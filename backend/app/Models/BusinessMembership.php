<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessMembership extends Model
{
    protected $fillable = ['business_id', 'user_id', 'role', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}