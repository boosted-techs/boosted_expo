<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPerson extends Model
{
    protected $table = 'staff_people';

    protected $fillable = ['business_id', 'user_id', 'staff_code', 'name', 'kind', 'skills', 'phone', 'active', 'advance_limit_base', 'payroll_tax_class', 'nssf_applicable', 'payroll_classification_note', 'payroll_classified_by', 'payroll_classified_at'];

    protected function casts(): array
    {
        return ['skills' => 'array', 'active' => 'boolean', 'nssf_applicable' => 'boolean', 'payroll_classified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
