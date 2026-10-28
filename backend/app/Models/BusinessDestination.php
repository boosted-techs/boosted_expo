<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessDestination extends Model
{
    protected $fillable = ['business_id', 'name', 'name_key', 'country_code', 'city', 'location', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
