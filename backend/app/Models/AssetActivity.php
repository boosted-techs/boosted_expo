<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetActivity extends Model
{
    protected $fillable = ['business_id', 'asset_id', 'kind', 'event_on', 'reference', 'note', 'before', 'after', 'expense_id', 'created_by', 'request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['event_on' => 'date', 'before' => 'array', 'after' => 'array'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(FinanceOperation::class, 'expense_id');
    }
}
