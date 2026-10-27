<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabelPrintBatch extends Model
{
    protected $fillable = ['business_id', 'request_key', 'payload_sha256', 'copies', 'carton_count', 'created_by'];

    public function generations(): HasMany
    {
        return $this->hasMany(LabelGeneration::class, 'batch_id');
    }
}
