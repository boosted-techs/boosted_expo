<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\SupplierQuoteRevision;
use App\Models\SupplyRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierQuoting
{
    public function record(SupplyRequest $request, Supplier $supplier, array $data, string $channel): SupplierQuote
    {
        return DB::transaction(function () use ($request, $supplier, $data, $channel) {
            $business = Business::query()->lockForUpdate()->findOrFail($request->business_id);
            abort_unless($business->is_active, 404);
            $rfq = SupplyRequest::query()->where('business_id', $request->business_id)
                ->lockForUpdate()->findOrFail($request->id);

            abort_unless($rfq->status === 'sent' && $rfq->response_deadline->isFuture(), 422,
                'This request no longer accepts quotations.');
            $supplier = Supplier::query()->where('business_id', $rfq->business_id)
                ->where('active', true)->lockForUpdate()->find($supplier->id);
            abort_unless($supplier, 404);

            $math = app(ProcurementMath::class);
            $qty = $math->decimal($data['quantity']);
            if ($qty->isGreaterThan($math->decimal($rfq->quantity))) {
                throw ValidationException::withMessages([
                    'quantity' => 'Offered quantity cannot exceed the amount requested.',
                ]);
            }

            $quote = SupplierQuote::query()->where('business_id', $rfq->business_id)
                ->where('supply_request_id', $rfq->id)
                ->where('supplier_id', $supplier->id)->lockForUpdate()->first();

            $revision = $quote ? $quote->revision + 1 : 1;
            $fields = [
                'business_id' => $rfq->business_id,
                'supply_request_id' => $rfq->id,
                'supplier_id' => $supplier->id,
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'],
                'currency' => $rfq->currency,
                'delivery_on' => $data['delivery_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'revision' => $revision,
                'status' => 'submitted',
                'submitted_at' => now(),
            ];
            if ($quote) {
                $quote->update($fields);
            } else {
                $quote = SupplierQuote::create($fields);
            }

            SupplierQuoteRevision::create([
                'business_id' => $rfq->business_id, 'supplier_quote_id' => $quote->id,
                'revision' => $revision, 'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'], 'currency' => $rfq->currency,
                'delivery_on' => $data['delivery_on'] ?? null,
                'notes' => $data['notes'] ?? null, 'submitted_via' => $channel,
                'created_at' => now(),
            ]);

            return $quote->refresh();
        });
    }
}
