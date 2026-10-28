<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'business_id', 'customer_code', 'name', 'country_code', 'city', 'currency',
        'email', 'phone', 'notes', 'active', 'business_name', 'business_email', 'location',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
