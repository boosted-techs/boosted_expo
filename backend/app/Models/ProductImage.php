<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = [
        'business_id', 'product_id', 'original_key', 'thumbnail_key', 'width', 'height',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}