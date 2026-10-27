<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformDelivery extends Model
{
    protected $fillable = ['business_id', 'kind', 'source_id', 'recipient', 'payload', 'status', 'attempts', 'attempted_at', 'sent_at', 'lease_until', 'failure_note', 'deduplication_key'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'attempted_at' => 'datetime', 'sent_at' => 'datetime', 'lease_until' => 'datetime'];
    }
}
