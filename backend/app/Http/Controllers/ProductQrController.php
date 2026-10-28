<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\PlatformAccess;
use App\Services\ProductQrCodes;
use App\Support\BusinessPermissions;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ProductQrController extends Controller
{
    private function product(Request $request, int $id, string $permission): Product
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);

        return Product::query()->where('business_id', $request->attributes->get('activeBusiness')->id)->findOrFail($id);
    }

    public function update(Request $request, int $product, ProductQrCodes $codes): RedirectResponse|JsonResponse
    {
        $product = $this->product($request, $product, 'catalogue.manage');
        $input = $request->validate(['public_enabled' => ['required_without:access', 'boolean'], 'access' => ['nullable', Rule::in(['hidden', 'public', 'pin'])], 'pin' => ['nullable', 'string', 'min:4', 'max:64']]);
        DB::transaction(function () use ($product, $codes, $input, $request): void {
            $qr = $codes->ensure($product);
            $qr = $qr->newQuery()->lockForUpdate()->findOrFail($qr->id);
            $access = $input['access'] ?? (! empty($input['public_enabled']) ? 'public' : 'hidden');
            if ($access === 'pin' && ! $qr->access_pin_hash && empty($input['pin'])) {
                throw ValidationException::withMessages(['pin' => 'Set a secret PIN for protected access.']);
            }
            $before = ['public_enabled' => $qr->public_enabled, 'protected' => (bool) $qr->access_pin_hash];
            $qr->update(['public_enabled' => $access !== 'hidden', 'access_pin_hash' => $access === 'pin' ? (! empty($input['pin']) ? Hash::make($input['pin']) : $qr->access_pin_hash) : null, 'access_version' => $qr->access_version + 1]);
            app(PlatformAccess::class)->audit($product->business_id, $request->user()->id, 'product.qr.access', 'QR access updated.', $before, ['access' => $access, 'product_id' => $product->id]);
        });

        return $request->expectsJson() ? response()->json(['message' => 'QR access saved. Previous PIN sessions have been reset.']) : back()->with('status', 'Product QR access saved.');
    }

    public function png(Request $request, int $product, ProductQrCodes $codes): Response
    {
        $product = $this->product($request, $product, 'catalogue.view');
        $record = $codes->ensure($product);
        $image = (new PngWriter)->write(new QrCode(data: route('qr.public', $record->public_token), errorCorrectionLevel: ErrorCorrectionLevel::High, size: 360, margin: 12));

        return response($image->getString(), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
