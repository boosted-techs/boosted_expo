<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundingCommitment extends Model
{
    protected $fillable = ['business_id', 'reference', 'kind', 'currency', 'amount', 'due_on', 'purpose', 'evidence', 'status', 'created_by', 'reviewed_by', 'review_note', 'reviewed_at', 'request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'reviewed_at' => 'datetime'];
    }
}
