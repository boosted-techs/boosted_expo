<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceTradeCredit extends Model
{
    protected $fillable = ['business_id', 'workflow_id', 'case_id', 'commercial_invoice_id', 'supplier_bill_id', 'customer_id', 'supplier_id', 'currency', 'amount', 'tax_amount', 'applied_amount', 'excess_amount', 'excess_base', 'due_on'];

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FinanceWorkflow::class);
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(StockExceptionCase::class, 'case_id');
    }
}
