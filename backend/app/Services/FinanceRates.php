<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceExchangeRate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceRates
{
    public function propose(Business $business, array $data, int $userId): FinanceExchangeRate
    {
        return DB::transaction(function () use ($business, $data, $userId) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $currency = strtoupper($data['currency']);
            abort_unless($currency !== $business->base_currency, 422,
                'The company base currency always uses an exchange rate of one.');
            abort_unless(BigDecimal::of((string) $data['rate_to_base'])->isGreaterThan(0), 422);

            return FinanceExchangeRate::create([
                'business_id' => $business->id, 'currency' => $currency,
                'revision' => 1 + (int) FinanceExchangeRate::query()->where('business_id', $business->id)->where('currency', $currency)->whereDate('effective_on', $data['effective_on'])->max('revision'),
                'base_currency' => $business->base_currency,
                'rate_to_base' => $data['rate_to_base'],
                'effective_on' => $data['effective_on'],
                'status' => 'proposed', 'source_note' => $data['source_note'],
                'created_by' => $userId,
            ]);
        }, 3);
    }

    public function approve(FinanceExchangeRate $rate, int $userId): void
    {
        DB::transaction(function () use ($rate, $userId) {
            $rate = FinanceExchangeRate::query()->where('business_id', $rate->business_id)
                ->lockForUpdate()->findOrFail($rate->id);
            abort_unless($rate->status === 'proposed', 422, 'Only proposed rates can be approved.');
            abort_unless($rate->created_by !== $userId, 422,
                'A second authorised person must approve the exchange rate.');
            $rate->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);
        });
    }

    public function rate(Business $business, string $currency, ?int $rateId, string $postingOn): string
    {
        if ($currency === $business->base_currency) {
            if ($rateId !== null) {
                throw ValidationException::withMessages([
                    'exchange_rate_id' => 'The base currency does not use a separate exchange-rate record.',
                ]);
            }

            return '1';
        }
        if ($rateId === null) {
            throw ValidationException::withMessages([
                'exchange_rate_id' => 'Choose an approved exchange rate for '.$currency.'.',
            ]);
        }
        $rate = FinanceExchangeRate::query()->where('business_id', $business->id)
            ->where('status', 'approved')->findOrFail($rateId);
        if ($rate->currency !== $currency || $rate->base_currency !== $business->base_currency
            || $rate->effective_on->toDateString() > $postingOn) {
            throw ValidationException::withMessages([
                'exchange_rate_id' => 'The approved rate must match the currency, base currency and posting date.',
            ]);
        }

        return (string) $rate->rate_to_base;
    }
}
