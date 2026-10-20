<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierQuoteRevision extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'business_id','supplier_quote_id','revision','quantity','unit_price',
        'currency','delivery_on','notes','submitted_via','created_at',
    ];
}