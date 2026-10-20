<?php
namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class FinanceMath
{
    public function money(string|BigDecimal|int $number):BigDecimal
    {
        $value=BigDecimal::of((string)$number)->toScale(2,RoundingMode::HalfUp);
        if($value->abs()->isGreaterThan('999999999999999999.99')) {
            throw ValidationException::withMessages(['amount'=>'This amount exceeds the supported precision.']);
        }
        return $value;
    }

    public function base(string|BigDecimal|int $amount,string $rate):BigDecimal
    {
        return $this->money(BigDecimal::of((string)$amount)->multipliedBy($rate));
    }

    public function amount(string|BigDecimal $a,string|BigDecimal $b):BigDecimal
    {
        return $this->money(BigDecimal::of((string)$a)->multipliedBy((string)$b));
    }

    public function sum(array $values):BigDecimal
    {
        $total=BigDecimal::zero();
        foreach($values as $value) $total=$total->plus((string)$value);
        return $this->money($total);
    }
}