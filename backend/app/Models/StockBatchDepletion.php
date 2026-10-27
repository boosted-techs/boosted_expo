<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBatchDepletion extends Model
{
    protected $fillable = ['business_id', 'batch_id', 'case_id', 'workflow_id', 'kind', 'quantity', 'base_cost'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FinanceWorkflow::class);
    }
}
