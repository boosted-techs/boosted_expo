<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
    }

    private function fields(Request $request, ?Supplier $supplier = null): array
    {
        $request->merge(['supplier_code' => strtoupper(trim((string) $request->input('supplier_code')))]);

        return $request->validate([
            'supplier_code' => ['required', 'alpha_dash', 'max:50',
                Rule::unique('suppliers', 'supplier_code')
                    ->where('business_id', $request->attributes->get('activeBusiness')->id)->ignore($supplier?->id)],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'kind' => ['required', Rule::in(['company', 'individual'])],
            'market_location' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'suppliers.view');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $suppliers = Supplier::query()->where('business_id', $businessId)->orderBy('name')->workspacePaginate(20);
        $canManage = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'suppliers.manage');

        return view('suppliers.index', compact('suppliers', 'canManage'));
    }

    public function create(Request $request): View
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'suppliers.manage'), 403);
        $canManage = true;

        return view('suppliers.create', compact('canManage'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->check($request, 'suppliers.manage');
        $data = $this->fields($request);
        $data['business_id'] = $request->attributes->get('activeBusiness')->id;
        $data['active'] = true;
        Supplier::create($data);

        return back()->with('status', 'Supplier added.');
    }

    public function edit(Request $request, int $supplier): View
    {
        $this->check($request, 'suppliers.manage');
        $supplier = Supplier::query()->where('business_id', $request->attributes->get('activeBusiness')->id)->findOrFail($supplier);

        return view('suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, int $supplier): RedirectResponse
    {
        $this->check($request, 'suppliers.manage');
        $supplier = Supplier::query()->where('business_id', $request->attributes->get('activeBusiness')->id)->findOrFail($supplier);
        $data = $this->fields($request, $supplier);
        $data['active'] = $request->boolean('active');
        $supplier->update($data);

        return redirect()->route('suppliers.index')->with('status', 'Supplier updated.');
    }
}
