<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    protected $fillable = ['business_id', 'sales_order_line_id', 'batch_id', 'order_supply_id', 'quantity', 'consumed_quantity', 'lost_quantity', 'released_at', 'reason', 'request_key', 'created_by'];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
