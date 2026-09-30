<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamRoom;
use App\Models\ExamSeatAssignment;
use App\Models\ExamSession;
use App\Models\User;
use App\Support\SeatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExamRoomController extends Controller
{
    protected function denyUnlessStaff(Request $request)
    {
        if (!in_array($request->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限，仅教务/教师可操作'], 403);
        }

        return null;
    }

    /* ---------------- 机房 ---------------- */

    public function roomsIndex(Request $request)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $rooms = ExamRoom::withCount('sessions')
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->keyword . '%')
                    ->orWhere('building', 'like', '%' . $request->keyword . '%');
            })
            ->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json(['exam_rooms' => $rooms]);
    }

    public function roomsStore(Request $request)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:exam_rooms,name',
            'building' => 'nullable|string|max:100',
            'ip_range' => ['nullable', 'string', 'max:50', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
            'seat_count' => 'nullable|integer|min:0|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $room = ExamRoom::create([
            'name' => $request->name,
            'building' => $request->building,
            'ip_range' => $request->ip_range,
            'seat_count' => $request->seat_count ?? 0,
            'status' => 1,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => '机房创建成功', 'exam_room' => $room], 201);
    }

    public function roomsUpdate(Request $request, ExamRoom $examRoom)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:exam_rooms,name,' . $examRoom->id,
            'building' => 'nullable|string|max:100',
            'ip_range' => ['nullable', 'string', 'max:50', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
            'seat_count' => 'nullable|integer|min:0|max:1000',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $examRoom->update(array_filter([
            'name' => $request->name,
            'building' => $request->building,
            'ip_range' => $request->ip_range,
            'seat_count' => $request->seat_count ?? $examRoom->seat_count,
            'status' => $request->status ?? $examRoom->status,
        ], fn ($v) => $v !== null));

        return response()->json(['message' => '更新成功', 'exam_room' => $examRoom]);
    }

    public function roomsDestroy(Request $request, ExamRoom $examRoom)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        if ($examRoom->sessions()->exists()) {
            return response()->json(['message' => '该机房下存在考试场次，无法删除'], 422);
        }

        $examRoom->delete();

        return response()->json(['message' => '删除成功']);
    }

    /* ---------------- 场次 ---------------- */

    public function sessionsIndex(Request $request)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $sessions = ExamSession::with(['examPaper:id,title', 'room:id,name,building'])
            ->withCount('seatAssignments as seats_total')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('exam_paper_id'), fn ($q) => $q->where('exam_paper_id', $request->exam_paper_id))
            ->orderByDesc('start_time')
            ->paginate($request->input('per_page', 15));

        // 签到数需要带条件统计，单独补充
        $sessions->getCollection()->transform(function ($s) {
            $s->seats_assigned = ExamSeatAssignment::where('exam_session_id', $s->id)->whereNotNull('user_id')->count();
            $s->seats_checked = ExamSeatAssignment::where('exam_session_id', $s->id)->whereNotNull('checkin_time')->count();
            return $s;
        });

        return response()->json(['exam_sessions' => $sessions]);
    }

    public function sessionsStore(Request $request)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'exam_paper_id' => 'required|exists:exam_papers,id',
            'exam_room_id' => 'required|exists:exam_rooms,id',
            'name' => 'required|string|max:200',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'check_ip' => 'nullable|boolean',
            'status' => 'nullable|in:' . implode(',', array_keys(ExamSession::STATUSES)),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $session = ExamSession::create([
            'exam_paper_id' => $request->exam_paper_id,
            'exam_room_id' => $request->exam_room_id,
            'name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'check_ip' => $request->boolean('check_ip'),
            'status' => $request->status ?? ExamSession::STATUS_SCHEDULED,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => '场次创建成功', 'exam_session' => $session], 201);
    }

    public function sessionsShow(Request $request, ExamSession $examSession)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $examSession->load(['examPaper:id,title,total_time,question_count', 'room', 'creator:id,username,real_name']);

        $stats = [
            'seats_total' => ExamSeatAssignment::where('exam_session_id', $examSession->id)->count(),
            'seats_assigned' => ExamSeatAssignment::where('exam_session_id', $examSession->id)->whereNotNull('user_id')->count(),
            'seats_checked' => ExamSeatAssignment::where('exam_session_id', $examSession->id)->whereNotNull('checkin_time')->count(),
        ];

        return response()->json(['exam_session' => $examSession, 'stats' => $stats]);
    }

    public function sessionsUpdate(Request $request, ExamSession $examSession)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'exam_paper_id' => 'sometimes|required|exists:exam_papers,id',
            'exam_room_id' => 'sometimes|required|exists:exam_rooms,id',
            'name' => 'sometimes|required|string|max:200',
            'start_time' => 'sometimes|required|date',
            'end_time' => 'sometimes|required|date|after:start_time',
            'check_ip' => 'nullable|boolean',
            'status' => 'nullable|in:' . implode(',', array_keys(ExamSession::STATUSES)),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $examSession->update($request->only([
            'exam_paper_id', 'exam_room_id', 'name', 'start_time', 'end_time', 'check_ip', 'status',
        ]));

        return response()->json(['message' => '更新成功', 'exam_session' => $examSession]);
    }

    public function sessionsDestroy(Request $request, ExamSession $examSession)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        DB::transaction(function () use ($examSession) {
            ExamSeatAssignment::where('exam_session_id', $examSession->id)->delete();
            $examSession->seatChangeLogs()->delete();
            $examSession->anomalies()->delete();
            $examSession->invigilationLogs()->delete();
            $examSession->delete();
        });

        return response()->json(['message' => '删除成功']);
    }

    /* ---------------- 座位 ---------------- */

    public function seatsIndex(Request $request, ExamSession $examSession)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $seats = $examSession->seatAssignments()
            ->with('user:id,username,real_name,email')
            ->orderByRaw('LENGTH(seat_no), seat_no')
            ->get();

        return response()->json(['seats' => $seats]);
    }

    /**
     * 导入座位。支持两种入参：
     * 1) rows: [['seat_no'=>'A01','computer_no'=>'PC-01','student'=>'student1@example.com'], ...]
     * 2) content: 粘贴的 CSV/TSV 文本，表头支持 座位号/电脑编号/考生(学号或邮箱，可空)
     * mode: merge(默认，按座位号 upsert) | replace(清空后重建)
     */
    public function seatsImport(Request $request, ExamSession $examSession)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'rows' => 'nullable|array',
            'rows.*.seat_no' => 'required_with:rows|string|max:20',
            'rows.*.computer_no' => 'required_with:rows|string|max:50',
            'rows.*.student' => 'nullable|string|max:100',
            'content' => 'nullable|string',
            'mode' => 'nullable|in:merge,replace',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $mode = $request->input('mode', 'merge');
        $rows = $request->input('rows');

        if (!$rows && $request->filled('content')) {
            $rows = $this->parseCsvContent($request->input('content'));
        }

        if (!$rows) {
            return response()->json(['message' => '未解析到任何座位数据'], 422);
        }

        // 标准化 + 文件内查重
        $normalized = [];
        $seenSeat = [];
        $seenComputer = [];
        $errors = [];

        foreach ($rows as $i => $row) {
            $line = $i + 1;
            $seatNo = trim($row['seat_no'] ?? '');
            $computerNo = trim($row['computer_no'] ?? '');
            $studentKey = trim($row['student'] ?? '');

            if ($seatNo === '' || $computerNo === '') {
                $errors[] = "第 {$line} 行：座位号和电脑编号不能为空";
                continue;
            }
            if (isset($seenSeat[$seatNo])) {
                $errors[] = "第 {$line} 行：座位号 {$seatNo} 在文件内重复";
                continue;
            }
            if (isset($seenComputer[$computerNo])) {
                $errors[] = "第 {$line} 行：电脑编号 {$computerNo} 在文件内重复";
                continue;
            }

            $seenSeat[$seatNo] = true;
            $seenComputer[$computerNo] = true;

            $userId = null;
            if ($studentKey !== '') {
                $user = User::where('role', 'student')
                    ->where(fn ($q) => $q->where('email', $studentKey)->orWhere('username', $studentKey))
                    ->first();
                if (!$user) {
                    $errors[] = "第 {$line} 行：未找到考生 {$studentKey}";
                    continue;
                }
                $userId = $user->id;
            }

            $normalized[] = [
                'seat_no' => $seatNo,
                'computer_no' => $computerNo,
                'user_id' => $userId,
            ];
        }

        if ($errors) {
            return response()->json(['message' => '导入数据存在问题，请修正后重试', 'errors' => $errors], 422);
        }

        // 与库内现有数据的唯一性预检（merge 模式）
        $seatNos = array_column($normalized, 'seat_no');
        $computerNos = array_column($normalized, 'computer_no');
        $userIds = array_filter(array_column($normalized, 'user_id'));

        $dbErrors = [];
        if ($mode === 'merge') {
            $computerConflict = ExamSeatAssignment::where('exam_session_id', $examSession->id)
                ->whereIn('computer_no', $computerNos)
                ->whereNotIn('seat_no', $seatNos)
                ->pluck('computer_no')
                ->all();
            foreach ($computerConflict as $c) {
                $dbErrors[] = "电脑编号 {$c} 已被其他座位占用";
            }

            if ($userIds) {
                $userConflict = ExamSeatAssignment::where('exam_session_id', $examSession->id)
                    ->whereIn('user_id', $userIds)
                    ->whereNotIn('seat_no', $seatNos)
                    ->with('user:id,username')
                    ->get();
                foreach ($userConflict as $c) {
                    $name = $c->user?->username ?? ('用户#' . $c->user_id);
                    $dbErrors[] = "考生 {$name} 已分配在座位 {$c->seat_no}，不能重复分配";
                }
            }
        }

        if ($dbErrors) {
            return response()->json(['message' => '导入数据与现有座位冲突，请修正后重试', 'errors' => $dbErrors], 422);
        }

        $imported = 0;
        $updated = 0;

        DB::transaction(function () use ($examSession, $normalized, $mode, &$imported, &$updated) {
            if ($mode === 'replace') {
                ExamSeatAssignment::where('exam_session_id', $examSession->id)->delete();
            }

            foreach ($normalized as $item) {
                $existing = ExamSeatAssignment::where('exam_session_id', $examSession->id)
                    ->where('seat_no', $item['seat_no'])
                    ->first();

                if ($existing) {
                    $existing->update([
                        'computer_no' => $item['computer_no'],
                        'user_id' => $item['user_id'],
                        'current_computer_no' => $existing->checkin_time ? $existing->current_computer_no : $item['computer_no'],
                    ]);
                    $updated++;
                    continue;
                }

                ExamSeatAssignment::create([
                    'exam_session_id' => $examSession->id,
                    'exam_room_id' => $examSession->exam_room_id,
                    'seat_no' => $item['seat_no'],
                    'computer_no' => $item['computer_no'],
                    'current_computer_no' => $item['computer_no'],
                    'user_id' => $item['user_id'],
                    'seat_token' => SeatService::makeToken($examSession->id),
                ]);
                $imported++;
            }
        });

        return response()->json([
            'message' => "导入完成：新增 {$imported} 个座位，更新 {$updated} 个座位",
            'imported' => $imported,
            'updated' => $updated,
        ]);
    }

    public function seatsDestroy(Request $request, ExamSession $examSession, ExamSeatAssignment $seat)
    {
        if ($denied = $this->denyUnlessStaff($request)) {
            return $denied;
        }

        if ($seat->exam_session_id !== $examSession->id) {
            return response()->json(['message' => '座位不属于该场次'], 422);
        }

        if ($seat->checkin_time) {
            return response()->json(['message' => '该座位已有学生签到，不可删除'], 422);
        }

        $seat->delete();

        return response()->json(['message' => '座位已删除']);
    }

    /**
     * 解析粘贴的 CSV/TSV 文本，自动识别中英文表头
     */
    protected function parseCsvContent(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        $lines = array_values(array_filter(explode("\n", $content), fn ($l) => trim($l) !== ''));
        if (!$lines) {
            return [];
        }

        $split = function (string $line): array {
            // 优先制表符，否则逗号；同时去掉引号
            if (str_contains($line, "\t")) {
                $cells = explode("\t", $line);
            } else {
                $cells = str_getcsv($line);
            }
            return array_map(fn ($c) => trim($c, " \t\n\r\0\x0B\""), $cells);
        };

        $header = $split($lines[0]);
        $hasHeader = (bool) preg_match('/座位|seat/i', $header[0] ?? '');

        $map = [
            'seat' => 0,
            'computer' => 1,
            'student' => 2,
        ];

        if ($hasHeader) {
            foreach ($header as $idx => $name) {
                if (preg_match('/座位|seat/i', $name)) {
                    $map['seat'] = $idx;
                } elseif (preg_match('/电脑|计算机|machine|computer|pc/i', $name)) {
                    $map['computer'] = $idx;
                } elseif (preg_match('/考生|学生|学号|邮箱|student|email|user/i', $name)) {
                    $map['student'] = $idx;
                }
            }
            $lines = array_slice($lines, 1);
        }

        $rows = [];
        foreach ($lines as $line) {
            $cells = $split($line);
            $rows[] = [
                'seat_no' => $cells[$map['seat']] ?? '',
                'computer_no' => $cells[$map['computer']] ?? '',
                'student' => $cells[$map['student']] ?? '',
            ];
        }

        return $rows;
    }
}
