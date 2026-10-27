<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingAdjustment extends Model
{
    protected $fillable = ['billing_invoice_id', 'kind', 'amount_ugx', 'request_key', 'reason', 'evidence_reference', 'recorded_by'];
}
