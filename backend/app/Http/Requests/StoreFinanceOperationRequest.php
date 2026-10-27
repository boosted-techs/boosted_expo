<?php

namespace App\Http\Requests;

use App\Services\FinanceOperations;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinanceOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(BusinessPermissions::class)->allows($this->attributes->get('membership'), 'finance.documents.create');
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('allocations'))) {
            $this->merge(['allocations' => array_values(array_filter($this->input('allocations'), fn ($row) => ! is_array($row) || collect($row)->contains(fn ($value) => filled($value))))]);
        }
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(FinanceOperations::KINDS))],
            'currency' => ['required', app(BusinessCurrencies::class)->rule($this->attributes->get('activeBusiness'))],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'],
            'event_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($this->attributes->get('activeBusiness')->timezone)->toDateString()],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:event_on'],
            'description' => ['required', 'string', 'min:5', 'max:250'],
            'external_reference' => ['required', 'string', 'min:3', 'max:150'],
            'category' => ['nullable', Rule::in(['rent', 'utilities', 'packing_material', 'transport', 'handling', 'repairs', 'communication', 'professional_fees', 'staff_welfare', 'other'])],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'advance_request_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'owner_user_id' => ['nullable', 'integer', 'min:1'],
            'staff_person_id' => ['nullable', 'integer', 'min:1'],
            'sales_order_id' => ['nullable', 'integer', 'min:1'],
            'market_trip_id' => ['nullable', 'integer', 'min:1'],
            'shipment_id' => ['nullable', 'integer', 'min:1'],
            'commercial_invoice_id' => ['nullable', 'integer', 'min:1'],
            'supplier_bill_id' => ['nullable', 'integer', 'min:1'],
            'cash_account_key' => ['nullable', Rule::in(['cash', 'bank'])],
            'bank_profile_id' => ['nullable', 'integer', 'min:1'],
            'cash_custody_id' => ['nullable', 'integer', 'min:1'],
            'destination_account_key' => ['nullable', Rule::in(['cash', 'bank'])],
            'destination_bank_profile_id' => ['nullable', 'integer', 'min:1'],
            'exchange_rate_id' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'allocations' => ['nullable', 'array', 'max:100'],
            'allocations.*.sales_order_id' => ['required', 'integer', 'min:1'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'],
            'allocations.*.basis' => ['required', 'string', 'min:5', 'max:250'],
        ];
    }
}
