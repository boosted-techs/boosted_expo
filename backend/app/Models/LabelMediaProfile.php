<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabelMediaProfile extends Model
{
    protected $fillable = ['business_id', 'name', 'printer_model', 'protocol', 'dpi', 'width_mm', 'height_mm', 'margin_mm', 'offset_x_mm', 'offset_y_mm', 'media_type', 'orientation', 'test_evidence', 'tested_at', 'tested_by', 'created_by'];

    protected function casts(): array
    {
        return ['tested_at' => 'datetime'];
    }
}
