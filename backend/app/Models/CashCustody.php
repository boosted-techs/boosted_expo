<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashCustody extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'account_id', 'name', 'currency', 'staff_person_id', 'authorised_float', 'active', 'created_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
