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
    public function recordMany(SupplyRequest $request, Supplier $supplier, array $data, string $channel): array
    {
        return DB::transaction(function () use ($request, $supplier, $data, $channel) {
            $result = [];
            foreach ($data['items'] as $item) {
                $result[] = $this->record($request, $supplier, [...collect($data)->except('items')->all(), ...$item], $channel);
            }

            return $result;
        });
    }

    public function record(SupplyRequest $request, Supplier $supplier, array $data, string $channel): SupplierQuote
    {
        return DB::transaction(function () use ($request, $supplier, $data, $channel) {
            $business = Business::query()->lockForUpdate()->findOrFail($request->business_id);
            abort_unless($business->is_active, 404);
            app(PlatformTenancy::class)->assertModuleWrite($business, 'procurement');
            $rfq = SupplyRequest::query()->where('business_id', $request->business_id)
                ->lockForUpdate()->findOrFail($request->id);

            abort_unless(in_array($rfq->status, ['sent', 'part_awarded'], true) && $rfq->response_deadline->isFuture(), 422,
                'This request no longer accepts quotations.');
            $supplier = Supplier::query()->where('business_id', $rfq->business_id)
                ->where('active', true)->lockForUpdate()->find($supplier->id);
            abort_unless($supplier, 404);

            if (empty($data['supply_request_item_id']) && $rfq->items()->count() > 1) {
                throw ValidationException::withMessages(['supply_request_item_id' => 'Choose the requested product for this offer.']);
            }
            $item = $rfq->items()->where('business_id', $rfq->business_id)->when(! empty($data['supply_request_item_id']), fn ($q) => $q->whereKey($data['supply_request_item_id']))->whereHas('product', fn ($q) => $q->where('active', true))->orderBy('id')->firstOrFail();
            $math = app(ProcurementMath::class);
            $qty = $math->decimal($data['quantity']);
            if ($qty->isGreaterThan($math->decimal($item->quantity))) {
                throw ValidationException::withMessages([
                    'quantity' => 'Offered quantity cannot exceed the amount requested.',
                ]);
            }

            $quote = SupplierQuote::query()->where('business_id', $rfq->business_id)
                ->where('supply_request_id', $rfq->id)
                ->where('supplier_id', $supplier->id)->where('supply_request_item_id', $item->id)->lockForUpdate()->first();

            abort_if($quote?->status === 'selected', 422, 'An awarded offer is frozen; submit a new reviewed request for further changes.');
            $revision = $quote ? $quote->revision + 1 : 1;
            $fields = [
                'business_id' => $rfq->business_id,
                'supply_request_id' => $rfq->id, 'supply_request_item_id' => $item->id,
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
