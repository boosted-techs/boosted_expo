<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CommercialInvoice extends Model
{
    protected $fillable = ['due_on', 'business_id', 'shipment_id', 'sales_order_id', 'customer_id', 'reference',
        'currency', 'subtotal', 'discount_amount', 'tax_amount', 'freight_amount',
        'insurance_amount', 'grand_total', 'posting_status', 'data_snapshot',
        'html_snapshot', 'sha256', 'issued_by', 'issued_at'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'data_snapshot' => 'array', 'issued_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ExportShipment::class, 'shipment_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function accountingHandoff(): HasOne
    {
        return $this->hasOne(InvoiceAccountingHandoff::class, 'commercial_invoice_id');
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(FinanceCreditNote::class, 'commercial_invoice_id');
    }
}
