<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_paper_id',
        'exam_room_id',
        'name',
        'start_time',
        'end_time',
        'check_ip',
        'status',
        'created_by',
    ];

    protected $casts = [
        'exam_paper_id' => 'integer',
        'exam_room_id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'check_ip' => 'boolean',
        'created_by' => 'integer',
    ];

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED => '未开始',
        self::STATUS_ONGOING => '进行中',
        self::STATUS_FINISHED => '已结束',
        self::STATUS_CANCELLED => '已取消',
    ];

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function room()
    {
        return $this->belongsTo(ExamRoom::class, 'exam_room_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function seatAssignments()
    {
        return $this->hasMany(ExamSeatAssignment::class, 'exam_session_id');
    }

    public function seatChangeLogs()
    {
        return $this->hasMany(ExamSeatChangeLog::class, 'exam_session_id');
    }

    public function anomalies()
    {
        return $this->hasMany(ExamAnomaly::class, 'exam_session_id');
    }

    public function invigilationLogs()
    {
        return $this->hasMany(InvigilationLog::class, 'exam_session_id');
    }

    public function isCheckinOpen(): bool
    {
        $now = now();

        return $now >= $this->start_time->copy()->subMinutes(30) && $now <= $this->end_time;
    }
}
