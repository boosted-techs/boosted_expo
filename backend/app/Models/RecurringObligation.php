<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringObligation extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'name', 'currency', 'amount', 'category', 'supplier_id', 'staff_person_id', 'next_due_on', 'interval_months', 'active', 'terms', 'created_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'next_due_on' => 'date', 'interval_months' => 'integer'];
    }
}
