<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'business_id','supplier_code','name','kind','market_location',
        'email','phone','notes','active',
    ];

    protected function casts(): array { return ['active'=>'boolean']; }
}