<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerOrderingLink;
use App\Models\Product;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
    }

    private function fields(Request $request, ?Customer $customer = null): array
    {
        $request->merge([
            'customer_code' => strtoupper(trim((string) $request->input('customer_code'))),
            'country_code' => strtoupper(trim((string) $request->input('country_code'))),
            'currency' => strtoupper(trim((string) $request->input('currency'))),
        ]);

        return $request->validate([
            'customer_code' => ['required', 'alpha_dash', 'max:50',
                Rule::unique('customers', 'customer_code')
                    ->where('business_id', $request->attributes->get('activeBusiness')->id)->ignore($customer?->id)],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'country_code' => ['required', 'alpha', 'size:2'],
            'city' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', 'string', Rule::in(array_values(array_unique([
                ...app(BusinessCurrencies::class)->enabled($request->attributes->get('activeBusiness')),
                ...($customer && array_key_exists($customer->currency, app(BusinessCurrencies::class)->catalogue()) ? [$customer->currency] : []),
            ])))],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'customers.view');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $customers = Customer::query()->where('business_id', $businessId)->orderBy('name')->workspacePaginate(20);
        $canManage = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'customers.manage');

        $orderingProducts = Product::query()->where('business_id', $businessId)->where('active', true)->orderBy('name')->get();
        $orderingLinks = CustomerOrderingLink::query()->where('business_id', $businessId)->whereIn('customer_id', $customers->modelKeys())->latest('id')->get();

        return view('customers.index', compact('customers', 'canManage', 'orderingProducts', 'orderingLinks'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->check($request, 'customers.manage');
        $data = $this->fields($request);
        $data['business_id'] = $request->attributes->get('activeBusiness')->id;
        $data['active'] = true;
        Customer::create($data);

        return back()->with('status', 'Customer added.');
    }

    public function edit(Request $request, int $customer): View
    {
        $this->check($request, 'customers.manage');
        $customer = Customer::query()->where('business_id', $request->attributes->get('activeBusiness')->id)->findOrFail($customer);

        return view('customers.edit', compact('customer'));
    }

    public function update(Request $request, int $customer): RedirectResponse
    {
        $this->check($request, 'customers.manage');
        $customer = Customer::query()->where('business_id', $request->attributes->get('activeBusiness')->id)->findOrFail($customer);
        $data = $this->fields($request, $customer);
        $data['active'] = $request->boolean('active');
        $customer->update($data);

        return redirect()->route('customers.index')->with('status', 'Customer updated.');
    }
}
