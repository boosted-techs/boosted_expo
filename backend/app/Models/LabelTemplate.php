<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabelTemplate extends Model
{
    protected $fillable = [
        'business_id','name','width_mm','height_mm','dpi',
        'qr_level','include_barcode','visible_fields','version','active','created_by',
    ];

    protected function casts(): array
    {
        return [
            'visible_fields'=>'array',
            'include_barcode'=>'boolean',
            'active'=>'boolean',
        ];
    }
}