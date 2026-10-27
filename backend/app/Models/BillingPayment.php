<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPayment extends Model
{
    protected $fillable = [
        'billing_invoice_id', 'billing_payment_method_id',
        'verified_by', 'amount_ugx', 'reference', 'received_on', 'notes',
    ];

    protected function casts(): array
    {
        return ['received_on' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }
}
