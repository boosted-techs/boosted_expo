<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceipt extends Model
{
    protected $fillable = [
        'business_id', 'reference', 'purchase_order_id', 'batch_id',
        'received_quantity', 'accepted_quantity', 'rejected_quantity',
        'gross_weight_kg', 'tare_weight_kg', 'farmer_code', 'quality_notes',
        'received_on', 'posted_by', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['received_on' => 'date'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
