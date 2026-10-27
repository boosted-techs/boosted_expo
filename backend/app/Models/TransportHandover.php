<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransportHandover extends Model
{
    protected $fillable = ['business_id', 'transport_leg_id', 'action', 'from_person', 'to_person', 'evidence_reference', 'occurred_at', 'notes', 'request_key', 'recorded_by'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
