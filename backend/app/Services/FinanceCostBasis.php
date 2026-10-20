<?php

namespace App\Services;

use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceJournal;
use App\Models\FinanceSupplierBill;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class FinanceCostBasis
{
    public function unitBase(FinanceSupplierBill $bill,FinanceJournal $original):BigDecimal
    {
        abort_unless($original->business_id===$bill->business_id
            && $original->source_type==='supplier_bill'
            && $original->source_id===$bill->id,422,'Supplier bill posting does not match.');
        $math=app(FinanceMath::class);
        if(FinanceBalanceAdjustment::query()->where('business_id',$bill->business_id)
            ->where('supplier_bill_id',$bill->id)
            ->where('posting_status','not_posted')->exists()) {
            throw ValidationException::withMessages(['inventory'=>
                'Finish the pending supplier price concession before valuing or dispatching financial inventory.']);
        }
        $net=$math->base((string)$bill->total,(string)$original->rate_to_base);
        $adjustment=FinanceBalanceAdjustment::query()->where('business_id',$bill->business_id)
            ->where('supplier_bill_id',$bill->id)->where('posting_status','posted')->first();
        if($adjustment) {
            $net=$net->minus($math->base((string)$adjustment->amount,
                (string)$original->rate_to_base));
        }
        $quantity=BigDecimal::of((string)$bill->accepted_quantity);
        if($quantity->isLessThanOrEqualTo(0) || $net->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['inventory'=>'Supplier bill has no valid remaining cost basis.']);
        }
        return $net->dividedBy($quantity,12,RoundingMode::HalfUp);
    }
}
