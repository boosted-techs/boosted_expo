<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformUsageActivity extends Model
{
    protected $fillable = ['business_id', 'user_id', 'activity_on', 'last_seen_at'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['activity_on' => 'date', 'last_seen_at' => 'datetime'];
    }
}
