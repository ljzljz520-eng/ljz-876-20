<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamSeatChangeLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'exam_session_id',
        'user_id',
        'from_seat_no',
        'from_computer_no',
        'to_seat_no',
        'to_computer_no',
        'reason',
        'operator_id',
    ];

    protected $casts = [
        'exam_session_id' => 'integer',
        'user_id' => 'integer',
        'operator_id' => 'integer',
    ];

    public function session()
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
