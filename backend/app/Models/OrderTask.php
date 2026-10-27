<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTask extends Model
{
    protected $fillable = ['business_id', 'sales_order_id', 'staff_person_id', 'title', 'kind', 'due_at', 'status', 'note', 'created_by', 'request_key'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
