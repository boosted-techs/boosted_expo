<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabelPrintAgent extends Model
{
    protected $fillable = ['business_id', 'name', 'credential_hash', 'active',
        'created_by', 'revoked_by', 'last_seen_at', 'media_profile_id'];

    protected $hidden = ['credential_hash'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_seen_at' => 'datetime'];
    }
}
