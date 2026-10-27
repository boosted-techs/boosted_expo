<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ExportShipment;
use App\Models\FinanceCostLayer;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceSupplierBill;
use App\Models\GoodsReceipt;
use App\Models\PackingWasteEntry;
use App\Models\StockBatch;
use App\Models\StockBatchOrigin;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceInventoryValuation
{
    private function sourceQuantities(Business $business, string $kind, int $id): array
    {
        $quantities = [];
        if ($kind === 'cogs') {
            $shipment = ExportShipment::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($id);
            abort_unless(in_array($shipment->status, ['dispatched', 'delivered'], true), 422,
                'Shipment must be dispatched before COGS is prepared.');
            $shipment->load('allocations.carton.contents');
            abort_unless($shipment->allocations->isNotEmpty(), 422, 'No cartons are allocated.');
            foreach ($shipment->allocations as $entry) {
                $carton = $entry->carton;
                abort_unless($carton && $carton->contents->isNotEmpty(), 422,
                    'Every allocated carton requires source-batch quantities.');
                foreach ($carton->contents as $content) {
                    abort_unless($content->business_id === $business->id, 404);
                    $batchId = $content->batch_id;
                    $quantities[$batchId] = ($quantities[$batchId] ?? BigDecimal::zero())
                        ->plus((string) $content->quantity);
                }
            }
        } elseif ($kind === 'waste') {
            $entry = PackingWasteEntry::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($id);
            $quantities[$entry->batch_id] = BigDecimal::of((string) $entry->quantity);
        } else {
            abort(422, 'Unknown inventory valuation type.');
        }
        foreach ($quantities as $quantity) {
            abort_unless($quantity->isGreaterThan(0), 422, 'Inventory relief needs positive quantities.');
        }

        return $quantities;
    }

    public function prepare(Business $business, string $kind, int $sourceId, int $actor): FinanceInventoryRelief
    {
        return DB::transaction(function () use ($business, $kind, $sourceId, $actor) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $column = $kind === 'cogs' ? 'shipment_id' : 'waste_entry_id';
            abort_unless(in_array($kind, ['cogs', 'waste'], true), 422);
            if (FinanceInventoryRelief::query()->where('business_id', $business->id)
                ->where($column, $sourceId)->exists()) {
                abort(422, 'This inventory consumption has already been valued. Reuse its original review.');
            }
            $quantities = $this->sourceQuantities($business, $kind, $sourceId);
            $math = app(FinanceMath::class);
            $batchIds = array_keys($quantities);
            $batches = StockBatch::query()->where('business_id', $business->id)
                ->whereIn('id', $batchIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless(count($batchIds) === $batches->count(), 404, 'Missing source batches.');

            // Reserve quantities across every pending or posted relief, never allowing
            // more than one financial write-off for the same accepted source units.
            $reserved = [];
            foreach (FinanceInventoryRelief::query()->where('business_id', $business->id)
                ->get(['data_snapshot']) as $relief) {
                foreach (($relief->data_snapshot['lines'] ?? []) as $line) {
                    $batchId = (int) $line['batch_id'];
                    if (! isset($quantities[$batchId])) {
                        continue;
                    }
                    $reserved[$batchId]['quantity'] = ($reserved[$batchId]['quantity'] ?? BigDecimal::zero())
                        ->plus((string) $line['quantity']);
                    $reserved[$batchId]['base_cost'] = ($reserved[$batchId]['base_cost'] ?? BigDecimal::zero())
                        ->plus((string) $line['base_cost']);
                }
            }
            foreach (FinanceCostLayer::query()->where('business_id', $business->id)->whereIn('batch_id', $batchIds)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'posted'))->get() as $layer) {
                $reserved[$layer->batch_id]['base_cost'] = ($reserved[$layer->batch_id]['base_cost'] ?? BigDecimal::zero())->plus((string) $layer->cogs_amount)->plus((string) $layer->waste_amount);
            }
            abort_if(FinanceCostLayer::query()->where('business_id', $business->id)->whereIn('batch_id', $batchIds)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'not_posted'))->exists(), 422, 'Finish pending batch cost allocations before valuing stock consumption.');
            $items = [];
            $total = BigDecimal::zero();
            foreach ($batches as $batch) {
                $receipt = GoodsReceipt::query()->where('business_id', $business->id)
                    ->where('batch_id', $batch->id)->with('purchaseOrder')->first();
                $origin = StockBatchOrigin::query()->where('business_id', $business->id)->where('batch_id', $batch->id)->first();
                $purchase = null;
                $bill = null;
                $journal = null;
                if (! $origin) {
                    if (! $receipt || ! $receipt->purchaseOrder) {
                        throw ValidationException::withMessages(['inventory' => 'Batch '.$batch->batch_code.' has no verified purchase receipt.']);
                    }
                    $purchase = $receipt->purchaseOrder;
                    $bill = FinanceSupplierBill::query()->where('business_id', $business->id)
                        ->where('purchase_order_id', $purchase->id)
                        ->where('posting_status', 'posted')->first();
                    if (! $bill) {
                        throw ValidationException::withMessages(['inventory' => 'Post the supplier bill for '.$purchase->reference.' before valuing stock.']);
                    }
                    $journal = FinanceJournal::query()->where('business_id', $business->id)
                        ->where('source_type', 'supplier_bill')->where('source_id', $bill->id)->first();
                    if (! $journal) {
                        throw ValidationException::withMessages(['inventory' => 'Missing the posted journal for supplier bill '.$bill->reference.'.']);
                    }
                }
                $basis = app(FinanceCostBasis::class)->batchBasis($batch);
                $received = $basis['quantity'];
                $previousQty = $reserved[$batch->id]['quantity'] ?? BigDecimal::zero();
                $previousCost = $reserved[$batch->id]['base_cost'] ?? BigDecimal::zero();
                $qty = $quantities[$batch->id];
                if ($qty->plus($previousQty)->isGreaterThan($received)) {
                    throw ValidationException::withMessages(['inventory' => 'Financial stock consumption exceeds accepted stock for batch '.$batch->batch_code.'.']);
                }
                // Use the posted supplier bill's net payable valuation, including
                // independently posted pre-consumption price concessions.
                $unitBase = $basis['unit'];
                $totalBatch = $basis['cost'];
                $cost = $qty->plus($previousQty)->isEqualTo($received)
                    ? $totalBatch->minus($previousCost)
                    : $math->money($qty->multipliedBy($unitBase));
                if ($cost->isLessThanOrEqualTo(0)
                    || $previousCost->plus($cost)->isGreaterThan($totalBatch)) {
                    throw ValidationException::withMessages(['inventory' => 'Batch valuation is exhausted or this quantity is too small for two-decimal costing.']);
                }
                $total = $total->plus($cost);
                $items[] = [
                    'batch_id' => $batch->id, 'batch_code' => $batch->batch_code,
                    'purchase_id' => $purchase?->id, 'purchase_reference' => $purchase?->reference ?? 'Verified '.$origin?->kind.' stock origin', 'origin_id' => $origin?->id,
                    'supplier_bill_id' => $bill?->id,
                    'supplier_bill_reference' => $bill?->reference,
                    'original_currency' => $purchase?->currency ?? $business->base_currency,
                    'quantity' => (string) $qty, 'unit' => $batch->product->base_unit,
                    'rate_to_base' => (string) ($journal?->rate_to_base ?? '1'),
                    'base_cost' => (string) $cost, 'basis_quantity' => (string) $basis['quantity'], 'basis_cost' => (string) $basis['cost'],
                ];
            }
            $snapshot = [
                'kind' => $kind, 'source_id' => $sourceId,
                'expense_account' => $kind === 'cogs' && ExportShipment::query()->where('business_id', $business->id)->findOrFail($sourceId)->salesOrder?->order_type === 'replacement' ? 'quality_claim_loss' : ($kind === 'cogs' ? 'cogs' : 'waste_loss'),
                'base_currency' => $business->base_currency, 'lines' => $items,
                'method' => 'posted_produce_and_reviewed_production_cost_layers',
                'caveat' => 'Includes posted batch cost allocations. Unassigned costs remain period expenses.',
            ];
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

            return FinanceInventoryRelief::create([
                'business_id' => $business->id, 'reference' => 'VAL-'.Str::upper((string) Str::ulid()),
                'kind' => $kind, 'shipment_id' => $kind === 'cogs' ? $sourceId : null,
                'waste_entry_id' => $kind === 'waste' ? $sourceId : null,
                'currency' => $business->base_currency,
                'base_amount' => (string) $math->money($total),
                'data_snapshot' => $snapshot, 'sha256' => hash('sha256', $encoded),
                'posting_status' => 'not_posted', 'created_by' => $actor,
            ]);
        }, 3);
    }

    public function validateBeforePost(FinanceInventoryRelief $relief): void
    {
        $business = Business::query()->findOrFail($relief->business_id);
        $actual = $this->sourceQuantities($business, $relief->kind,
            $relief->kind === 'cogs' ? $relief->shipment_id : $relief->waste_entry_id);
        $snapshot = $relief->data_snapshot;
        $expectedExpense = $relief->kind === 'cogs' && ExportShipment::query()->where('business_id', $business->id)->findOrFail($relief->shipment_id)->salesOrder?->order_type === 'replacement' ? 'quality_claim_loss' : ($relief->kind === 'cogs' ? 'cogs' : 'waste_loss');
        abort_unless(($snapshot['expense_account'] ?? ($relief->kind === 'cogs' ? 'cogs' : 'waste_loss')) === $expectedExpense, 422, 'The shipment cost classification changed.');
        $snapshotQuantities = [];
        $total = BigDecimal::zero();
        foreach ($snapshot['lines'] as $line) {
            $id = (int) $line['batch_id'];
            $snapshotQuantities[$id] = BigDecimal::of($line['quantity']);
            if (isset($line['basis_quantity'])) {
                $batch = StockBatch::query()->where('business_id', $business->id)->findOrFail($id);
                $basis = app(FinanceCostBasis::class)->batchBasis($batch);
                abort_unless(BigDecimal::of($line['basis_quantity'])->isEqualTo($basis['quantity']) && BigDecimal::of($line['basis_cost'])->isEqualTo($basis['cost']), 422, 'The approved stock cost layer changed after relief preparation.');
            }
            $total = $total->plus((string) $line['base_cost']);
        }
        abort_unless(count($actual) === count($snapshotQuantities), 422,
            'Inventory source lines changed. Review the source data.');
        foreach ($actual as $id => $qty) {
            abort_unless(isset($snapshotQuantities[$id]) &&
                $qty->isEqualTo($snapshotQuantities[$id]), 422, 'Inventory source quantities have changed.');
        }
        abort_unless($total->isEqualTo((string) $relief->base_amount), 422, 'Valuation is inconsistent.');
        abort_unless(hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)) === $relief->sha256, 422,
            'Original inventory valuation snapshot failed integrity validation.');
    }
}
