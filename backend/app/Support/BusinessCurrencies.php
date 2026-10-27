<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class BusinessCurrencies
{
    public function catalogue(): array
    {
        return config('currencies.supported', []);
    }

    public function enabled(Business $business): array
    {
        $currencies = $business->enabled_currencies ?? config('currencies.defaults', []);

        return array_values(array_unique(array_filter(
            [$business->base_currency, ...$currencies],
            fn ($currency) => is_string($currency) && array_key_exists($currency, $this->catalogue()),
        )));
    }

    public function rule(Business $business): In
    {
        return Rule::in($this->enabled($business));
    }
}
