<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentCarton extends Model
{
    protected $fillable = ['business_id','shipment_id','carton_id','allocated_by'];

    public function shipment(): BelongsTo { return $this->belongsTo(ExportShipment::class, 'shipment_id'); }
    public function carton(): BelongsTo { return $this->belongsTo(Carton::class); }
}