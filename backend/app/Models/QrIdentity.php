<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrIdentity extends Model
{
    protected $fillable = ['business_id', 'level', 'record_id', 'public_token', 'public_enabled', 'access_pin_hash', 'access_version'];

    protected $hidden = ['access_pin_hash'];

    protected function casts(): array
    {
        return ['public_enabled' => 'boolean', 'access_version' => 'integer'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
