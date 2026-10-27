<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceOpeningItem extends Model
{
    protected $fillable = ['business_id', 'cutover_id', 'workflow_id', 'workflow_leg_id', 'kind', 'reference', 'currency', 'amount', 'base_amount', 'customer_id', 'supplier_id', 'staff_person_id', 'due_on', 'data_snapshot', 'snapshot_hash'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'data_snapshot' => 'array'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FinanceWorkflow::class);
    }
}
