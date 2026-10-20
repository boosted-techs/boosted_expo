<?php

namespace App\Services;

use App\Models\StockMovement;
use Brick\Math\BigDecimal;

class StockAvailability
{
    public function available(int $businessId, int $batchId): BigDecimal
    {
        $total = BigDecimal::zero();
        // Movements are signed: receiving adds, packing and grading losses subtract.
        // Always add in decimal arithmetic, not floating point.
        $values = StockMovement::query()->where('business_id', $businessId)
            ->where('batch_id', $batchId)->pluck('quantity');
        foreach ($values as $value) {
            $total = $total->plus((string) $value);
        }
        return $total;
    }
}