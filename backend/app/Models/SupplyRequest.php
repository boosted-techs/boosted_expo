<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplyRequest extends Model
{
    protected static function booted(): void
    {
        static::created(function (self $request): void {
            $request->items()->create(['business_id' => $request->business_id, 'product_id' => $request->product_id, 'quantity' => $request->quantity]);
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplyRequestItem::class);
    }

    protected $fillable = [
        'business_id', 'reference', 'product_id', 'quantity', 'currency', 'needed_on',
        'response_deadline', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['response_deadline' => 'datetime', 'needed_on' => 'date'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(SupplierRequestLink::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(SupplierQuote::class);
    }
}
