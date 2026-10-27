<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceMoneyCorrection extends Model
{
    protected $fillable = ['business_id', 'operation_id', 'workflow_id', 'original_journal_id', 'amount', 'control_base', 'data_snapshot'];

    protected function casts(): array
    {
        return ['data_snapshot' => 'array'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FinanceWorkflow::class, 'workflow_id');
    }
}
