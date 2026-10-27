<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffAttendanceCorrection extends Model
{
    protected $fillable = ['business_id', 'staff_attendance_id', 'proposed_in_at', 'proposed_out_at', 'reason', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected function casts(): array
    {
        return ['proposed_in_at' => 'datetime', 'proposed_out_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }
}
