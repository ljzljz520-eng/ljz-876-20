<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamAnomaly extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_session_id',
        'exam_seat_assignment_id',
        'exam_record_id',
        'user_id',
        'type',
        'detail',
        'severity',
        'resolved',
        'resolved_by',
        'resolved_note',
        'resolved_at',
    ];

    protected $casts = [
        'exam_session_id' => 'integer',
        'exam_seat_assignment_id' => 'integer',
        'exam_record_id' => 'integer',
        'user_id' => 'integer',
        'resolved' => 'boolean',
        'resolved_by' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public const TYPE_MISMATCH_COMPUTER = 'computer_mismatch';
    public const TYPE_MISMATCH_IP = 'ip_mismatch';
    public const TYPE_NO_CHECKIN = 'no_checkin';
    public const TYPE_WRONG_STUDENT = 'wrong_student';
    public const TYPE_MANUAL = 'manual_report';
    public const TYPE_IDLE = 'idle';

    public const TYPES = [
        self::TYPE_MISMATCH_COMPUTER => '电脑不匹配',
        self::TYPE_MISMATCH_IP => 'IP不在机房网段',
        self::TYPE_NO_CHECKIN => '未签到开考',
        self::TYPE_WRONG_STUDENT => '签到人与座位绑定不一致',
        self::TYPE_MANUAL => '巡考上报',
        self::TYPE_IDLE => '长时间无作答',
    ];

    public function session()
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function seatAssignment()
    {
        return $this->belongsTo(ExamSeatAssignment::class, 'exam_seat_assignment_id');
    }

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
