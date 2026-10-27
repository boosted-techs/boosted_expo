<?php

namespace App\Services;

use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceBillReceipt;
use App\Models\FinanceJournal;
use App\Models\FinanceSupplierBill;
use App\Models\GoodsReceipt;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class FinanceCostBasis
{
    public function receiptUnitBase(GoodsReceipt $receipt): BigDecimal
    {
        $math = app(FinanceMath::class);
        $quantity = BigDecimal::zero();
        $cost = BigDecimal::zero();
        $bills = FinanceSupplierBill::query()->where('business_id', $receipt->business_id)->where('purchase_order_id', $receipt->purchase_order_id)->orderBy('id')->get();
        foreach ($bills as $bill) {
            if ($bill->receipt_snapshot) {
                $rows = FinanceBillReceipt::query()->where('business_id', $receipt->business_id)->where('supplier_bill_id', $bill->id)->orderBy('goods_receipt_id')->get();
                $row = $rows->firstWhere('goods_receipt_id', $receipt->id);
                if (! $row) {
                    continue;
                }
                abort_unless($bill->posting_status === 'posted' && $row->base_cost !== null, 422, 'Post every bill for the received batch before valuing its cost.');
                $journal = FinanceJournal::query()->where('business_id', $receipt->business_id)->where('source_type', 'supplier_bill')->where('source_id', $bill->id)->firstOrFail();
                $this->unitBase($bill, $journal);
                $allocated = BigDecimal::of((string) $row->base_cost);
                $adjustment = FinanceBalanceAdjustment::query()->where('business_id', $bill->business_id)->where('supplier_bill_id', $bill->id)->where('posting_status', 'posted')->first();
                if ($adjustment) {
                    $discount = $math->base((string) $adjustment->amount, (string) $journal->rate_to_base);
                    $remaining = $discount;
                    foreach ($rows as $i => $part) {
                        $share = $i === $rows->count() - 1 ? $remaining : $math->money($discount->multipliedBy((string) $part->quantity)->dividedBy((string) $bill->accepted_quantity, 14, RoundingMode::HalfUp));
                        if ($part->id === $row->id) {
                            $allocated = $allocated->minus($share);
                        }
                        $remaining = $remaining->minus($share);
                    }
                }
                $quantity = $quantity->plus((string) $row->quantity);
                $cost = $cost->plus($allocated);
            } else {
                $remaining = BigDecimal::of((string) $bill->accepted_quantity);
                $share = BigDecimal::zero();
                foreach (GoodsReceipt::query()->where('business_id', $receipt->business_id)->where('purchase_order_id', $receipt->purchase_order_id)->orderBy('id')->get() as $prior) {
                    $used = BigDecimal::min((string) $prior->accepted_quantity, $remaining);
                    if ($prior->id === $receipt->id) {
                        $share = $used;
                    }
                    $remaining = $remaining->minus($used);
                }
                if (! $share->isGreaterThan(0)) {
                    continue;
                }
                abort_unless($bill->posting_status === 'posted', 422, 'Post the original purchase bill before stock valuation.');
                $journal = FinanceJournal::query()->where('business_id', $receipt->business_id)->where('source_type', 'supplier_bill')->where('source_id', $bill->id)->firstOrFail();
                $quantity = $quantity->plus($share);
                $cost = $cost->plus($share->multipliedBy($this->unitBase($bill, $journal)));
            }
        }
        abort_unless($quantity->isEqualTo((string) $receipt->accepted_quantity) && $quantity->isGreaterThan(0), 422, 'Accepted stock is not fully covered by posted supplier bills. Complete its bill allocation before valuation.');

        return $cost->dividedBy($quantity, 12, RoundingMode::HalfUp);
    }

    public function unitBase(FinanceSupplierBill $bill, FinanceJournal $original): BigDecimal
    {
        abort_unless($original->business_id === $bill->business_id
            && $original->source_type === 'supplier_bill'
            && $original->source_id === $bill->id, 422, 'Supplier bill posting does not match.');
        $math = app(FinanceMath::class);
        if (FinanceBalanceAdjustment::query()->where('business_id', $bill->business_id)
            ->where('supplier_bill_id', $bill->id)
            ->where('posting_status', 'not_posted')->exists()) {
            throw ValidationException::withMessages(['inventory' => 'Finish the pending supplier price concession before valuing or dispatching financial inventory.']);
        }
        $net = $math->base((string) $bill->total, (string) $original->rate_to_base);
        $adjustment = FinanceBalanceAdjustment::query()->where('business_id', $bill->business_id)
            ->where('supplier_bill_id', $bill->id)->where('posting_status', 'posted')->first();
        if ($adjustment) {
            $net = $net->minus($math->base((string) $adjustment->amount,
                (string) $original->rate_to_base));
        }
        $quantity = BigDecimal::of((string) $bill->accepted_quantity);
        if ($quantity->isLessThanOrEqualTo(0) || $net->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['inventory' => 'Supplier bill has no valid remaining cost basis.']);
        }

        return $net->dividedBy($quantity,12,RoundingMode::HalfUp);
    }
}
