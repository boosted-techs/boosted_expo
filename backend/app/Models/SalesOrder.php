<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    protected $fillable = ['order_type', 'replacement_case_id', 'replacement_shipment_id', 'pricing_snapshot', 'pricing_status', 'required_on', 'destination', 'closed_at', 'close_reason', 'business_id', 'reference', 'sales_request_id', 'sales_quotation_id',
        'customer_id', 'status', 'currency', 'incoterm', 'discount_percent', 'tax_percent',
        'subtotal', 'discount_amount', 'tax_amount', 'grand_total', 'buyer_confirmation',
        'notes', 'confirmed_by', 'confirmed_at'];

    protected function casts(): array
    {
        return ['pricing_snapshot' => 'array', 'confirmed_at' => 'datetime', 'required_on' => 'date', 'closed_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    public function salesRequest(): BelongsTo
    {
        return $this->belongsTo(SalesRequest::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(ExportShipment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CommercialInvoice::class);
    }
}
