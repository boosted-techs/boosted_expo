<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceExchangeRate extends Model
{
    protected $fillable = ['revision', 'business_id', 'currency', 'base_currency', 'rate_to_base', 'effective_on', 'status', 'source_note', 'created_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'approved_at' => 'datetime'];
    }
}
