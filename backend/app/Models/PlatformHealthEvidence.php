<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformHealthEvidence extends Model
{
    protected $fillable = ['business_id', 'metric', 'status', 'evidence', 'observed_at', 'valid_until', 'recorded_by', 'value_ugx', 'period_start', 'period_end'];

    protected $table = 'platform_health_evidence';

    protected function casts(): array
    {
        return ['observed_at' => 'datetime', 'period_start' => 'date', 'period_end' => 'date', 'valid_until' => 'datetime'];
    }
}
