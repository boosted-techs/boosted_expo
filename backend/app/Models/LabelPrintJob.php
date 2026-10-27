<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelPrintJob extends Model
{
    protected $fillable = ['business_id', 'agent_id', 'generation_id', 'request_key',
        'status', 'attempts', 'lease_hash', 'lease_until', 'transferred_at',
        'failure_note', 'requested_by', 'copies', 'parent_job_id', 'operator_reason'];

    protected $hidden = ['lease_hash'];

    protected function casts(): array
    {
        return [
            'lease_until' => 'datetime', 'transferred_at' => 'datetime',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabelGeneration::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(LabelPrintAgent::class, 'agent_id');
    }
}
