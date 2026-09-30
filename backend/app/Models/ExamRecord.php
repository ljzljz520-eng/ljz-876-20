<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exam_paper_id',
        'exam_session_id',
        'seat_no',
        'computer_no',
        'client_ip',
        'start_time',
        'end_time',
        'score',
        'status',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'exam_paper_id' => 'integer',
        'exam_session_id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'score' => 'decimal:2',
        'status' => 'string',
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED = 'graded';

    public const STATUSES = [
        self::STATUS_IN_PROGRESS => '进行中',
        self::STATUS_SUBMITTED => '已提交',
        self::STATUS_GRADED => '已评分',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function answers()
    {
        return $this->hasMany(ExamRecordAnswer::class, 'exam_record_id');
    }

    public function session()
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function anomalies()
    {
        return $this->hasMany(ExamAnomaly::class, 'exam_record_id');
    }
}
