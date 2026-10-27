<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceSupplierBill extends Model
{
    protected $fillable = ['receipt_snapshot', 'due_on', 'business_id', 'purchase_order_id', 'reference', 'vendor_invoice_reference',
        'vendor_invoice_date', 'accepted_quantity', 'total', 'currency', 'posting_status', 'notes', 'created_by', 'posted_at'];

    protected function casts(): array
    {
        return ['receipt_snapshot' => 'array', 'due_on' => 'date', 'vendor_invoice_date' => 'date', 'posted_at' => 'datetime'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancePayment::class, 'supplier_bill_id');
    }
}
