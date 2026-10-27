<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessAsset extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'asset_code', 'name', 'category', 'ownership', 'serial_number', 'purchase_reference', 'location', 'staff_person_id', 'condition', 'operating_status', 'useful_life_months', 'residual_base', 'depreciation_start_on', 'maintenance_due_on', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['depreciation_start_on' => 'date', 'maintenance_due_on' => 'date', 'useful_life_months' => 'integer'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
