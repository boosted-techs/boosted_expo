<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffBreak extends Model
{
    protected $fillable = ['business_id', 'staff_attendance_id', 'starts_at', 'ends_at', 'recorded_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
