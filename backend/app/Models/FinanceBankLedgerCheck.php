<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceBankLedgerCheck extends Model
{
    protected $fillable = ['business_id', 'statement_id', 'status', 'data_snapshot', 'sha256',
        'prepared_by', 'reviewed_by', 'review_notes', 'verified_at', 'superseded_by_check_id', 'superseded_by', 'superseded_at', 'supersession_note', 'supersession_snapshot', 'supersession_hash'];

    protected function casts(): array
    {
        return ['data_snapshot' => 'array', 'verified_at' => 'datetime', 'superseded_at' => 'datetime', 'supersession_snapshot' => 'array'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(FinanceBankStatement::class, 'statement_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FinanceBankLedgerCheckEvent::class, 'check_id');
    }
}
