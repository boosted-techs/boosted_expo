<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformUsageSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['business_id', 'period_start', 'period_end', 'counters', 'measured_at'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'counters' => 'array', 'measured_at' => 'datetime'];
    }
}
