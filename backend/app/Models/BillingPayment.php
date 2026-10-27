<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
