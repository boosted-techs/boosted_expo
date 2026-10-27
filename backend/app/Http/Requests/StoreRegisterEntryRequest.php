<?php

namespace App\Http\Requests;

use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegisterEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(BusinessPermissions::class)->allows($this->attributes->get('membership'), 'finance.documents.create');
    }

    public function rules(): array
    {
        return [
            'cash_custody_id' => ['nullable', 'integer', 'min:1'], 'cash_witness' => ['nullable', 'string', 'min:10', 'max:1000'], 'due_on' => ['nullable', 'date_format:Y-m-d'],
            'fees_treatment' => ['nullable', 'in:paid,accrued'], 'fee_policy' => ['nullable', 'in:expense,straight_line,reviewed_schedule'], 'amortization_start_on' => ['nullable', 'date_format:Y-m-d'], 'amortization_months' => ['nullable', 'integer', 'between:1,600'],
            'asset_id' => ['nullable', 'integer', 'min:1'], 'loan_id' => ['nullable', 'integer', 'min:1'], 'instalment_id' => ['nullable', 'integer', 'min:1'],
            'original_entry_id' => ['nullable', 'integer', 'min:1'], 'return_bank_line_id' => ['nullable', 'integer', 'min:1'],
            'event_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($this->attributes->get('activeBusiness')->timezone)->toDateString()],
            'currency' => ['nullable', app(BusinessCurrencies::class)->rule($this->attributes->get('activeBusiness'))],
            'amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'],
            'principal' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'],
            'interest' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'],
            'fees' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'],
            'opening_accumulated' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'],
            'paid' => ['nullable', 'boolean'], 'interest_treatment' => ['nullable', 'in:paid,accrued'],
            'depreciation_month' => ['nullable', 'date_format:Y-m'],
            'exchange_rate_id' => ['nullable', 'integer', 'min:1'], 'bank_profile_id' => ['nullable', 'integer', 'min:1'], 'cash_account_key' => ['nullable', Rule::in(['cash', 'bank'])],
            'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid'],
        ];
    }
}
