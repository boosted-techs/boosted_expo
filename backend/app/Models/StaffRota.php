<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffRota extends Model
{
    protected $fillable = ['business_id', 'week_start', 'name', 'status', 'published_at', 'published_by', 'created_by', 'request_key'];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'published_at' => 'datetime'];
    }
}
