<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless(
            app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission),
            403,
        );
    }

    private function productForBusiness(Request $request, int $id): Product
    {
        return Product::query()
            ->where('business_id', $request->attributes->get('activeBusiness')->id)
            ->findOrFail($id);
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $request->merge(['sku' => strtoupper(trim((string) $request->input('sku')))]);
        $businessId = $request->attributes->get('activeBusiness')->id;

        return $request->validate([
            'sku' => [
                'required', 'string', 'min:2', 'max:80',
                Rule::unique('products', 'sku')->where('business_id', $businessId)->ignore($product?->id),
            ],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:3000'],
            'base_unit' => ['required', Rule::in(['kg', 'g', 'tonne', 'pcs', 'box', 'crate', 'bunch', 'sack', 'litre'])],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    public function index(Request $request): View
    {
        $this->authorizePermission($request, 'catalogue.view');
        $business = $request->attributes->get('activeBusiness');
        $search = trim((string) $request->query('search', ''));
        $products = Product::query()->with('images')->where('business_id', $business->id)
            ->when($search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('sku', 'like', '%'.$search.'%')))
            ->orderBy('name')->paginate(20)->withQueryString();
        $canManage = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'catalogue.manage');

        return view('products.index', compact('products', 'search', 'canManage'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'catalogue.manage');
        $data = $this->validated($request);
        $data['business_id'] = $request->attributes->get('activeBusiness')->id;
        $data['active'] = true;
        $product = Product::create($data);
        app(\App\Services\ProductQrCodes::class)->ensure($product);

        return redirect()->route('products.index')->with('status', 'Product added to your catalogue.');
    }

    public function edit(Request $request, int $product): View
    {
        $this->authorizePermission($request, 'catalogue.manage');
        $product = $this->productForBusiness($request, $product);
        $qr = app(\App\Services\ProductQrCodes::class)->ensure($product);

        return view('products.edit', compact('product', 'qr'));
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        $this->authorizePermission($request, 'catalogue.manage');
        $product = $this->productForBusiness($request, $product);
        $data = $this->validated($request, $product);
        $data['active'] = $request->boolean('active');
        $product->update($data);

        return redirect()->route('products.index')->with('status', 'Product updated.');
    }
}