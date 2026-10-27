<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelGeneration extends Model
{
    protected $fillable = [
        'business_id', 'carton_id', 'template_id', 'template_version',
        'zpl_sha256', 'generated_by', 'template_snapshot', 'zpl_snapshot',
        'batch_id', 'scan_token', 'source_snapshot', 'source_sha256', 'artwork_png', 'verified_at', 'verified_by',
    ];

    protected function casts(): array
    {
        return ['template_snapshot' => 'array', 'source_snapshot' => 'array', 'verified_at' => 'datetime'];
    }

    public function carton(): BelongsTo
    {
        return $this->belongsTo(Carton::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LabelTemplate::class);
    }
}
