<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSupportGrant extends Model
{
    protected $fillable = ['business_id', 'platform_user_id', 'scope', 'reason', 'status', 'approved_by', 'expires_at', 'approved_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'approved_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
