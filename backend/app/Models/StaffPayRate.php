<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPayRate extends Model
{
    protected $fillable = ['business_id', 'staff_person_id', 'basis', 'regular_daily_hours', 'overtime_multiplier', 'output_unit', 'work_code', 'unpaid_day_divisor', 'overtime_hourly_amount', 'superseded_on', 'supersedes_rate_id', 'amount', 'currency', 'starts_on', 'ends_on', 'status', 'agreement_reference', 'created_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'approved_at' => 'datetime', 'superseded_on' => 'date'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }

    public function scopeEffectiveOn(Builder $q, string $date): void
    {
        $q->where('status', 'approved')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->where(fn ($q) => $q->whereNull('superseded_on')->orWhereDate('superseded_on', '>', $date));
    }
}
