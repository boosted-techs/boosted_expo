<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBatch extends Model
{
    protected $fillable = ['business_id','product_id','batch_code','source_reference','received_on'];
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}