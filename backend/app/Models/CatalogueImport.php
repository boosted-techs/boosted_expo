<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogueImport extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'business_id', 'user_id', 'rows', 'row_count', 'status', 'expires_at', 'applied_at'];

    protected function casts(): array
    {
        return ['rows' => 'array', 'expires_at' => 'datetime', 'applied_at' => 'datetime'];
    }
}