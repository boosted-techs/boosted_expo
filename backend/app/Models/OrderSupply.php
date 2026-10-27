<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSupply extends Model
{
    protected $fillable = ['business_id', 'sales_order_line_id', 'purchase_order_id', 'market_trip_id', 'origin_supply_id', 'kind', 'quantity', 'status', 'reason', 'request_key', 'request_hash', 'created_by'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(MarketTrip::class, 'market_trip_id');
    }
}
