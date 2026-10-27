<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseAmendment extends Model
{
    protected $fillable = ['business_id', 'purchase_order_id', 'status', 'data_snapshot', 'snapshot_hash', 'base_hash', 'reason', 'agreement_reference', 'created_by', 'reviewed_by', 'review_note', 'reviewed_at', 'request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['data_snapshot' => 'array', 'reviewed_at' => 'datetime'];
    }
}
