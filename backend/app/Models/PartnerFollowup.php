<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PartnerFollowup extends Model
{
    use SoftDeletes;

    protected $fillable = ['business_id', 'customer_id', 'supplier_id', 'staff_person_id', 'title', 'note', 'due_at', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
