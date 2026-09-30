<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'building',
        'ip_range',
        'seat_count',
        'status',
        'created_by',
    ];

    protected $casts = [
        'seat_count' => 'integer',
        'status' => 'boolean',
        'created_by' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sessions()
    {
        return $this->hasMany(ExamSession::class, 'exam_room_id');
    }

    public function seatAssignments()
    {
        return $this->hasMany(ExamSeatAssignment::class, 'exam_room_id');
    }
}
