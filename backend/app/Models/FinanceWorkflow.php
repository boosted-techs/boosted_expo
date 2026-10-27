<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceWorkflow extends Model
{
    protected $fillable = ['business_id', 'reference', 'kind', 'event_on', 'currency', 'amount', 'rate_to_base', 'exchange_rate_id', 'reason', 'external_reference', 'posting_status', 'data_snapshot', 'snapshot_hash', 'request_key', 'payload_hash', 'created_by', 'posted_at'];

    protected function casts(): array
    {
        return ['event_on' => 'date', 'data_snapshot' => 'array', 'posted_at' => 'datetime'];
    }

    public function legs(): HasMany
    {
        return $this->hasMany(FinanceWorkflowLeg::class, 'workflow_id')->orderBy('sequence');
    }
}
