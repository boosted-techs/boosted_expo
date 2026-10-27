<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBatchOrigin extends Model
{
    protected $fillable = ['business_id', 'batch_id', 'case_id', 'source_batch_id', 'workflow_id', 'kind', 'quantity', 'base_cost', 'data_snapshot', 'snapshot_hash'];

    protected function casts(): array
    {
        return ['data_snapshot' => 'array'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FinanceWorkflow::class);
    }
}
