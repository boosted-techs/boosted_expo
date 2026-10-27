<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    protected $fillable = [
        'occurred_on', 'business_id', 'batch_id', 'movement_type', 'quantity', 'unit', 'source_type', 'source_id', 'created_by',
    ];
}
