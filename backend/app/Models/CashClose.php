<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashClose extends Model
{
    protected $fillable = ['data_snapshot', 'snapshot_hash', 'business_id', 'custody_id', 'counted_on', 'expected', 'counted', 'exchange_rate_id', 'rate_to_base', 'ledger_fingerprint', 'denominations', 'reason', 'status', 'workflow_id', 'created_by', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected function casts(): array
    {
        return ['data_snapshot' => 'array', 'counted_on' => 'date', 'reviewed_at' => 'datetime', 'denominations' => 'array'];
    }
}
