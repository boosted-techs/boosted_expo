<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockLocationEvent extends Model
{
    protected $fillable = ['business_id', 'batch_id', 'carton_id', 'from_location_id', 'to_location_id', 'quantity', 'unit', 'reason', 'created_by', 'request_key', 'payload_hash'];
}
