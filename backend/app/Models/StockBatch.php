<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBatch extends Model
{
    protected $fillable = ['stock_location_id', 'business_id', 'product_id', 'batch_code', 'source_reference', 'received_on', 'is_quarantined', 'quality_hold_reason',
        'quality_hold_by', 'quality_held_at'];

    protected function casts(): array
    {
        return ['is_quarantined' => 'boolean',
            'quality_held_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
