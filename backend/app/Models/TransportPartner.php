<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransportPartner extends Model
{
    protected $fillable = ['business_id', 'name', 'phone', 'partner_code', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
