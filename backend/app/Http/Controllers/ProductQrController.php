<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductQrCodes;
use App\Support\BusinessPermissions;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductQrController extends Controller
{
    private function product(Request $request, int $id, string $permission): Product
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);

        return Product::query()->where('business_id', $request->attributes->get('activeBusiness')->id)->findOrFail($id);
    }

    public function update(Request $request, int $product, ProductQrCodes $codes): RedirectResponse
    {
        $product = $this->product($request, $product, 'catalogue.manage');
        $input = $request->validate(['public_enabled' => ['required', 'boolean']]);
        $codes->ensure($product)->update(['public_enabled' => (bool) $input['public_enabled']]);

        return back()->with('status', $input['public_enabled'] ? 'Public product page enabled.' : 'Public product page disabled.');
    }

    public function png(Request $request, int $product, ProductQrCodes $codes): Response
    {
        $product = $this->product($request, $product, 'catalogue.view');
        $record = $codes->ensure($product);
        $url = route('qr.public', $record->public_token);
        $image = (new PngWriter())->write(new QrCode(
            data: $url, errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 360, margin: 12,
        ));

        return response($image->getString(), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}