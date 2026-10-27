<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketTrip extends Model
{
    protected $fillable = ['business_id', 'reference', 'staff_person_id', 'location', 'due_at', 'budget', 'currency', 'status', 'notes', 'request_key', 'created_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
