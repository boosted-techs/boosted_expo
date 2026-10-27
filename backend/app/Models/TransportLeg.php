<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportLeg extends Model
{
    protected $fillable = ['business_id', 'shipment_id', 'transport_partner_id', 'staff_person_id', 'sequence', 'mode', 'origin', 'destination', 'due_at', 'partner_snapshot', 'driver_name', 'vehicle_reference', 'booking_reference', 'carton_ids', 'status', 'request_key', 'created_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'partner_snapshot' => 'array', 'carton_ids' => 'array'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ExportShipment::class, 'shipment_id');
    }
}
