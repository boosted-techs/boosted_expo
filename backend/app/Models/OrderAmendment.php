<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAmendment extends Model
{
    protected $fillable = ['business_id', 'sales_order_id', 'reference', 'status', 'data_snapshot', 'snapshot_hash', 'base_hash', 'reason', 'created_by', 'reviewed_by', 'reviewed_at', 'review_note', 'buyer_mode', 'buyer_name', 'buyer_reference', 'buyer_evidence', 'buyer_decided_at', 'token_hash', 'expires_at', 'revoked_at', 'applied_at', 'request_key', 'payload_hash'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['data_snapshot' => 'array', 'reviewed_at' => 'datetime', 'buyer_decided_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
