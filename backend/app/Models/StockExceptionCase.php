<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockExceptionCase extends Model
{
    protected $fillable = ['due_on', 'resolution_workflow_id', 'business_id', 'reference', 'kind', 'event_on', 'batch_id', 'carton_id', 'shipment_id', 'staff_person_id', 'quantity', 'status', 'reason', 'external_reference', 'data_snapshot', 'snapshot_hash', 'result', 'workflow_id', 'created_by', 'reviewed_by', 'reviewed_at', 'review_note', 'request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'event_on' => 'date', 'reviewed_at' => 'datetime', 'data_snapshot' => 'array', 'result' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function carton(): BelongsTo
    {
        return $this->belongsTo(Carton::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ExportShipment::class, 'shipment_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(StaffPerson::class, 'staff_person_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FinanceWorkflow::class, 'workflow_id');
    }
}
