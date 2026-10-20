<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'business_id','batch_id','movement_type','quantity','unit','source_type','source_id','created_by',
    ];
}