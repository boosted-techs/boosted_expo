<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportDocumentTemplate extends Model
{
    protected $fillable = [
        'business_id','name','type','paper_size','orientation',
        'visible_columns','header_fields','version','active','created_by',
    ];
    protected function casts(): array
    {
        return ['visible_columns'=>'array','header_fields'=>'array','active'=>'boolean'];
    }
}