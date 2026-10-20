<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExportShipment extends Model
{
    protected $fillable = [
        'business_id','reference','customer_id','status','transport_mode','creation_key','origin_country',
        'origin_airport','destination_country','destination_airport','carrier','flight_number',
        'air_waybill','scheduled_departure_at','estimated_arrival_at',
        'actual_departure_at','actual_arrival_at','incoterm','notes',
        'created_by','dispatched_by','delivered_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_departure_at'=>'datetime','estimated_arrival_at'=>'datetime',
            'actual_departure_at'=>'datetime','actual_arrival_at'=>'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function allocations(): HasMany { return $this->hasMany(ShipmentCarton::class, 'shipment_id'); }
    public function events(): HasMany { return $this->hasMany(ShipmentEvent::class, 'shipment_id'); }
    public function documents(): HasMany { return $this->hasMany(ExportDocument::class, 'shipment_id'); }
}