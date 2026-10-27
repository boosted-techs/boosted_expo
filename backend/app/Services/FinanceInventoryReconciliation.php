<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceBillReceipt;
use App\Models\FinanceCostLayer;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceSupplierBill;
use App\Models\GoodsReceipt;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockBatchDepletion;
use App\Models\StockBatchOrigin;
use App\Models\StockExceptionCase;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class FinanceInventoryReconciliation
{
    public function report(Business $business, string $through): array
    {
        $rows = [];
        $issues = [];
        $journalDates = [];
        $journalIds = [];
        foreach (FinanceJournal::query()->where('business_id', $business->id)->whereDate('posted_on', '<=', $through)->get(['id', 'source_type', 'source_id', 'posted_on']) as $journal) {
            $key = $journal->source_type.':'.$journal->source_id;
            $journalDates[$key] = $journal->posted_on->toDateString();
            $journalIds[$key] = $journal->id;
        }
        $batches = StockBatch::query()->where('business_id', $business->id)->with('product')->get()->keyBy('id');
        foreach ($batches as $batch) {
            $rows[$batch->id] = ['reference' => $batch->batch_code, 'product' => $batch->product->name, 'unit' => $batch->product->base_unit, 'quantity' => BigDecimal::zero(), 'cost' => BigDecimal::zero(), 'physical' => BigDecimal::zero()];
        }
        $add = function (int $batch, string $quantity, string $cost) use (&$rows): void {
            abort_unless(isset($rows[$batch]), 422, 'A valuation source references stock outside this business.');
            $rows[$batch]['quantity'] = $rows[$batch]['quantity']->plus($quantity);
            $rows[$batch]['cost'] = $rows[$batch]['cost']->plus($cost);
        };
        $math = app(FinanceMath::class);
        foreach (FinanceSupplierBill::query()->where('business_id', $business->id)->where('posting_status', 'posted')->with('purchase.receipts')->lazyById(100) as $bill) {
            if (! isset($journalDates['supplier_bill:'.$bill->id])) {
                continue;
            }
            $original = FinanceJournal::query()->whereKey($journalIds['supplier_bill:'.$bill->id])->where('business_id', $business->id)->firstOrFail();
            $discount = BigDecimal::zero();
            foreach (FinanceBalanceAdjustment::query()->where('business_id', $business->id)->where('supplier_bill_id', $bill->id)->where('posting_status', 'posted')->get() as $concession) {
                if (isset($journalDates['balance_adjustment:'.$concession->id])) {
                    $discount = $discount->plus($math->base((string) $concession->amount, (string) $original->rate_to_base));
                }
            }
            $allocations = FinanceBillReceipt::query()->where('business_id', $business->id)->where('supplier_bill_id', $bill->id)->with('receipt')->orderBy('goods_receipt_id')->get();
            if ($bill->receipt_snapshot) {
                $remainingDiscount = $discount;
                foreach ($allocations as $index => $allocation) {
                    $share = $index === $allocations->count() - 1 ? $remainingDiscount : $math->money($discount->multipliedBy((string) $allocation->quantity)->dividedBy((string) $bill->accepted_quantity, 14, RoundingMode::HalfUp));
                    $remainingDiscount = $remainingDiscount->minus($share);
                    $receipt = $allocation->receipt;
                    if ($receipt && $receipt->received_on->toDateString() <= $through && $receipt->batch_id) {
                        $add($receipt->batch_id, (string) $allocation->quantity, (string) BigDecimal::of((string) $allocation->base_cost)->minus($share));
                    } else {
                        $issues[] = $bill->reference.' has a posted value without a received batch by this date.';
                    }
                }
            } else {
                $remaining = BigDecimal::of((string) $bill->accepted_quantity);
                $net = $math->base((string) $bill->total, (string) $original->rate_to_base)->minus($discount);
                $remainingCost = $net;
                foreach ($bill->purchase->receipts->sortBy('id') as $receipt) {
                    $qty = BigDecimal::min($remaining, (string) $receipt->accepted_quantity);
                    if (! $qty->isGreaterThan(0)) {
                        continue;
                    }
                    $cost = $qty->isEqualTo($remaining) ? $remainingCost : $math->money($net->multipliedBy($qty)->dividedBy((string) $bill->accepted_quantity, 14, RoundingMode::HalfUp));
                    $remaining = $remaining->minus($qty);
                    $remainingCost = $remainingCost->minus($cost);
                    if ($receipt->received_on->toDateString() <= $through && $receipt->batch_id) {
                        $add($receipt->batch_id, (string) $qty, (string) $cost);
                    }
                }
                if ($remaining->isGreaterThan(0)) {
                    $issues[] = $bill->reference.' lacks a complete historical receipt allocation.';
                }
            }
        }
        foreach (StockBatchOrigin::query()->where('business_id', $business->id)->with('workflow')->lazyById(100) as $origin) {
            abort_unless(hash_equals($origin->snapshot_hash, app(FinanceWorkflows::class)->hash($origin->data_snapshot)), 422, 'An original stock identity/value snapshot changed.');
            $date = substr((string) $batches[$origin->batch_id]->received_on, 0, 10);
            if ($origin->workflow_id && $origin->workflow?->posting_status !== 'posted') {
                continue;
            }
            $layers = $origin->data_snapshot['source']['valuation_layers'] ?? [];
            $cost = BigDecimal::zero();
            if ($layers) {
                $date = min(array_column($layers, 'event_on'));
                foreach ($layers as $layer) {
                    if ($layer['event_on'] <= $through) {
                        $cost = $cost->plus($layer['base_cost']);
                    }
                }
            } else {
                $cost = BigDecimal::of((string) $origin->base_cost);
            }
            if ($date && $date <= $through) {
                $add($origin->batch_id, (string) $origin->quantity, (string) $cost);
            }
        }
        foreach (StockBatchDepletion::query()->where('business_id', $business->id)->with('workflow')->lazyById(100) as $depletion) {
            $date = $depletion->workflow?->event_on?->toDateString() ?? StockExceptionCase::query()->where('business_id', $business->id)->findOrFail($depletion->case_id)->event_on->toDateString();
            if ($date <= $through && (! $depletion->workflow_id || $depletion->workflow?->posting_status === 'posted')) {
                $add($depletion->batch_id, (string) BigDecimal::of((string) $depletion->quantity)->negated(), (string) BigDecimal::of((string) $depletion->base_cost)->negated());
            }
        }
        foreach (StockAdjustment::query()->where('business_id', $business->id)->where('posting_status', 'posted')->cursor() as $adjustment) {
            if (! isset($journalDates['stock_adjustment:'.$adjustment->id])) {
                continue;
            }
            $qty = BigDecimal::of((string) $adjustment->quantity_delta);
            $cost = BigDecimal::of((string) $adjustment->amount)->multipliedBy($qty->isLessThan(0) ? -1 : 1);
            $add($adjustment->batch_id, (string) $qty, (string) $cost);
        }
        foreach (FinanceCostLayer::query()->where('business_id', $business->id)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'posted')->whereDate('event_on', '<=', $through))->cursor() as $layer) {
            $add($layer->batch_id, '0', (string) $layer->base_amount);
            foreach (DB::table('finance_cost_layer_reliefs')->where('business_id', $business->id)->where('cost_layer_id', $layer->id)->whereDate('event_on', '<=', $through)->pluck('base_cost') as $share) {
                $add($layer->batch_id, '0', (string) BigDecimal::of((string) $share)->negated());
            }
        }
        foreach (FinanceInventoryRelief::query()->where('business_id', $business->id)->where('posting_status', 'posted')->cursor() as $relief) {
            if (isset($journalDates['inventory_relief:'.$relief->id])) {
                foreach ($relief->data_snapshot['lines'] as $line) {
                    $add((int) $line['batch_id'], (string) BigDecimal::of($line['quantity'])->negated(), (string) BigDecimal::of($line['base_cost'])->negated());
                }
            }
        }
        $receiptDates = GoodsReceipt::query()->where('business_id', $business->id)->get(['id', 'received_on'])->mapWithKeys(fn ($r) => [$r->id => $r->received_on->toDateString()]);
        $countDates = StockAdjustment::query()->where('business_id', $business->id)->get(['id', 'event_on'])->mapWithKeys(fn ($r) => [$r->id => $r->event_on->toDateString()]);
        foreach (StockMovement::query()->where('business_id', $business->id)->whereNotIn('movement_type', ['packing_consumption', 'carton_unpacked'])->cursor() as $movement) {
            $date = $movement->occurred_on?->toDateString() ?? match ($movement->source_type) {
                'goods_receipt' => $receiptDates[$movement->source_id] ?? null, 'stock_adjustment' => $countDates[$movement->source_id] ?? null, default => $movement->created_at->copy()->timezone($business->timezone)->toDateString()
            };
            if ($date && $date <= $through) {
                $rows[$movement->batch_id]['physical'] = $rows[$movement->batch_id]['physical']->plus((string) $movement->quantity);
            }
        }
        foreach (DB::table('shipment_cartons as s')->join('export_shipments as e', 'e.id', '=', 's.shipment_id')->join('carton_contents as c', 'c.carton_id', '=', 's.carton_id')->where('s.business_id', $business->id)->where('e.business_id', $business->id)->where('c.business_id', $business->id)->whereIn('e.status', ['dispatched', 'delivered'])->whereDate('e.actual_departure_at', '<=', $through)->get(['c.batch_id', 'c.quantity']) as $content) {
            $rows[$content->batch_id]['physical'] = $rows[$content->batch_id]['physical']->minus((string) $content->quantity);
        }
        $inventory = BigDecimal::zero();
        foreach ($rows as &$row) {
            $row['quantity_difference'] = $row['quantity']->minus($row['physical']);
            $inventory = $inventory->plus($row['cost']);
            if (! $row['quantity_difference']->isZero() || $row['cost']->isLessThan(0)) {
                $issues[] = $row['reference'].' has physical/valued quantity or negative-cost differences.';
            }
        } unset($row);
        $materials = BigDecimal::zero();
        foreach (DB::table('material_movements as m')->join('finance_workflows as w', 'w.id', '=', 'm.workflow_id')->where('m.business_id', $business->id)->where('w.business_id', $business->id)->where('w.posting_status', 'posted')->whereDate('w.event_on', '<=', $through)->pluck('m.base_cost') as $value) {
            $materials = $materials->plus((string) $value);
        }
        $actual = ['inventory' => BigDecimal::zero(), 'materials_inventory' => BigDecimal::zero()];
        foreach (DB::table('finance_journal_lines as l')->join('finance_journals as j', 'j.id', '=', 'l.journal_id')->join('finance_accounts as a', 'a.id', '=', 'l.account_id')->where('l.business_id', $business->id)->where('j.business_id', $business->id)->where('a.business_id', $business->id)->whereIn('a.system_key', array_keys($actual))->whereDate('j.posted_on', '<=', $through)->get(['a.system_key', 'l.base_debit', 'l.base_credit']) as $line) {
            $actual[$line->system_key] = $actual[$line->system_key]->plus((string) $line->base_debit)->minus((string) $line->base_credit);
        }
        $controls = [['label' => 'Produce inventory', 'source' => $inventory, 'book' => $actual['inventory'], 'difference' => $actual['inventory']->minus($inventory)], ['label' => 'Preparation materials', 'source' => $materials, 'book' => $actual['materials_inventory'], 'difference' => $actual['materials_inventory']->minus($materials)]];
        foreach ($controls as $control) {
            if (! $control['difference']->isZero()) {
                $issues[] = $control['label'].' source values differ from the ledger.';
            }
        }

        return ['through' => $through, 'rows' => array_values($rows), 'controls' => $controls, 'issues' => array_values(array_unique($issues)), 'matches' => $issues === []];
    }
}
