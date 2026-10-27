<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentRequirement extends Model
{
    protected $fillable = ['business_id', 'shipment_id', 'document_kind', 'file_required', 'staff_person_id', 'due_on', 'status', 'reason', 'created_by', 'waived_by', 'waived_at', 'waiver_reason'];

    protected function casts(): array
    {
        return ['file_required' => 'boolean', 'due_on' => 'date', 'waived_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
