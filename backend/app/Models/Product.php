<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = ['business_id', 'sku', 'name', 'description', 'base_unit', 'thumbnail_key', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function images(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}