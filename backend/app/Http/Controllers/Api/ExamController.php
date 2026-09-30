<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\ExamSeatAssignment;
use App\Models\ExamSession;
use App\Models\ExamAnomaly;
use App\Models\Question;
use App\Support\SeatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $examPapers = ExamPaper::with('creator')
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'exam_papers' => $examPapers,
        ]);
    }

    public function start(Request $request, ExamPaper $examPaper)
    {
        $existingRecord = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->first();

        if ($existingRecord) {
            return response()->json([
                'message' => '您已经开始这场考试',
                'exam_record' => $existingRecord,
            ]);
        }

        // 线下机房场次：必须在绑定座位的指定电脑签到后才能开考
        $seatContext = $this->resolveSeatContext($request, $examPaper);
        if ($seatContext['error']) {
            return $seatContext['error'];
        }

        $record = ExamRecord::create([
            'user_id' => $request->user()->id,
            'exam_paper_id' => $examPaper->id,
            'exam_session_id' => $seatContext['session']?->id,
            'seat_no' => $seatContext['seat']?->seat_no,
            'computer_no' => $seatContext['seat']?->current_computer_no,
            'client_ip' => $request->ip(),
            'start_time' => now(),
            'status' => 'in_progress',
        ]);

        $questions = $examPaper->questions()->get();

        $questionsData = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });

        return response()->json([
            'message' => '考试开始',
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
            ],
            'questions' => $questionsData,
        ]);
    }

    public function getQuestions(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        // 线下场次：每一次取题都校验机器，防止换机答题
        if ($record->exam_session_id) {
            $violation = $this->verifyMachineBinding($request, $record);
            if ($violation) {
                return $violation;
            }
        }

        $questions = $examPaper->questions()->get();

        $questionsData = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });

        return response()->json([
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
            ],
            'questions' => $questionsData,
        ]);
    }

    public function submit(Request $request, ExamPaper $examPaper)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        // 线下场次：交卷时再次校验机器
        if ($record->exam_session_id) {
            $violation = $this->verifyMachineBinding($request, $record);
            if ($violation) {
                return $violation;
            }
        }

        $totalScore = 0;
        $questionMap = $examPaper->questions->keyBy('id');

        foreach ($request->answers as $answerData) {
            $question = $questionMap->get($answerData['question_id']);
            if (!$question) {
                continue;
            }

            $isCorrect = $this->checkAnswer($question, $answerData['answer']);
            $score = $isCorrect ? $question->pivot->score : 0;

            ExamRecordAnswer::create([
                'exam_record_id' => $record->id,
                'question_id' => $answerData['question_id'],
                'answer' => $answerData['answer'],
                'is_correct' => $isCorrect,
                'score' => $score,
            ]);

            $totalScore += $score;
        }

        $record->update([
            'end_time' => now(),
            'score' => $totalScore,
            'status' => 'graded',
        ]);

        return response()->json([
            'message' => '提交成功',
            'score' => $totalScore,
            'exam_record' => $record->load('answers'),
        ]);
    }

    public function myRecords(Request $request)
    {
        $records = ExamRecord::with('examPaper')
            ->where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'records' => $records,
        ]);
    }

    public function showRecord(Request $request, ExamRecord $record)
    {
        if ($record->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        $record->load(['examPaper.questions', 'answers.question']);

        return response()->json([
            'record' => $record,
        ]);
    }

    /**
     * 解析当前试卷的线下场次座位上下文。
     * 规则：
     * - 试卷没有进行中的线下场次 => 不限制（兼容纯在线考试）
     * - 试卷有该考生的座位绑定 => 必须已签到，且 computer_no/IP 匹配
     * - 试卷有进行中场次但该考生无座位 => 拒绝（线下场次不允许裸考）
     *
     * @return array{session: ?ExamSession, seat: ?ExamSeatAssignment, error: ?\Illuminate\Http\JsonResponse}
     */
    protected function resolveSeatContext(Request $request, ExamPaper $examPaper): array
    {
        $now = now();

        // 机位绑定仅对考生生效；教师/管理员组卷测试不受线下场次限制
        if ($request->user()->role !== 'student') {
            return ['session' => null, 'seat' => null, 'error' => null];
        }

        $session = ExamSession::where('exam_paper_id', $examPaper->id)
            ->whereIn('status', [ExamSession::STATUS_SCHEDULED, ExamSession::STATUS_ONGOING])
            ->where('start_time', '<=', $now)
            ->where('end_time', '>=', $now)
            ->orderBy('id')
            ->first();

        if (!$session) {
            return ['session' => null, 'seat' => null, 'error' => null];
        }

        $seat = ExamSeatAssignment::where('exam_session_id', $session->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$seat) {
            SeatService::anomaly(
                $session->id,
                $request->user()->id,
                ExamAnomaly::TYPE_NO_CHECKIN,
                '学生在无机位安排的情况下尝试开考',
                ExamAnomaly::SEVERITY_CRITICAL
            );

            return [
                'session' => $session,
                'seat' => null,
                'error' => response()->json([
                    'message' => '本场考试为线下机房考试，你没有本场次的座位安排，请联系监考老师',
                ], 403),
            ];
        }

        if (!$seat->isCheckedIn()) {
            SeatService::anomaly(
                $session->id,
                $request->user()->id,
                ExamAnomaly::TYPE_NO_CHECKIN,
                "座位 {$seat->seat_no} 未签到即尝试开考",
                ExamAnomaly::SEVERITY_WARNING,
                $seat->id
            );

            return [
                'session' => $session,
                'seat' => $seat,
                'error' => response()->json([
                    'message' => '请先在指定电脑（' . $seat->current_computer_no . '）完成签到后再开考',
                    'need_checkin' => true,
                    'seat_no' => $seat->seat_no,
                    'computer_no' => $seat->current_computer_no,
                ], 403),
            ];
        }

        $computerNo = $request->header('X-Computer-No') ?? $request->input('computer_no');
        if (!$computerNo) {
            return [
                'session' => $session,
                'seat' => $seat,
                'error' => response()->json([
                    'message' => '缺少本机编号信息，请通过签到后的考试入口进入',
                    'need_checkin' => true,
                ], 403),
            ];
        }

        if (strcasecmp($computerNo, $seat->current_computer_no) !== 0
            && strcasecmp($computerNo, $seat->computer_no) !== 0) {
            SeatService::anomaly(
                $session->id,
                $request->user()->id,
                ExamAnomaly::TYPE_MISMATCH_COMPUTER,
                "尝试在电脑 {$computerNo} 开考，但绑定电脑为 {$seat->current_computer_no}（座位 {$seat->seat_no}）",
                ExamAnomaly::SEVERITY_CRITICAL,
                $seat->id
            );

            return [
                'session' => $session,
                'seat' => $seat,
                'error' => response()->json([
                    'message' => "本机编号（{$computerNo}）与绑定电脑（{$seat->current_computer_no}）不一致，已记录异常并禁止开考",
                ], 403),
            ];
        }

        if ($session->check_ip && !SeatService::ipInRange($request->ip(), $session->room->ip_range)) {
            // IP 不在机房网段：硬拦截（防止从校外直接开考）
            SeatService::anomaly(
                $session->id,
                $request->user()->id,
                ExamAnomaly::TYPE_MISMATCH_IP,
                "开考 IP {$request->ip()} 不在机房网段（{$session->room->ip_range}）",
                ExamAnomaly::SEVERITY_CRITICAL,
                $seat->id
            );

            return [
                'session' => $session,
                'seat' => $seat,
                'error' => response()->json([
                    'message' => '当前网络不在指定机房网段内，请使用机房电脑开考',
                ], 403),
            ];
        }

        if ($seat->checkin_ip && $request->ip() !== $seat->checkin_ip) {
            // 与签到 IP 不同但仍在机房网段内（DHCP/NAT 漂移）：记录警告但不阻断
            SeatService::anomaly(
                $session->id,
                $request->user()->id,
                ExamAnomaly::TYPE_MISMATCH_IP,
                "开考 IP {$request->ip()} 与签到 IP {$seat->checkin_ip} 不一致",
                ExamAnomaly::SEVERITY_WARNING,
                $seat->id
            );
        }

        return ['session' => $session, 'seat' => $seat, 'error' => null];
    }

    /**
     * 考试进行中校验机器（取题 / 交卷）。
     */
    protected function verifyMachineBinding(Request $request, ExamRecord $record): ?\Illuminate\Http\JsonResponse
    {
        $seat = ExamSeatAssignment::where('exam_session_id', $record->exam_session_id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$seat || !$seat->isCheckedIn()) {
            SeatService::anomaly(
                $record->exam_session_id,
                $request->user()->id,
                ExamAnomaly::TYPE_NO_CHECKIN,
                '考试中检测到未签到状态（记录 #' . $record->id . '）',
                ExamAnomaly::SEVERITY_CRITICAL,
                $seat?->id,
                $record->id
            );

            return response()->json(['message' => '机位签到状态异常，请联系监考老师'], 403);
        }

        $computerNo = $request->header('X-Computer-No') ?? $request->input('computer_no');
        if (!$computerNo) {
            return response()->json([
                'message' => '缺少本机编号信息，请通过签到后的考试入口继续考试',
            ], 403);
        }

        if (strcasecmp($computerNo, $seat->current_computer_no) !== 0
            && strcasecmp($computerNo, $seat->computer_no) !== 0) {
            SeatService::anomaly(
                $record->exam_session_id,
                $request->user()->id,
                ExamAnomaly::TYPE_MISMATCH_COMPUTER,
                "考试中检测到换机：当前电脑 {$computerNo}，绑定电脑 {$seat->current_computer_no}",
                ExamAnomaly::SEVERITY_CRITICAL,
                $seat->id,
                $record->id
            );

            return response()->json([
                'message' => "检测到更换电脑（{$computerNo}），本场考试必须在绑定电脑 {$seat->current_computer_no} 上完成",
            ], 403);
        }

        return null;
    }

    protected function checkAnswer(Question $question, string $userAnswer): bool
    {
        $correctAnswer = $question->answer;

        switch ($question->type) {
            case 'single_choice':
            case 'true_false':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            case 'multiple_choice':
                $userAnswers = explode(',', strtoupper(trim($userAnswer)));
                $correctAnswers = explode(',', strtoupper(trim($correctAnswer)));
                sort($userAnswers);
                sort($correctAnswers);
                return $userAnswers === $correctAnswers;
            case 'fill_blank':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            default:
                return false;
        }
    }
}
