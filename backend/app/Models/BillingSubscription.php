<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingSubscription extends Model
{
    protected $fillable = [
        'business_id', 'billing_plan_id', 'billing_email', 'billing_cycle', 'price_ugx',
        'tax_rate', 'status', 'starts_on', 'next_invoice_on', 'paid_through',
        'grace_days', 'agreement_notes', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'next_invoice_on' => 'date', 'paid_through' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function plan()
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function invoices()
    {
        return $this->hasMany(BillingInvoice::class);
    }
}
