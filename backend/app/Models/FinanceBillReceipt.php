<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceBillReceipt extends Model
{
    protected $fillable = ['business_id', 'supplier_bill_id', 'goods_receipt_id', 'quantity', 'base_cost'];
}
