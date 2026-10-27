<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOrderingLink extends Model
{
    protected $fillable = ['business_id', 'customer_id', 'recipient', 'token_hash', 'product_ids', 'expires_at', 'revoked_at', 'delivery_status', 'created_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['product_ids' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
