<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = ['market_trip_id', 'expected_on',
        'business_id', 'reference', 'kind', 'product_id', 'supplier_id', 'supplier_quote_id',
        'vendor_name', 'vendor_phone', 'market_location', 'purchased_on', 'quantity',
        'unit_price', 'estimated_total', 'currency', 'status', 'notes', 'idempotency_key', 'created_by',
    ];

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'expected_on' => 'date'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
