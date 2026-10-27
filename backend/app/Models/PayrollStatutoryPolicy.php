<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollStatutoryPolicy extends Model
{
    protected $fillable = ['business_id', 'name', 'starts_on', 'ends_on', 'rules', 'source_note', 'status', 'created_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'rules' => 'array', 'approved_at' => 'datetime'];
    }
}
