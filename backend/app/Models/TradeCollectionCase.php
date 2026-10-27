<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeCollectionCase extends Model
{
    protected $fillable = ['business_id', 'commercial_invoice_id', 'supplier_bill_id', 'staff_person_id', 'status', 'next_action_on', 'promised_on', 'disputed_amount', 'note', 'automatic', 'created_by'];

    protected function casts(): array
    {
        return ['next_action_on' => 'date', 'promised_on' => 'date', 'automatic' => 'boolean'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CommercialInvoice::class, 'commercial_invoice_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(FinanceSupplierBill::class, 'supplier_bill_id');
    }
}
