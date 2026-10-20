<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartonContent extends Model
{
    protected $fillable = ['business_id','carton_id','batch_id','quantity'];
    public function carton(): BelongsTo { return $this->belongsTo(Carton::class); }
    public function batch(): BelongsTo { return $this->belongsTo(StockBatch::class); }
}