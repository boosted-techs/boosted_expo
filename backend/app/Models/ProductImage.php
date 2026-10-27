<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = [
        'stored_bytes',
        'business_id', 'product_id', 'original_key', 'thumbnail_key', 'width', 'height',
    ];

    public function storageDisk(): string
    {
        $prefix = 'demo-media/'.$this->business_id.'/';

        return app()->environment(['local', 'testing'])
            && str_starts_with($this->original_key, $prefix)
            && str_starts_with($this->thumbnail_key, $prefix)
            && Business::query()->whereKey($this->business_id)->where('code', 'DEMO-EXPORT')->exists()
                ? 'local' : 'r2';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
