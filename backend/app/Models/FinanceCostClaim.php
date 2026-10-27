<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceCostClaim extends Model
{
    protected $fillable = ['business_id', 'workflow_id', 'source_type', 'source_id', 'account', 'base_amount', 'source_hash'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FinanceWorkflow::class);
    }
}
