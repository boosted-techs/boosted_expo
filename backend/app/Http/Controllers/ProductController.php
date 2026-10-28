<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCode;
use App\Services\PlatformAccess;
use App\Services\ProductQrCodes;
use App\Services\ProductUnitRules;
use App\Support\BusinessPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                'nullable', 'string', 'min:2', 'max:80',
                Rule::unique('products', 'sku')->where('business_id', $businessId)->ignore($product?->id),
            ],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:3000'],
            'base_unit' => ['required', Rule::in(['kg', 'g', 'tonne', 'pcs', 'box', 'crate', 'bunch', 'sack', 'litre'])],
            'active' => ['sometimes', 'boolean'],
            'reorder_point' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999'],
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
                ->orWhere('sku', 'like', '%'.$search.'%')->orWhereHas('codes', fn ($q) => $q->where('business_id', $business->id)->where('active', true)->where('code', 'like', '%'.$search.'%'))))
            ->orderBy('name')->workspacePaginate(20)->withQueryString();
        $canManage = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'catalogue.manage');

        return view('products.index', compact('products', 'search', 'canManage'));
    }

    public function create(Request $request): View
    {
        $this->authorizePermission($request, 'catalogue.manage');

        return view('products.edit', ['product' => new Product(['base_unit' => 'kg', 'active' => true]), 'activity' => 'details']);
    }

    public function codes(Request $request, int $product): View
    {
        $this->authorizePermission($request, 'catalogue.manage');
        $product = $this->productForBusiness($request, $product);
        $codes = $product->codes()->with('customer')->orderBy('code')->workspacePaginate(20);
        $customers = Customer::query()->where('business_id', $product->business_id)->where('active', true)->orderBy('name')->get();

        return view('products.codes', compact('product', 'codes', 'customers'));
    }

    public function saveCode(Request $request, int $product): RedirectResponse|JsonResponse
    {
        $this->authorizePermission($request, 'catalogue.manage');
        $product = $this->productForBusiness($request, $product);
        $data = $request->validate(['code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9 ._\/-]+$/'], 'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $product->business_id)], 'country_code' => ['nullable', Rule::in(array_keys(config('countries')))], 'description' => ['nullable', 'string', 'max:160'], 'code_id' => ['nullable', 'integer'], 'active' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($product, $data, $request): void {
            Business::query()->lockForUpdate()->findOrFail($product->business_id);
            $code = empty($data['code_id']) ? new ProductCode(['business_id' => $product->business_id, 'product_id' => $product->id]) : $product->codes()->where('business_id', $product->business_id)->lockForUpdate()->findOrFail($data['code_id']);
            $duplicate = ProductCode::query()->where('business_id', $product->business_id)->where('product_id', $product->id)->where('customer_id', $data['customer_id'] ?? null)->where('country_code', $data['country_code'] ?? null)->where('code', $data['code'])->when($code->exists, fn ($q) => $q->where('id', '!=', $code->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['code' => 'This code is already attached for this customer and destination.']);
            }
            $before = $code->getAttributes();
            $code->fill(collect($data)->except('code_id')->all());
            $code->save();
            app(PlatformAccess::class)->audit($product->business_id, $request->user()->id, 'product.code.saved', 'Product code updated.', $before, $code->getAttributes());
        });

        return $request->expectsJson() ? response()->json(['message' => 'Product code saved.']) : back()->with('status', 'Product code saved.');
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorizePermission($request, 'catalogue.manage');
        $data = $this->validated($request);
        $data['business_id'] = $request->attributes->get('activeBusiness')->id;
        $data['active'] = true;
        $product = DB::transaction(function () use ($data, $request) {
            Business::query()->lockForUpdate()->findOrFail($data['business_id']);
            if (empty($data['sku'])) {
                $base = Str::upper(Str::slug($data['name']));
                $base = mb_substr($base ?: 'PRODUCT', 0, 65);
                $sku = $base;
                $suffix = 2;
                while (Product::query()->where('business_id', $data['business_id'])->where('sku', $sku)->exists()) {
                    $sku = $base.'-'.$suffix++;
                }
                $data['sku'] = $sku;
            }
            $product = Product::create($data);
            app(PlatformAccess::class)->audit($product->business_id, $request->user()->id, 'product.created', 'Product added.', [], $product->getAttributes());

            return $product;
        });
        app(ProductQrCodes::class)->ensure($product);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Product added. Refresh the catalogue when you are ready.']);
        }

        return redirect()->route('products.index')->with('status', 'Product added to your catalogue.');
    }

    public function edit(Request $request, int $product): View
    {
        $this->authorizePermission($request, 'catalogue.manage');
        $product = $this->productForBusiness($request, $product);
        $qr = app(ProductQrCodes::class)->ensure($product);

        $activity = $request->validate(['activity' => ['nullable', Rule::in(['details', 'photos', 'qr'])]])['activity'] ?? 'details';
        $product->load('images');

        return view('products.edit', compact('product', 'qr', 'activity'));
    }

    public function update(Request $request, int $product): RedirectResponse|JsonResponse
    {
        $this->authorizePermission($request, 'catalogue.manage');
        $product = $this->productForBusiness($request, $product);
        $data = $this->validated($request, $product);
        $data['active'] = $request->boolean('active');
        if (empty($data['sku'])) {
            $data['sku'] = $product->sku;
        }
        DB::transaction(function () use ($product, $data, $request) {
            $product = Product::query()->where('business_id', $product->business_id)
                ->lockForUpdate()->findOrFail($product->id);
            app(ProductUnitRules::class)->ensureUnchangedWhenReferenced($product, $data['base_unit']);
            $before = $product->getAttributes();
            $product->update($data);
            app(PlatformAccess::class)->audit($product->business_id, $request->user()->id, 'product.updated', 'Product updated.', $before, $product->getAttributes());
        });

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Product saved. Refresh the catalogue when you are ready.']);
        }

        return redirect()->route('products.index')->with('status', 'Product updated.');
    }
}
