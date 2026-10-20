<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImages;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProductImageController extends Controller
{
    private function permitted(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
    }

    private function product(Request $request, int $id): Product
    {
        return Product::query()->where('business_id', $request->attributes->get('activeBusiness')->id)->findOrFail($id);
    }

    private function image(Product $product, int $id): ProductImage
    {
        return ProductImage::query()->where('business_id', $product->business_id)
            ->where('product_id', $product->id)->findOrFail($id);
    }

    public function store(Request $request, int $product, ProductImages $images): RedirectResponse
    {
        $this->permitted($request, 'catalogue.manage');
        $product = $this->product($request, $product);
        $request->validate(['photo' => ['required','image','mimes:jpeg,jpg,png,webp','max:4096']]);
        abort_if($product->images()->count() >= 4, 422, 'This product already has four images.');
        $images->store($product, $request->file('photo'));

        return back()->with('status', 'Product photograph uploaded.');
    }

    public function show(Request $request, int $product, int $image, string $size = 'thumb'): Response
    {
        $this->permitted($request, 'catalogue.view');
        abort_unless(in_array($size, ['thumb','full'], true), 404);
        $product = $this->product($request, $product);
        $record = $this->image($product, $image);
        $bytes = Storage::disk('r2')->get($size === 'thumb' ? $record->thumbnail_key : $record->original_key);

        return response($bytes, 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, int $product, int $image): RedirectResponse
    {
        $this->permitted($request, 'catalogue.manage');
        $product = $this->product($request, $product);
        $record = $this->image($product, $image);
        Storage::disk('r2')->delete([$record->original_key, $record->thumbnail_key]);
        $record->delete();

        return back()->with('status', 'Product photograph removed.');
    }
}