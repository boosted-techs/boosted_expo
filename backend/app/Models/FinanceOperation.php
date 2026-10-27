<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceOperation extends Model
{
    protected $fillable = ['owner_user_id', 'business_id', 'reference', 'kind', 'currency', 'amount', 'event_on', 'due_on', 'exchange_rate_id', 'rate_to_base', 'customer_id', 'supplier_id', 'staff_person_id', 'sales_order_id', 'market_trip_id', 'shipment_id', 'advance_request_id', 'parent_id', 'commercial_invoice_id', 'supplier_bill_id', 'cash_account_id', 'destination_account_id', 'category', 'description', 'external_reference', 'posting_status', 'created_by', 'posted_at', 'idempotency_key', 'payload_hash', 'data_snapshot', 'snapshot_hash', 'consumed_base'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    protected function casts(): array
    {
        return ['event_on' => 'date', 'due_on' => 'date', 'posted_at' => 'datetime', 'data_snapshot' => 'array'];
    }
}
