<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartonRepack extends Model
{
    protected $fillable = ['business_id', 'carton_id', 'reason', 'status', 'data_snapshot', 'snapshot_hash', 'created_by', 'reviewed_by', 'review_note', 'reviewed_at', 'request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['data_snapshot' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function carton(): BelongsTo
    {
        return $this->belongsTo(Carton::class, 'carton_id');
    }
}
