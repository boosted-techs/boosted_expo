<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancePayrollLine extends Model
{
    protected $fillable = ['staff_person_id', 'name_snapshot', 'source_snapshot', 'business_id', 'payroll_run_id', 'user_id', 'gross', 'deductions', 'net'];

    protected function casts(): array
    {
        return ['source_snapshot' => 'array'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
