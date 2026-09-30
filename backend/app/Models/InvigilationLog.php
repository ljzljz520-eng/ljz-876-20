<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvigilationLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'exam_session_id',
        'operator_id',
        'action',
        'detail',
        'seat_no',
        'user_id',
    ];

    protected $casts = [
        'exam_session_id' => 'integer',
        'operator_id' => 'integer',
        'user_id' => 'integer',
    ];

    public const ACTION_CHECKIN = 'checkin';
    public const ACTION_SEAT_CHANGE = 'seat_change';
    public const ACTION_ANOMALY_REPORT = 'anomaly_report';
    public const ACTION_ANOMALY_RESOLVE = 'anomaly_resolve';
    public const ACTION_SEAT_SCAN = 'seat_scan';
    public const ACTION_ABSENT = 'mark_absent';

    public const ACTIONS = [
        self::ACTION_CHECKIN => '学生签到',
        self::ACTION_SEAT_CHANGE => '换座登记',
        self::ACTION_ANOMALY_REPORT => '异常上报',
        self::ACTION_ANOMALY_RESOLVE => '异常处理',
        self::ACTION_SEAT_SCAN => '扫码巡看',
        self::ACTION_ABSENT => '标记缺考',
    ];

    public function session()
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
