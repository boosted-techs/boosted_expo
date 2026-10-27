<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradeCreditException extends Model
{
    protected $fillable = ['business_id', 'customer_id', 'shipment_id', 'currency', 'exposure_limit', 'expires_on', 'reason', 'approved_by', 'commercial_invoice_id'];

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }
}
