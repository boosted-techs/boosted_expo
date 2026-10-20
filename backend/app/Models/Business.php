<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    protected $fillable = [
        'name', 'code', 'country_code', 'base_currency', 'timezone',
        'default_qr_level', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembership::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(BusinessInvitation::class);
    }
}