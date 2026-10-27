<?php

namespace App\Http\Requests;

use App\Services\FinanceTreasury;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTreasuryWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(BusinessPermissions::class)->allows($this->attributes->get('membership'), 'finance.documents.create');
    }

    protected function prepareForValidation(): void
    {
        foreach (['' => 'account_choice', 'destination_' => 'destination_account_choice'] as $prefix => $field) {
            $choice = $this->input($field);
            if (is_string($choice) && preg_match('/^(bank|cash):([1-9][0-9]*)$/D', $choice, $match)) {
                $this->merge([$prefix.($match[1] === 'bank' ? 'bank_profile_id' : 'cash_custody_id') => (int) $match[2]]);
            } elseif ($choice === 'legacy:cash') {
                $this->merge([$prefix.'account_key' => 'cash']);
            }
        }
    }

    public function rules(): array
    {
        $business = $this->attributes->get('activeBusiness');
        $money = ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'];
        $id = ['nullable', 'integer', 'min:1'];

        return ['account_choice' => ['nullable', 'regex:/^(?:(?:bank|cash):[1-9][0-9]*|legacy:cash)$/D'], 'destination_account_choice' => ['nullable', 'regex:/^(?:(?:bank|cash):[1-9][0-9]*|legacy:cash)$/D'], 'kind' => ['required', Rule::in(array_keys(FinanceTreasury::KINDS))], 'event_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($business->timezone)->toDateString()], 'currency' => ['required', app(BusinessCurrencies::class)->rule($business)], 'amount' => $money, 'exchange_rate_id' => $id, 'bank_profile_id' => $id, 'cash_custody_id' => $id, 'account_key' => ['nullable', 'in:cash,bank'], 'custody_id' => $id, 'destination_currency' => ['required_if:kind,exchange', 'nullable', app(BusinessCurrencies::class)->rule($business)], 'destination_bank_profile_id' => $id, 'destination_cash_custody_id' => $id, 'destination_account_key' => ['nullable', 'in:cash,bank'], 'destination_exchange_rate_id' => $id, 'received_amount' => ['required_if:kind,exchange', 'nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'fee_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'request_key' => ['required', 'uuid']];
    }
}
