<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected $fillable = ['business_id', 'batch_id', 'reference', 'event_on', 'expected_quantity', 'counted_quantity', 'quantity_delta', 'unit', 'currency', 'amount', 'reason', 'status', 'posting_status', 'data_snapshot', 'snapshot_hash', 'created_by', 'reviewed_by', 'review_note', 'reviewed_at', 'posted_at', 'request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['event_on' => 'date', 'data_snapshot' => 'array', 'reviewed_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
