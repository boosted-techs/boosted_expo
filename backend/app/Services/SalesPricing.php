<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class SalesPricing
{
    public function money(BigDecimal|string|int $value): BigDecimal
    {
        $money=BigDecimal::of((string)$value)->toScale(2,RoundingMode::HalfUp);
        if($money->abs()->isGreaterThan(BigDecimal::of('999999999999999999.99'))) {
            throw ValidationException::withMessages(['items'=>'Amount exceeds the supported currency precision.']);
        }
        return $money;
    }

    public function totals(array $items, string $discountPercent='0', string $taxPercent='0',
        string $freight='0', string $insurance='0'): array
    {
        $subtotal=BigDecimal::zero();
        $lines=[];
        foreach($items as $item) {
            $qty=BigDecimal::of((string)$item['quantity']);
            $unit=BigDecimal::of((string)$item['unit_price']);
            $amount=$this->money($qty->multipliedBy($unit));
            $lines[]=[...$item,'line_total'=>(string)$amount];
            $subtotal=$subtotal->plus($amount);
        }
        $subtotal=$this->money($subtotal);
        $discount=$this->money($subtotal->multipliedBy((string)$discountPercent)->dividedBy('100',6,RoundingMode::HalfUp));
        $taxable=$subtotal->minus($discount);
        $tax=$this->money($taxable->multipliedBy((string)$taxPercent)->dividedBy('100',6,RoundingMode::HalfUp));
        $total=$taxable->plus($tax)->plus($this->money((string)$freight))->plus($this->money((string)$insurance));
        return [
            'lines'=>$lines,'subtotal'=>(string)$subtotal,
            'discount_amount'=>(string)$discount,'tax_amount'=>(string)$tax,
            'grand_total'=>(string)$this->money($total),
        ];
    }
}