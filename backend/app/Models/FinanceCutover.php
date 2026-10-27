<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceCutover extends Model
{
    protected $fillable = ['source_file_path', 'source_file_sha256', 'business_id', 'reference', 'effective_on', 'status', 'data_snapshot', 'snapshot_hash', 'workflow_id', 'created_by', 'accepted_by', 'accepted_at', 'acceptance_note', 'acceptance_snapshot', 'acceptance_hash', 'request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'data_snapshot' => 'array', 'accepted_at' => 'datetime', 'acceptance_snapshot' => 'array'];
    }
}
