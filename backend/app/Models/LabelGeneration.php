<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabelGeneration extends Model
{
    protected $fillable = [
        'business_id','carton_id','template_id','template_version',
        'zpl_sha256','generated_by','template_snapshot','zpl_snapshot',
    ];

    protected function casts(): array { return ['template_snapshot'=>'array']; }
    public function carton(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Carton::class); }
    public function template(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(LabelTemplate::class); }
}