<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketAccountability extends Model
{
    protected $fillable = ['business_id', 'market_trip_id', 'status', 'data_snapshot', 'snapshot_hash', 'reason', 'closed_by', 'reopened_by', 'reopened_at', 'reopen_reason'];

    protected function casts(): array
    {
        return ['data_snapshot' => 'array', 'reopened_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(MarketTrip::class, 'market_trip_id');
    }
}
