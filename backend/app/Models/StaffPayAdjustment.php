<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPayAdjustment extends Model
{
    protected $fillable = ['business_id', 'staff_person_id', 'effective_on', 'kind', 'amount', 'taxable', 'nssf_eligible', 'external_reference', 'evidence', 'status', 'created_by', 'approved_by', 'approved_at', 'review_note'];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'taxable' => 'boolean', 'nssf_eligible' => 'boolean', 'approved_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
