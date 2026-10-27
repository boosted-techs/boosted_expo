<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\CartonContent;
use App\Models\LabelGeneration;
use App\Models\LabelTemplate;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\QrIdentity;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\ZebraLabelRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZebraLabelsTest extends TestCase
{
    use RefreshDatabase;

    private function setupLabelData(string $code = 'EXP'): array
    {
        $business = Business::create([
            'code' => $code, 'name' => 'Avocado ^XA Export Ltd', 'country_code' => 'UG',
            'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton',
        ]);
        $owner = User::factory()->create();
        BusinessMembership::create([
            'business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'owner', 'active' => true,
        ]);
        $product = Product::create([
            'business_id' => $business->id, 'sku' => 'AVO-01', 'name' => 'Avocado ^XZ Deluxe', 'base_unit' => 'kg',
        ]);
        $batch = StockBatch::create([
            'business_id' => $business->id, 'product_id' => $product->id,
            'batch_code' => 'BT-CROP-1', 'source_reference' => 'UG/FARM-1', 'received_on' => '2026-10-20',
        ]);
        $job = PackingJob::create([
            'business_id' => $business->id, 'reference' => 'PK-'.Str::ulid(),
            'product_id' => $product->id, 'status' => 'completed', 'created_by' => $owner->id,
        ]);
        $carton = Carton::create([
            'business_id' => $business->id, 'carton_code' => 'CTN-0001',
            'net_weight_kg' => '15', 'gross_weight_kg' => '16',
            'packing_job_id' => $job->id, 'status' => 'packed', 'grade' => 'A',
            'packaging_type' => '15 kg box',
        ]);
        CartonContent::create([
            'business_id' => $business->id, 'batch_id' => $batch->id,
            'carton_id' => $carton->id, 'quantity' => '15',
        ]);

        return [$business, $owner, $product, $batch, $carton];
    }

    private function templateData(array $changes = []): array
    {
        return array_merge([
            'name' => 'Standard carton sticker',
            'width_mm' => 100, 'height_mm' => 50, 'dpi' => 203,
            'qr_level' => 'none', 'qr_visibility' => 'public', 'include_barcode' => '1',
            'visible_fields' => ['company', 'product', 'carton', 'net'],
        ], $changes);
    }

    public function test_barcode_only_label_and_original_history_survive_template_update(): void
    {
        [$b,$owner,$product,$batch,$carton] = $this->setupLabelData();
        $this->actingAs($owner)->post(route('labels.store'), $this->templateData([
            'name' => 'Barcode only', 'visible_fields' => [],
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $template = LabelTemplate::firstOrFail();
        $this->assertSame([], $template->visible_fields);
        $this->get(route('labels.index'))->assertOk()->assertSee('Barcode only');
        $result = $this->post(route('labels.generate', [$carton->id, $template->id]))->assertOk();
        $zpl = $result->streamedContent();
        $this->assertStringStartsWith("^XA\n", $zpl);
        $this->assertStringContainsString('^BCN,', $zpl);
        $this->assertStringNotContainsString('^BQN,', $zpl);
        $this->assertStringContainsString('^FH_^FD', $zpl);
        $this->assertStringNotContainsString('^XA Export Ltd', $zpl);
        $entry = LabelGeneration::firstOrFail();
        $this->assertSame(hash('sha256', $zpl), $entry->zpl_sha256);
        $this->assertSame($zpl, $entry->zpl_snapshot);
        $this->put(route('labels.update', $template->id), $this->templateData([
            'name' => 'Barcode only', 'visible_fields' => ['company'], 'qr_level' => 'none',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $template->fresh()->version);
        $this->post(route('labels.history.download', $entry->id))->assertOk();
        $this->assertSame($zpl, $this->post(route('labels.history.download', $entry->id))->streamedContent());
    }

    public function test_qr_only_label_is_private_until_product_is_published(): void
    {
        [$b,$owner,$product,$batch,$carton] = $this->setupLabelData();
        $this->actingAs($owner)->post(route('labels.store'), $this->templateData([
            'name' => 'Product QR only', 'visible_fields' => [], 'include_barcode' => '0', 'qr_level' => 'product',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $template = LabelTemplate::firstOrFail();
        $this->post(route('labels.generate', [$carton->id, $template->id]))
            ->assertSessionHasErrors('label');
        $this->assertDatabaseCount('label_generations', 0);

        QrIdentity::firstOrCreate(
            ['business_id' => $b->id, 'level' => 'product', 'record_id' => $product->id],
            ['public_token' => (string) Str::uuid(), 'public_enabled' => false],
        )->update(['public_enabled' => true]);

        $result = $this->post(route('labels.generate', [$carton->id, $template->id]))->assertOk();
        $zpl = $result->streamedContent();
        $this->assertStringContainsString('^BQN,', $zpl);
        $this->assertStringContainsString('^FH_^FDLA,', $zpl);
        $this->assertStringNotContainsString('^BCN,', $zpl);
        $this->assertDatabaseCount('label_generations', 1);
        $this->get(route('labels.history'))->assertOk()->assertSee('Product QR only');
    }

    public function test_mixed_batch_carton_cannot_generate_single_batch_qr(): void
    {
        [$b,$owner,$product,$batch,$carton] = $this->setupLabelData();
        $batch2 = StockBatch::create([
            'business_id' => $b->id, 'product_id' => $product->id,
            'batch_code' => 'BT-SECOND', 'received_on' => '2026-10-20',
        ]);
        CartonContent::create([
            'business_id' => $b->id, 'batch_id' => $batch2->id,
            'carton_id' => $carton->id, 'quantity' => '2',
        ]);
        $this->actingAs($owner)->post(route('labels.store'), $this->templateData([
            'name' => 'Batch QR', 'qr_level' => 'batch',
        ]))->assertRedirect();
        $template = LabelTemplate::firstOrFail();
        $this->post(route('labels.generate', [$carton->id, $template->id]))
            ->assertSessionHasErrors('label');
        $this->assertDatabaseCount('label_generations', 0);
    }

    public function test_label_field_injection_is_encoded_and_other_business_cannot_access_carton(): void
    {
        [$b,$owner,$product,$batch,$carton] = $this->setupLabelData();
        $this->actingAs($owner)->post(route('labels.store'), $this->templateData())->assertRedirect();
        $template = LabelTemplate::firstOrFail();
        $zpl = $this->post(route('labels.generate', [$carton->id, $template->id]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('^XZ Deluxe', $zpl);
        $this->assertStringNotContainsString('Avocado ^XA Export Ltd', $zpl);
        $this->assertStringContainsString('^FB', $zpl);
        [$other,$another] = $this->setupLabelData('OTHER');
        $this->actingAs($another)->withSession(['active_business_id' => $other->id])
            ->post(route('labels.generate', [$carton->id, $template->id]))->assertNotFound();
        $this->get(route('cartons.show', $carton->id))->assertNotFound();
        $this->assertDatabaseCount('label_generations', 1);
    }

    public function test_carton_and_batch_scannable_links_match_enabled_public_pages(): void
    {
        [$business,$owner,$product,$batch,$carton] = $this->setupLabelData();
        $this->actingAs($owner)->put(route('cartons.qr.visibility', $carton->id), [
            'public_enabled' => '1',
        ])->assertRedirect();
        $this->put(route('stock.qr.visibility', $batch->id), [
            'public_enabled' => '1',
        ])->assertRedirect();
        $this->assertDatabaseCount('qr_identities', 2);

        foreach (['carton' => $carton->id, 'batch' => $batch->id] as $level => $recordId) {
            $identity = QrIdentity::where('business_id', $business->id)
                ->where('level', $level)->where('record_id', $recordId)->firstOrFail();
            $this->get(route('qr.public', $identity->public_token))
                ->assertOk()->assertSee($level === 'carton' ? 'CTN-0001' : 'BT-CROP-1');

            $this->post(route('labels.store'), $this->templateData([
                'name' => ucfirst($level).' QR', 'qr_level' => $level,
                'include_barcode' => '0', 'visible_fields' => [],
            ]))->assertRedirect()->assertSessionHasNoErrors();
            $template = LabelTemplate::where('name', ucfirst($level).' QR')->firstOrFail();
            $zpl = $this->post(route('labels.generate', [$carton->id, $template->id]))
                ->assertOk()->streamedContent();
            $this->assertStringContainsString('^BQN,', $zpl);
            $this->assertStringContainsString('^PW799', $zpl);
            $this->assertStringContainsString('^LL400', $zpl);
            $this->assertMatchesRegularExpression('/\\^FDLA,([^\\^]+)\\^FS/', $zpl);
            preg_match('/\\^FDLA,([^\\^]+)\\^FS/', $zpl, $match);
            $decoded = preg_replace_callback('/_([0-9A-F]{2})/',
                fn ($hex) => chr(hexdec($hex[1])), $match[1]);
            $this->assertSame(route('qr.public', $identity->public_token), $decoded);
        }
    }

    public function test_small_label_rejects_more_fields_than_physically_fit(): void
    {
        [$business,$owner,$product,$batch,$carton] = $this->setupLabelData();
        $this->actingAs($owner)->post(route('labels.store'), $this->templateData([
            'name' => 'Too much text', 'width_mm' => 75, 'height_mm' => 45,
            'qr_level' => 'none',
            'visible_fields' => array_keys(ZebraLabelRenderer::FIELD_LABELS),
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $template = LabelTemplate::firstOrFail();
        $this->post(route('labels.generate',[$carton->id, $template->id]))
            ->assertSessionHasErrors('label');
        $this->assertDatabaseCount('label_generations',0);
    }
}
