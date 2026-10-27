<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffOutput extends Model
{
    protected $fillable = ['business_id', 'staff_person_id', 'staff_pay_rate_id', 'packing_job_id', 'sales_order_id', 'worked_on', 'quantity', 'unit', 'work_code', 'external_reference', 'evidence', 'status', 'created_by', 'approved_by', 'approved_at', 'review_note'];

    protected function casts(): array
    {
        return ['worked_on' => 'date', 'approved_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }
}
