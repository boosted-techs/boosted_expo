<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformCase extends Model
{
    protected $fillable = ['business_id', 'kind', 'title', 'details', 'status', 'priority', 'assigned_to', 'created_by', 'due_at', 'resolution', 'deduplication_key'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }
}
