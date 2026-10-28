<?php

namespace App\Http\Controllers;

use App\Models\QrIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PublicQrController extends Controller
{
    private function record(string $token): QrIdentity
    {
        return QrIdentity::query()->where('public_token', $token)
            ->where('public_enabled', true)
            ->whereHas('business', fn ($query) => $query->where('is_active', true))
            ->with('business')->firstOrFail();
    }

    private function unlocked(Request $request, QrIdentity $qr): bool
    {
        $grant = $request->session()->get('qr-access.'.$qr->id, []);

        return ! $qr->access_pin_hash || (($grant['version'] ?? null) === $qr->access_version && ($grant['until'] ?? 0) > now()->timestamp);
    }

    public function unlock(Request $request, string $token): RedirectResponse
    {
        $qr = $this->record($token);
        $input = $request->validate(['pin' => ['required', 'string', 'max:64']]);
        if (! $qr->access_pin_hash || ! Hash::check($input['pin'], $qr->access_pin_hash)) {
            throw ValidationException::withMessages(['pin' => 'The PIN is incorrect.']);
        }
        $request->session()->put('qr-access.'.$qr->id, ['version' => $qr->access_version, 'until' => now()->addHours(2)->timestamp]);

        return redirect()->route('qr.public', $token);
    }

    public function show(Request $request, string $token): View
    {
        $qr = $this->record($token);
        if (! $this->unlocked($request, $qr)) {
            return view('qr.unlock', ['qr' => $qr, 'business' => $qr->business]);
        }
        $business = $qr->business;
        $fields = [];

        if ($qr->level === 'product') {
            $product = DB::table('products')->where('business_id', $business->id)
                ->where('id', $qr->record_id)->where('active', true)->first();
            abort_unless($product, 404);
            $title = $product->name;
            $description = $product->description;
            $fields = ['Product code' => $product->sku, 'Unit' => $product->base_unit];
            $hasPhoto = DB::table('product_images')->where('business_id', $business->id)
                ->where('product_id', $product->id)->exists();
        } elseif ($qr->level === 'batch') {
            $batch = DB::table('stock_batches')->where('stock_batches.business_id', $business->id)
                ->where('stock_batches.id', $qr->record_id)
                ->join('products', function ($join) {
                    $join->on('products.id', '=', 'stock_batches.product_id')
                        ->on('products.business_id', '=', 'stock_batches.business_id');
                })->select('stock_batches.batch_code', 'products.name')->first();
            abort_unless($batch, 404);
            $title = $batch->name;
            $description = 'Produce batch information';
            $fields = ['Batch reference' => $batch->batch_code];
            $hasPhoto = false;
        } else {
            $carton = DB::table('cartons')->where('business_id', $business->id)
                ->where('id', $qr->record_id)->first();
            abort_unless($carton, 404);
            $title = 'Export carton';
            $description = 'Carton identification';
            $fields = [
                'Carton reference' => $carton->carton_code,
                'Net weight' => $carton->net_weight_kg === null ? 'Not recorded' : $carton->net_weight_kg.' kg',
            ];
            $hasPhoto = false;
        }

        return view('qr.public', compact('qr', 'business', 'title', 'description', 'fields', 'hasPhoto'));
    }

    public function photo(Request $request, string $token): Response
    {
        $qr = $this->record($token);
        abort_unless($this->unlocked($request, $qr), 403);
        abort_unless($qr->level === 'product', 404);
        $product = DB::table('products')->where('business_id', $qr->business_id)
            ->where('id', $qr->record_id)->where('active', true)->first();
        abort_unless($product, 404);
        $photo = DB::table('product_images')->where('business_id', $qr->business_id)
            ->where('product_id', $qr->record_id)->orderBy('id')->first();
        abort_unless($photo, 404);

        return response(Storage::disk($photo->storageDisk())->get($photo->thumbnail_key), 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
