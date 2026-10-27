<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanAgreementAmendment extends Model
{
    protected $fillable = ['business_id', 'loan_id', 'effective_on', 'before', 'after', 'snapshot_hash', 'reason', 'status', 'created_by', 'reviewed_by', 'reviewed_at', 'review_note', 'request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'before' => 'array', 'after' => 'array', 'reviewed_at' => 'datetime'];
    }
}
