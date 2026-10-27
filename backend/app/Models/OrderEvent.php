<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderEvent extends Model
{
    protected $fillable = ['business_id', 'sales_order_id', 'actor_id', 'action', 'reason', 'details', 'customer_note'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
