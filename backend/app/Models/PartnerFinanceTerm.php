<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerFinanceTerm extends Model
{
    protected $fillable = ['business_id', 'customer_id', 'supplier_id', 'currency', 'payment_days', 'credit_limit', 'effective_on', 'agreement', 'created_by'];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'payment_days' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
