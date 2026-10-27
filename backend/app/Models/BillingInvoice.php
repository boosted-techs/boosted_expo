<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingInvoice extends Model
{
    protected $fillable = [
        'business_id', 'billing_subscription_id', 'issued_by', 'billing_payment_method_id',
        'invoice_number', 'business_name', 'billing_email', 'plan_name',
        'billing_cycle', 'period_start', 'period_end', 'due_on',
        'payment_details', 'subtotal_ugx', 'tax_ugx', 'total_ugx', 'paid_ugx',
        'status', 'notes', 'issued_at', 'sent_at', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'due_on' => 'date',
            'payment_details' => 'array', 'issued_at' => 'datetime',
            'sent_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    public function subscription()
    {
        return $this->belongsTo(BillingSubscription::class, 'billing_subscription_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function payments()
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function remaining(): int
    {
        return max(0, $this->total_ugx - $this->paid_ugx);
    }
}
