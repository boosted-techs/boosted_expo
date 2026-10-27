<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialLot extends Model
{
    protected $fillable = ['business_id', 'workflow_id', 'name', 'unit', 'location', 'quantity', 'base_cost'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FinanceWorkflow::class);
    }
}
