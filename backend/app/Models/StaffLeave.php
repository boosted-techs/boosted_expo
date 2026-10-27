<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffLeave extends Model
{
    protected $fillable = ['pay_treatment', 'pay_reviewed_by', 'pay_reviewed_at', 'pay_review_note', 'unpaid_day_units', 'business_id', 'staff_person_id', 'starts_at', 'ends_at', 'reason', 'status', 'recorded_by'];

    protected function casts(): array
    {
        return ['pay_reviewed_at' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
