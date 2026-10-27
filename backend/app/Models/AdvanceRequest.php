<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvanceRequest extends Model
{
    protected $fillable = ['business_id', 'reference', 'staff_person_id', 'supplier_id', 'market_trip_id', 'currency', 'amount', 'needed_on', 'accountable_on', 'reason', 'status', 'created_by', 'approved_by', 'approved_at', 'decision_note', 'request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['needed_on' => 'date', 'accountable_on' => 'date', 'approved_at' => 'datetime'];
    }

    use HasFactory;

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(MarketTrip::class, 'market_trip_id');
    }
}
