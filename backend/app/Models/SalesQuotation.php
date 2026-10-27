<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesQuotation extends Model
{
    protected $fillable = ['business_id', 'sales_request_id', 'reference', 'revision', 'status',
        'currency', 'incoterm', 'valid_until', 'discount_percent', 'tax_percent',
        'subtotal', 'discount_amount', 'tax_amount', 'grand_total', 'notes', 'created_by',
        'currency_override_reason', 'pricing_exchange_rate_id', 'pricing_snapshot', 'payment_terms_days', 'issued_by', 'issued_at', 'html_snapshot', 'sha256'];

    protected function casts(): array
    {
        return ['pricing_snapshot' => 'array', 'valid_until' => 'date', 'issued_at' => 'datetime'];
    }

    public function salesRequest(): BelongsTo
    {
        return $this->belongsTo(SalesRequest::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesQuotationLine::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(SalesOrder::class);
    }
}
