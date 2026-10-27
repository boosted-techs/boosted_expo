<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPaymentMethod extends Model
{
    protected $fillable = [
        'label', 'type', 'provider', 'account_name', 'account_number', 'instructions', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function snapshot(): array
    {
        return $this->only(['label', 'type', 'provider', 'account_name', 'account_number', 'instructions']);
    }
}
