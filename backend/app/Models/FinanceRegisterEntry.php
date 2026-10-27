<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceRegisterEntry extends Model
{
    protected $fillable = ['business_id', 'reference', 'kind', 'asset_id', 'loan_id', 'instalment_id', 'original_entry_id', 'event_on', 'currency', 'amount', 'exchange_rate_id', 'rate_to_base', 'cash_account_id', 'cash_direction', 'cash_amount', 'return_bank_line_id', 'external_reference', 'reason', 'posting_status', 'data_snapshot', 'snapshot_hash', 'request_key', 'payload_hash', 'created_by', 'posted_at'];

    protected function casts(): array
    {
        return ['event_on' => 'date', 'posted_at' => 'datetime', 'data_snapshot' => 'array'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(BusinessAsset::class, 'asset_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanFacility::class, 'loan_id');
    }
}
