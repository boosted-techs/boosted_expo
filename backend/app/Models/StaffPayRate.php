<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPayRate extends Model
{
    protected $fillable = ['business_id', 'staff_person_id', 'basis', 'amount', 'currency', 'starts_on', 'ends_on', 'status', 'agreement_reference', 'created_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'approved_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
