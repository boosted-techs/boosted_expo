<?php

namespace App\Services;

use App\Models\FinanceCostClaim;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceOperation;
use App\Models\FinancePartialBankReturn;
use App\Models\FinanceShipmentCost;
use App\Models\OrderSupply;
use App\Models\SalesOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class OrderCostSheet
{
    public function report(SalesOrder $order): array
    {
        $math = app(FinanceMath::class);
        $postedExpense = BigDecimal::zero();
        $pending = [];
        $expenses = [];
        foreach (FinanceOperation::query()->where('business_id', $order->business_id)->whereIn('kind', ['expense', 'staff_expense', 'paid_expense'])->whereIn('posting_status', ['not_posted', 'posted'])->orderBy('id')->get() as $source) {
            $allocations = $source->data_snapshot['allocations'] ?? [];
            $allocation = collect($allocations)->firstWhere('sales_order_id', $order->id);
            if (! $allocation) {
                continue;
            }
            $base = null;
            if ($source->posting_status === 'posted') {
                $journal = FinanceJournal::query()->where('business_id', $order->business_id)->where('source_type', 'money_operation')->where('source_id', $source->id)->with('lines.account')->firstOrFail();
                $expense = BigDecimal::zero();
                foreach ($journal->lines as $line) {
                    if ($line->account->system_key === 'general_expense') {
                        $expense = $expense->plus((string) $line->base_debit)->minus((string) $line->base_credit);
                    }
                }
                $corrected = app(FinanceMoneyCorrections::class)->corrected($source);
                $expense = $expense->minus($math->money($expense->multipliedBy($corrected)->dividedBy((string) $source->amount, 14, RoundingMode::HalfUp)));
                foreach (FinanceCostClaim::query()->where('business_id', $order->business_id)->where('source_type', 'expense')->where('source_id', $source->id)->where('account', 'general_expense')->whereHas('workflow', fn ($q) => $q->where('posting_status', 'posted'))->pluck('base_amount') as $capitalized) {
                    $expense = $expense->minus((string) $capitalized);
                }
                $remaining = $expense;
                foreach ($allocations as $i => $part) {
                    $share = $i === count($allocations) - 1 ? $remaining : $math->money($expense->multipliedBy($part['amount'])->dividedBy((string) $source->amount, 14, RoundingMode::HalfUp));
                    if ($part['sales_order_id'] === $order->id) {
                        $base = $share;
                    }
                    $remaining = $remaining->minus($share);
                }
                $postedExpense = $postedExpense->plus($base);
            } else {
                $pending[$source->currency] = ($pending[$source->currency] ?? BigDecimal::zero())->plus($allocation['amount']);
            }
            $expenses[] = ['source' => $source, 'amount' => $allocation['amount'], 'basis' => $allocation['basis'], 'base' => $base];
        }
        $shipments = $order->shipments()->pluck('id');
        $cogs = BigDecimal::zero();
        $missingCogs = 0;
        $shipmentCosts = BigDecimal::zero();
        foreach ($order->shipments()->whereIn('status', ['dispatched', 'delivered'])->get() as $shipment) {
            $relief = FinanceInventoryRelief::query()->where('business_id', $order->business_id)->where('shipment_id', $shipment->id)->where('posting_status', 'posted')->first();
            if (! $relief) {
                $missingCogs++;
            } else {
                $cogs = $cogs->plus(app(FinanceManagementReports::class)->shipmentCost($shipment));
            }
        }
        foreach (FinanceShipmentCost::query()->where('business_id', $order->business_id)->whereIn('shipment_id', $shipments)->where('status', 'posted')->get() as $cost) {
            $journal = FinanceJournal::query()->where('business_id', $order->business_id)->where('source_type', 'shipment_cost')->where('source_id', $cost->id)->with('lines.account')->firstOrFail();
            foreach ($journal->lines as $line) {
                if ($line->account->system_key === 'shipment_cost') {
                    $shipmentCosts = $shipmentCosts->plus((string) $line->base_debit)->minus((string) $line->base_credit);
                }
            }
            foreach (FinancePartialBankReturn::query()->where('business_id', $order->business_id)->where('source_type', 'shipment_cost')->where('source_id', $cost->id)->where('posting_status', 'posted')->get() as $returned) {
                $journal = FinanceJournal::query()->where('business_id', $order->business_id)->where('source_type', 'partial_bank_return')->where('source_id', $returned->id)->with('lines.account')->firstOrFail();
                foreach ($journal->lines as $line) {
                    if ($line->account->system_key === 'shipment_cost') {
                        $shipmentCosts = $shipmentCosts->plus((string) $line->base_debit)->minus((string) $line->base_credit);
                    }
                }
            }
        }
        $committed = [];
        foreach (OrderSupply::query()->where('business_id', $order->business_id)->whereIn('sales_order_line_id', $order->lines()->pluck('id'))->where('status', 'active')->whereNotNull('purchase_order_id')->with('purchase')->get() as $supply) {
            if (! $supply->purchase) {
                continue;
            }
            $purchase = $supply->purchase;
            $committed[$purchase->currency] = ($committed[$purchase->currency] ?? BigDecimal::zero())->plus($math->amount((string) $supply->quantity, (string) $purchase->unit_price));
        }

        return compact('postedExpense', 'pending', 'expenses', 'cogs', 'missingCogs', 'shipmentCosts', 'committed');
    }
}
