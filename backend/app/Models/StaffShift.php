<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffShift extends Model
{
    protected $fillable = ['pay_treatment', 'pay_reviewed_by', 'pay_reviewed_at', 'pay_review_note', 'unpaid_day_units', 'staff_person_id', 'staff_rota_id', 'required_skill', 'published_at',
        'business_id', 'user_id', 'starts_at', 'ends_at', 'location', 'notes',
        'status', 'created_by', 'cancelled_by', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return ['pay_reviewed_at' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(StaffAttendance::class, 'staff_shift_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
