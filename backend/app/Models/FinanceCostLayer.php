<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceCostLayer extends Model
{
    protected $fillable = ['business_id', 'workflow_id', 'batch_id', 'base_amount', 'inventory_amount', 'cogs_amount', 'waste_amount'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FinanceWorkflow::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
