<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffAttendance extends Model
{
    protected $fillable = ['staff_person_id', 'recorded_by', 'approved_at', 'approved_by', 'effective_in_at', 'effective_out_at', 'review_note',
        'business_id', 'user_id', 'staff_shift_id', 'clocked_in_at',
        'clocked_out_at', 'work_note',
    ];

    protected function casts(): array
    {
        return ['clocked_in_at' => 'datetime', 'clocked_out_at' => 'datetime', 'effective_in_at' => 'datetime', 'effective_out_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(StaffShift::class, 'staff_shift_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(StaffAttendanceEvent::class, 'attendance_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
