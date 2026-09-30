<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamSeatAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_session_id',
        'exam_room_id',
        'seat_no',
        'computer_no',
        'user_id',
        'seat_token',
        'checkin_time',
        'checkin_ip',
        'checkin_ua_hash',
        'current_computer_no',
        'last_progress_at',
        'answered_count',
    ];

    protected $casts = [
        'exam_session_id' => 'integer',
        'exam_room_id' => 'integer',
        'user_id' => 'integer',
        'checkin_time' => 'datetime',
        'last_progress_at' => 'datetime',
        'answered_count' => 'integer',
    ];

    public function session()
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function room()
    {
        return $this->belongsTo(ExamRoom::class, 'exam_room_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isCheckedIn(): bool
    {
        return $this->checkin_time !== null;
    }
}
