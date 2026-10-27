<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPlan extends Model
{
    protected $fillable = [
        'family_code', 'version', 'modules', 'seat_limit', 'storage_limit_bytes',
        'code', 'name', 'description', 'features',
        'monthly_price_ugx', 'quarterly_price_ugx', 'annual_price_ugx',
        'is_public', 'is_active',
    ];

    protected function casts(): array
    {
        return ['features' => 'array', 'modules' => 'array', 'is_public' => 'boolean', 'is_active' => 'boolean'];
    }
}
