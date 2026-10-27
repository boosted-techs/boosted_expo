<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabelTemplate extends Model
{
    protected $fillable = [
        'business_id', 'name', 'width_mm', 'height_mm', 'dpi',
        'qr_level', 'include_barcode', 'visible_fields', 'version', 'active', 'created_by',
        'media_profile_id', 'layout', 'required_fields', 'qr_visibility', 'customer_id', 'product_id', 'is_default', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'visible_fields' => 'array',
            'include_barcode' => 'boolean',
            'active' => 'boolean',
            'layout' => 'array', 'required_fields' => 'array', 'is_default' => 'boolean', 'approved_at' => 'datetime',
        ];
    }
}
