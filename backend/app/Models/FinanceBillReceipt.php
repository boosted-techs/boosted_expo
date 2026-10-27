<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceBillReceipt extends Model
{
    protected $fillable = ['business_id', 'supplier_bill_id', 'goods_receipt_id', 'quantity', 'base_cost'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }
}
