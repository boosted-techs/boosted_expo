<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['business_id','shipment_id','actor_id','action','details','created_at'];
    protected function casts(): array { return ['details'=>'array','created_at'=>'datetime']; }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}