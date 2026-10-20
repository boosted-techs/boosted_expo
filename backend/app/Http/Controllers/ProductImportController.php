<?php

namespace App\Http\Controllers;

use App\Models\CatalogueImport;
use App\Models\Product;
use App\Services\ProductSpreadsheet;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    private function ensure(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $request): View
    {
        $this->ensure($request, 'catalogue.manage');

        return view('products.import');
    }

    public function preview(Request $request, ProductSpreadsheet $parser): View
    {
        $this->ensure($request, 'catalogue.manage');
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:3072']]);
        $parsed = $parser->parse($request->file('file'));
        if (count($parsed['issues']) > 0) {
            throw ValidationException::withMessages(['file' => implode(' ', array_slice($parsed['issues'], 0, 8))]);
        }

        $business = $request->attributes->get('activeBusiness');
        $existing = Product::query()->where('business_id', $business->id)
            ->whereIn('sku', array_column($parsed['rows'], 'sku'))->pluck('sku')->all();
        $existingSet = array_flip($existing);
        $updates = count(array_filter($parsed['rows'], fn ($r) => isset($existingSet[$r['sku']])));
        $import = CatalogueImport::create([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'user_id' => $request->user()->id,
            'rows' => $parsed['rows'],
            'row_count' => count($parsed['rows']),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
        ]);

        return view('products.import-preview', compact('import', 'parsed', 'updates'));
    }

    public function apply(Request $request, string $import): RedirectResponse
    {
        $this->ensure($request, 'catalogue.manage');

        DB::transaction(function () use ($request, $import) {
            $staged = CatalogueImport::query()
                ->where('id', $import)
                ->where('business_id', $request->attributes->get('activeBusiness')->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($staged->status === 'pending' && $staged->expires_at->isFuture(), 404);
            foreach ($staged->rows as $row) {
                $values = [
                    'name' => $row['name'],
                    'base_unit' => $row['base_unit'],
                ];
                if ($row['description'] !== null) $values['description'] = $row['description'];
                if ($row['active'] !== null) $values['active'] = $row['active'];
                $product = Product::updateOrCreate(
                    ['business_id' => $staged->business_id, 'sku' => $row['sku']],
                    $values,
                );
                app(\App\Services\ProductQrCodes::class)->ensure($product);
            }
            $staged->update(['status' => 'applied', 'applied_at' => now()]);
        });

        return redirect()->route('products.index')->with('status', 'Product import completed.');
    }

    public function export(Request $request, ProductSpreadsheet $parser): StreamedResponse
    {
        $this->ensure($request, 'catalogue.view');
        $business = $request->attributes->get('activeBusiness');
        $products = Product::query()->where('business_id', $business->id)->orderBy('name')->get();
        $book = $parser->workbook($products);

        return response()->streamDownload(
            fn () => $parser->writeToOutput($book),
            $business->code.'_products_'.now()->format('Ymd').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function template(Request $request, ProductSpreadsheet $parser): StreamedResponse
    {
        $this->ensure($request, 'catalogue.manage');
        $book = $parser->workbook([]);

        return response()->streamDownload(
            fn () => $parser->writeToOutput($book),
            'boosted_export_product_template.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}