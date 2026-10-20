<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackingWasteEntry extends Model
{
    protected $fillable = [
        'business_id','packing_job_id','batch_id','quantity','reason',
        'notes','idempotency_key','created_by',
    ];
    public function batch(): BelongsTo { return $this->belongsTo(StockBatch::class); }
}