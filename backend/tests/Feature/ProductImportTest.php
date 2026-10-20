<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\CatalogueImport;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $code): array
    {
        $business = Business::create([
            'code' => $code, 'name' => $code.' Business',
            'country_code' => 'UG', 'base_currency' => 'UGX',
            'timezone' => 'Africa/Kampala', 'default_qr_level' => 'batch',
        ]);
        $user = User::factory()->create();
        BusinessMembership::create([
            'business_id' => $business->id, 'user_id' => $user->id,
            'role' => 'owner', 'active' => true,
        ]);

        return [$user, $business];
    }

    private function csv(string $text): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', $text);
    }

    public function test_product_import_requires_preview_then_confirmation(): void
    {
        [$user, $business] = $this->owner('FIRST');
        $this->actingAs($user)->post(route('products.import.preview'), [
            'file' => $this->csv("Product Code,Product Name,Description,Unit,Status\nAVO-1,Avocado,Fresh hass,kgs,Active\nRED-2,Red Beans,Beans,kg,Active\n"),
        ])->assertOk()->assertSee('Review products');

        $this->assertDatabaseCount('products', 0);
        $import = CatalogueImport::firstOrFail();
        $this->assertSame(2, $import->row_count);
        $this->post(route('products.import.apply', $import->id))->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', [
            'business_id' => $business->id, 'sku' => 'AVO-1', 'base_unit' => 'kg',
        ]);
        $this->assertDatabaseCount('products', 2);
        $this->post(route('products.import.apply', $import->id))->assertNotFound();
    }

    public function test_product_import_rejects_formulas_and_duplicate_sku_names(): void
    {
        [$user] = $this->owner('FIRST');
        $this->actingAs($user)->post(route('products.import.preview'), [
            'file' => $this->csv("SKU,Product,Unit\nBAD-1,=HYPERLINK(\"\"https://example.com\"\"),kg\n"),
        ])->assertSessionHasErrors('file');
        $this->post(route('products.import.preview'), [
            'file' => $this->csv("SKU,Product,Unit\nA-1,Avocado,kg\nA-1,Ginger,kg\n"),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_preview_cannot_be_confirmed_from_another_company(): void
    {
        [$ownerOne] = $this->owner('FIRST');
        [$ownerTwo, $second] = $this->owner('SECOND');

        $this->actingAs($ownerOne)->post(route('products.import.preview'), [
            'file' => $this->csv("SKU,Product,Unit\nTST-1,Fresh Matooke,kg\n"),
        ])->assertOk();
        $import = CatalogueImport::firstOrFail();

        $this->actingAs($ownerTwo)->withSession(['active_business_id' => $second->id])
            ->post(route('products.import.apply', $import->id))->assertNotFound();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_excel_export_contains_only_current_business_products(): void
    {
        [$ownerOne, $first] = $this->owner('FIRST');
        [, $second] = $this->owner('SECOND');
        Product::create(['business_id'=>$first->id, 'sku'=>'A-1', 'name'=>'Avocado', 'base_unit'=>'kg']);
        Product::create(['business_id'=>$second->id, 'sku'=>'B-1', 'name'=>'Private Beans', 'base_unit'=>'kg']);

        $response = $this->actingAs($ownerOne)->get(route('products.export'))->assertOk();
        $bytes = $response->streamedContent();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_').'.xlsx';
        file_put_contents($tmp, $bytes);
        try {
            $sheet = IOFactory::load($tmp)->getActiveSheet();
            $this->assertSame('A-1', $sheet->getCell('A2')->getValue());
            $this->assertNull($sheet->getCell('A3')->getValue());
        } finally {
            @unlink($tmp);
        }
    }
}
