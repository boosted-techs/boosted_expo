<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformAuditEvent extends Model
{
    protected $fillable = ['business_id', 'actor_id', 'action', 'reason', 'before_data', 'after_data'];

    protected function casts(): array
    {
        return ['before_data' => 'array', 'after_data' => 'array'];
    }
}
