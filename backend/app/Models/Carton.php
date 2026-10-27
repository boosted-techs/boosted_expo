<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Carton extends Model
{
    protected $fillable = [
        'stock_location_id', 'business_id', 'carton_code', 'net_weight_kg', 'gross_weight_kg', 'status',
        'packing_job_id', 'grade', 'packaging_type', 'tare_weight_kg',
        'packed_by', 'packed_at', 'packing_key',
    ];

    protected function casts(): array
    {
        return ['packed_at' => 'datetime'];
    }

    public function packingJob(): BelongsTo
    {
        return $this->belongsTo(PackingJob::class);
    }

    public function shipmentAllocation(): HasOne
    {
        return $this->hasOne(ShipmentCarton::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(CartonContent::class);
    }
}
