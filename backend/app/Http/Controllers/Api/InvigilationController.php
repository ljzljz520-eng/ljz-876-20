<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamAnomaly;
use App\Models\ExamRecord;
use App\Models\ExamSeatAssignment;
use App\Models\ExamSeatChangeLog;
use App\Models\ExamSession;
use App\Models\InvigilationLog;
use App\Support\SeatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InvigilationController extends Controller
{
    protected function denyUnlessStaff(Request $request)
    {
        if (!in_array($request->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限，仅监考教师可操作'], 403);
        }

        return null;
    }

    /**
     * 巡考可选场次（未结束的场次 + 最近结束的场次）
     */
    public function sessionsIndex(Request $request)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $sessions = ExamSession::with(['examPaper:id,title', 'room:id,name,building'])
            ->whereIn('status', [ExamSession::STATUS_SCHEDULED, ExamSession::STATUS_ONGOING, ExamSession::STATUS_FINISHED])
            ->orderByRaw("FIELD(status, 'ongoing', 'scheduled', 'finished')")
            ->orderByDesc('start_time')
            ->limit(50)
            ->get();

        return response()->json(['exam_sessions' => $sessions]);
    }

    /**
     * 扫码 / 手输座位码查询：学生身份 + 考试进度 + 异常记录
     */
    public function seatLookup(Request $request)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $token = SeatService::parseToken($request->input('code'));
        if (!$token) {
            return response()->json(['message' => '二维码格式不正确，无法识别座位'], 422);
        }

        $seat = ExamSeatAssignment::with(['session.examPaper', 'room', 'user'])
            ->where('seat_token', $token)
            ->first();

        if (!$seat) {
            return response()->json(['message' => '未找到该座位对应的绑定信息'], 404);
        }

        SeatService::log(
            $seat->exam_session_id,
            $request->user()->id,
            InvigilationLog::ACTION_SEAT_SCAN,
            "扫码查看座位 {$seat->seat_no}（电脑 {$seat->current_computer_no}）",
            $seat->seat_no,
            $seat->user_id
        );

        return response()->json([
            'seat' => $this->formatSeatDetail($seat),
        ]);
    }

    /**
     * 手动按场次 + 座位号查询（无摄像头时兜底）
     */
    public function seatShow(Request $request, ExamSession $examSession, string $seatNo)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $seat = ExamSeatAssignment::with(['session.examPaper', 'room', 'user'])
            ->where('exam_session_id', $examSession->id)
            ->where('seat_no', $seatNo)
            ->firstOrFail();

        return response()->json(['seat' => $this->formatSeatDetail($seat)]);
    }

    /**
     * 巡考上报异常
     */
    public function reportAnomaly(Request $request, ExamSeatAssignment $seat)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|max:50',
            'detail' => 'required|string|max:1000',
            'severity' => 'nullable|in:info,warning,critical',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = $this->findActiveRecord($seat);

        $anomaly = SeatService::anomaly(
            $seat->exam_session_id,
            $seat->user_id,
            $request->input('type', ExamAnomaly::TYPE_MANUAL),
            $request->input('detail'),
            $request->input('severity', ExamAnomaly::SEVERITY_WARNING),
            $seat->id,
            $record?->id
        );

        SeatService::log(
            $seat->exam_session_id,
            $request->user()->id,
            InvigilationLog::ACTION_ANOMALY_REPORT,
            '上报异常：' . $request->input('detail'),
            $seat->seat_no,
            $seat->user_id
        );

        return response()->json(['message' => '异常已记录', 'anomaly' => $anomaly], 201);
    }

    /**
     * 处理异常
     */
    public function resolveAnomaly(Request $request, ExamAnomaly $anomaly)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'resolved_note' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $anomaly->update([
            'resolved' => true,
            'resolved_by' => $request->user()->id,
            'resolved_note' => $request->input('resolved_note'),
            'resolved_at' => now(),
        ]);

        SeatService::log(
            $anomaly->exam_session_id,
            $request->user()->id,
            InvigilationLog::ACTION_ANOMALY_RESOLVE,
            '处理异常：' . $request->input('resolved_note'),
            $anomaly->seatAssignment?->seat_no,
            $anomaly->user_id
        );

        return response()->json(['message' => '异常已处理', 'anomaly' => $anomaly]);
    }

    /**
     * 登记换座。
     * 入参：target_seat_no（同场次）、reason（必填）
     * - 目标座位无人：考生迁移到空座位
     * - 目标座位有人：双方对调
     * 同时写入换座登记表与监考日志。
     */
    public function changeSeat(Request $request, ExamSeatAssignment $seat)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        if (!$seat->user_id) {
            return response()->json(['message' => '该座位当前未安排考生，无需换座'], 422);
        }

        $validator = Validator::make($request->all(), [
            'target_seat_no' => 'required|string|max:20',
            'reason' => 'required|string|min:2|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $targetSeatNo = trim($request->input('target_seat_no'));
        $reason = trim($request->input('reason'));

        if (strcasecmp($targetSeatNo, $seat->seat_no) === 0) {
            return response()->json(['message' => '目标座位与当前座位相同，无需换座'], 422);
        }

        $target = ExamSeatAssignment::where('exam_session_id', $seat->exam_session_id)
            ->where('seat_no', $targetSeatNo)
            ->first();

        if (!$target) {
            return response()->json(['message' => "目标座位 {$targetSeatNo} 不存在，请先导入该座位"], 422);
        }

        $operator = $request->user();
        $studentA = $seat->user_id;
        $studentB = $target->user_id;

        DB::transaction(function () use ($seat, $target, $studentA, $studentB, $reason, $operator) {
            // 换座登记表
            ExamSeatChangeLog::create([
                'exam_session_id' => $seat->exam_session_id,
                'user_id' => $studentA,
                'from_seat_no' => $seat->seat_no,
                'from_computer_no' => $seat->current_computer_no,
                'to_seat_no' => $target->seat_no,
                'to_computer_no' => $target->computer_no,
                'reason' => $reason,
                'operator_id' => $operator->id,
            ]);

            if ($studentB) {
                ExamSeatChangeLog::create([
                    'exam_session_id' => $seat->exam_session_id,
                    'user_id' => $studentB,
                    'from_seat_no' => $target->seat_no,
                    'from_computer_no' => $target->current_computer_no,
                    'to_seat_no' => $seat->seat_no,
                    'to_computer_no' => $seat->computer_no,
                    'reason' => '与 ' . $seat->seat_no . ' 对调：' . $reason,
                    'operator_id' => $operator->id,
                ]);
            }

            // 交换/迁移考生，并把机器绑定重置到物理电脑；签到状态需在新机器重新签到确认
            $seat->update([
                'user_id' => $studentB,
                'current_computer_no' => $seat->computer_no,
                'checkin_time' => null,
                'checkin_ip' => null,
                'checkin_ua_hash' => null,
                'answered_count' => 0,
                'last_progress_at' => null,
            ]);

            $target->update([
                'user_id' => $studentA,
                'current_computer_no' => $target->computer_no,
                'checkin_time' => null,
                'checkin_ip' => null,
                'checkin_ua_hash' => null,
                'answered_count' => 0,
                'last_progress_at' => null,
            ]);

            SeatService::log(
                $seat->exam_session_id,
                $operator->id,
                InvigilationLog::ACTION_SEAT_CHANGE,
                "考生由座位 {$seat->seat_no} 调整至 {$target->seat_no}，原因：{$reason}",
                $target->seat_no,
                $studentA
            );

            if ($studentB) {
                SeatService::log(
                    $seat->exam_session_id,
                    $operator->id,
                    InvigilationLog::ACTION_SEAT_CHANGE,
                    "考生由座位 {$target->seat_no} 对调至 {$seat->seat_no}，原因：{$reason}",
                    $seat->seat_no,
                    $studentB
                );
            }
        });

        return response()->json([
            'message' => $studentB
                ? "换座成功，{$seat->seat_no} 与 {$target->seat_no} 已对调，两位考生需在新机器重新签到"
                : "换座成功，考生已调整至 {$target->seat_no}，需在新机器重新签到",
        ]);
    }

    /**
     * 场次监考日志
     */
    public function logsIndex(Request $request, ExamSession $examSession)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $logs = $examSession->invigilationLogs()
            ->with('operator:id,username,real_name')
            ->orderByDesc('id')
            ->paginate($request->input('per_page', 30));

        return response()->json(['invigilation_logs' => $logs]);
    }

    /**
     * 场次异常列表
     */
    public function anomaliesIndex(Request $request, ExamSession $examSession)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $anomalies = $examSession->anomalies()
            ->with(['user:id,username,real_name,email', 'resolver:id,username,real_name', 'seatAssignment:id,seat_no,current_computer_no'])
            ->orderByRaw('resolved ASC, id DESC')
            ->paginate($request->input('per_page', 30));

        return response()->json(['exam_anomalies' => $anomalies]);
    }

    /* ---------------- 学生端 ---------------- */

    /**
     * 学生查询本人的线下场次座位安排
     */
    public function mySeat(Request $request)
    {
        $assignments = ExamSeatAssignment::with(['session.examPaper:id,title,total_time,total_score', 'room'])
            ->where('user_id', $request->user()->id)
            ->whereHas('session', fn ($q) => $q->where('status', '!=', ExamSession::STATUS_CANCELLED))
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($a) => $this->formatStudentSeat($a));

        return response()->json(['my_seats' => $assignments]);
    }

    /**
     * 学生到指定机器签到
     */
    public function checkin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exam_session_id' => 'nullable|exists:exam_sessions,id',
            'computer_no' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $computerNo = trim($request->input('computer_no'));

        $query = ExamSeatAssignment::with(['session.examPaper', 'room'])
            ->where('user_id', $request->user()->id);

        if ($request->filled('exam_session_id')) {
            $query->where('exam_session_id', $request->input('exam_session_id'));
        } else {
            $query->whereHas('session', fn ($q) => $q->where('status', ExamSession::STATUS_ONGOING));
        }

        $seat = $query->first();

        if (!$seat) {
            return response()->json(['message' => '没有找到你在该场次的座位安排，请联系监考老师'], 404);
        }

        $session = $seat->session;

        if ($session->status === ExamSession::STATUS_FINISHED || $session->status === ExamSession::STATUS_CANCELLED) {
            return response()->json(['message' => '该场次已结束或已取消，无法签到'], 422);
        }

        if (!$session->isCheckinOpen()) {
            return response()->json([
                'message' => '当前不在签到时间内（开考前 30 分钟开放签到）',
            ], 422);
        }

        // 电脑编号必须与座位绑定一致 —— 核心机器校验
        if (strcasecmp($computerNo, $seat->current_computer_no) !== 0 && strcasecmp($computerNo, $seat->computer_no) !== 0) {
            SeatService::anomaly(
                $session->id,
                $request->user()->id,
                ExamAnomaly::TYPE_MISMATCH_COMPUTER,
                "尝试在电脑 {$computerNo} 签到，但座位 {$seat->seat_no} 绑定电脑为 {$seat->current_computer_no}",
                ExamAnomaly::SEVERITY_CRITICAL,
                $seat->id
            );

            return response()->json([
                'message' => "本机编号（{$computerNo}）与座位 {$seat->seat_no} 绑定的电脑（{$seat->current_computer_no}）不一致，已记录异常，请联系监考老师",
            ], 403);
        }

        // IP 网段校验（可按场次开关）
        $ip = $request->ip();
        if ($session->check_ip && !SeatService::ipInRange($ip, $seat->room->ip_range)) {
            SeatService::anomaly(
                $session->id,
                $request->user()->id,
                ExamAnomaly::TYPE_MISMATCH_IP,
                "签到 IP {$ip} 不在机房网段（{$seat->room->ip_range}）",
                ExamAnomaly::SEVERITY_CRITICAL,
                $seat->id
            );

            return response()->json([
                'message' => '当前网络不在指定机房网段内，无法签到，请使用机房内电脑',
            ], 403);
        }

        $alreadyCheckedIn = $seat->isCheckedIn();

        $seat->update([
            'checkin_time' => $seat->checkin_time ?? now(),
            'checkin_ip' => $seat->checkin_ip ?? $ip,
            'checkin_ua_hash' => $seat->checkin_ua_hash ?? SeatService::uaFingerprint(
                $request->userAgent(),
                $request->header('Accept-Language')
            ),
        ]);

        if (!$alreadyCheckedIn) {
            SeatService::log(
                $session->id,
                $request->user()->id,
                InvigilationLog::ACTION_CHECKIN,
                "学生在座位 {$seat->seat_no}、电脑 {$seat->current_computer_no} 完成签到（IP {$ip}）",
                $seat->seat_no,
                $request->user()->id
            );
        }

        return response()->json([
            'message' => $alreadyCheckedIn ? '你已签到，无需重复签到' : '签到成功，请开始考试',
            'seat' => $this->formatStudentSeat($seat->fresh()),
        ]);
    }

    /**
     * 学生答题进度心跳
     */
    public function progress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'answered_count' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->input('exam_record_id'))
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($record->exam_session_id) {
            ExamSeatAssignment::where('exam_session_id', $record->exam_session_id)
                ->where('user_id', $request->user()->id)
                ->update([
                    'answered_count' => $request->input('answered_count'),
                    'last_progress_at' => now(),
                ]);
        }

        return response()->json(['message' => 'ok']);
    }

    /* ---------------- 组装输出 ---------------- */

    protected function findActiveRecord(ExamSeatAssignment $seat): ?ExamRecord
    {
        if (!$seat->user_id) {
            return null;
        }

        return ExamRecord::where('user_id', $seat->user_id)
            ->where('exam_paper_id', $seat->session->exam_paper_id)
            ->where('status', ExamRecord::STATUS_IN_PROGRESS)
            ->latest('id')
            ->first();
    }

    protected function formatSeatDetail(ExamSeatAssignment $seat): array
    {
        $record = $this->findActiveRecord($seat);
        $session = $seat->session;
        $paper = $session->examPaper;

        $progress = null;
        if ($record) {
            $deadline = $record->start_time->copy()->addMinutes($paper->total_time);
            $progress = [
                'exam_record_id' => $record->id,
                'status' => $record->status,
                'status_label' => ExamRecord::STATUSES[$record->status] ?? $record->status,
                'start_time' => $record->start_time,
                'deadline' => $deadline,
                'seconds_remaining' => max(0, now()->diffInSeconds($deadline, false)),
                'question_count' => $paper->question_count,
                'answered_count' => (int) $seat->answered_count,
                'score' => $record->score,
            ];
        }

        $anomalies = ExamAnomaly::where('exam_seat_assignment_id', $seat->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'type_label' => ExamAnomaly::TYPES[$a->type] ?? $a->type,
                'detail' => $a->detail,
                'severity' => $a->severity,
                'resolved' => (bool) $a->resolved,
                'resolved_note' => $a->resolved_note,
                'created_at' => $a->created_at,
            ]);

        return [
            'id' => $seat->id,
            'seat_no' => $seat->seat_no,
            'computer_no' => $seat->computer_no,
            'current_computer_no' => $seat->current_computer_no,
            'seat_token' => $seat->seat_token,
            'checked_in' => $seat->isCheckedIn(),
            'checkin_time' => $seat->checkin_time,
            'checkin_ip' => $seat->checkin_ip,
            'last_progress_at' => $seat->last_progress_at,
            'answered_count' => (int) $seat->answered_count,
            'student' => $seat->user ? [
                'id' => $seat->user->id,
                'username' => $seat->user->username,
                'real_name' => $seat->user->real_name,
                'email' => $seat->user->email,
            ] : null,
            'session' => [
                'id' => $session->id,
                'name' => $session->name,
                'status' => $session->status,
                'status_label' => ExamSession::STATUSES[$session->status] ?? $session->status,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'exam_paper' => [
                    'id' => $paper->id,
                    'title' => $paper->title,
                    'total_time' => $paper->total_time,
                    'question_count' => $paper->question_count,
                ],
            ],
            'room' => [
                'id' => $seat->room->id,
                'name' => $seat->room->name,
                'building' => $seat->room->building,
            ],
            'progress' => $progress,
            'anomalies' => $anomalies,
        ];
    }

    protected function formatStudentSeat(ExamSeatAssignment $seat): array
    {
        $session = $seat->session;

        return [
            'assignment_id' => $seat->id,
            'seat_no' => $seat->seat_no,
            'computer_no' => $seat->computer_no,
            'current_computer_no' => $seat->current_computer_no,
            'checked_in' => $seat->isCheckedIn(),
            'checkin_time' => $seat->checkin_time,
            'checkin_open' => $session->isCheckinOpen(),
            'session' => [
                'id' => $session->id,
                'name' => $session->name,
                'status' => $session->status,
                'status_label' => ExamSession::STATUSES[$session->status] ?? $session->status,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'exam_paper' => $session->examPaper ? [
                    'id' => $session->examPaper->id,
                    'title' => $session->examPaper->title,
                    'total_time' => $session->examPaper->total_time,
                    'total_score' => $session->examPaper->total_score,
                ] : null,
            ],
            'room' => $seat->room ? [
                'id' => $seat->room->id,
                'name' => $seat->room->name,
                'building' => $seat->room->building,
            ] : null,
        ];
    }
}
