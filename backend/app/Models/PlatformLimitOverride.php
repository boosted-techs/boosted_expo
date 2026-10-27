<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformLimitOverride extends Model
{
    protected $fillable = ['business_id', 'metric', 'allowance', 'starts_on', 'ends_on', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
