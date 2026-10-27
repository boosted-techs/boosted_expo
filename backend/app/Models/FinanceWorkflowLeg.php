<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceWorkflowLeg extends Model
{
    protected $fillable = ['business_id', 'workflow_id', 'sequence', 'currency', 'rate_to_base', 'exchange_rate_id', 'cash_account_id', 'cash_direction', 'amount', 'event_on', 'lines', 'posting_status'];

    protected function casts(): array
    {
        return ['event_on' => 'date', 'lines' => 'array', 'sequence' => 'integer'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FinanceWorkflow::class, 'workflow_id');
    }
}
