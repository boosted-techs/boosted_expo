<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class ProcurementMath
{
    public function decimal(string|int|float $number): BigDecimal
    {
        return BigDecimal::of((string) $number);
    }

    public function amount(string $unitPrice, string $quantity): string
    {
        return (string) $this->decimal($unitPrice)
            ->multipliedBy($this->decimal($quantity))
            ->toScale(2, RoundingMode::HalfUp);
    }

    public function positive(string $number, string $field): void
    {
        if ($this->decimal($number)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages([$field => 'Quantity must be greater than zero.']);
        }
    }

    public function sum(array $amounts): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($amounts as $amount) $total = $total->plus((string) $amount);
        return $total;
    }
}