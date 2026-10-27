<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanInstalment extends Model
{
    protected $fillable = ['status', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'business_id', 'loan_id', 'due_on', 'principal', 'interest', 'fees', 'reference', 'created_by'];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanFacility::class, 'loan_id');
    }

    protected function casts(): array
    {
        return ['cancelled_at' => 'datetime', 'due_on' => 'date'];
    }
}
