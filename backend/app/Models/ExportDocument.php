<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportDocument extends Model
{
    protected $fillable = [
        'business_id','shipment_id','template_id','reference','document_type',
        'version','template_version','data_snapshot','html_snapshot','sha256','generated_by',
    ];
    protected function casts(): array { return ['data_snapshot'=>'array']; }
    public function shipment(): BelongsTo { return $this->belongsTo(ExportShipment::class, 'shipment_id'); }
    public function template(): BelongsTo { return $this->belongsTo(ExportDocumentTemplate::class, 'template_id'); }
}