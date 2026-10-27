<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReceiptAllocation extends Model
{
    protected $fillable = ['business_id', 'order_supply_id', 'goods_receipt_id', 'received_quantity', 'accepted_quantity', 'rejected_quantity'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }
}
