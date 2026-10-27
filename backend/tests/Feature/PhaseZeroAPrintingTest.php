<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\CartonContent;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\LabelGeneration;
use App\Models\LabelMediaProfile;
use App\Models\LabelPrintBatch;
use App\Models\LabelPrintJob;
use App\Models\LabelTemplate;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\QrIdentity;
use App\Models\ShipmentCarton;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\LabelPrintQueue;
use App\Services\LabelWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PhaseZeroAPrintingTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $code = 'LABELS', string $role = 'owner'): array
    {
        $this->freezeTime();
        $business = Business::create(['name' => 'Mirembe Exports', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        BusinessMembership::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => $role, 'active' => true]);
        $product = Product::create(['business_id' => $business->id, 'sku' => 'AVO', 'name' => 'Avocado', 'base_unit' => 'kg']);
        $job = PackingJob::create(['business_id' => $business->id, 'reference' => 'PK-ONE', 'product_id' => $product->id, 'status' => 'completed', 'created_by' => $owner->id]);
        $batch = StockBatch::create(['business_id' => $business->id, 'product_id' => $product->id, 'batch_code' => 'FARM-ONE', 'received_on' => now()->toDateString()]);
        $cartons = collect([1, 2])->map(function ($number) use ($business, $job, $batch) {
            $carton = Carton::create(['business_id' => $business->id, 'packing_job_id' => $job->id, 'carton_code' => 'CTN-'.$number, 'net_weight_kg' => '15', 'gross_weight_kg' => '16', 'status' => 'packed']);
            CartonContent::create(['business_id' => $business->id, 'carton_id' => $carton->id, 'batch_id' => $batch->id, 'quantity' => '15']);

            return $carton;
        });
        $profile = LabelMediaProfile::create(['business_id' => $business->id, 'name' => 'GK888t 100x50', 'printer_model' => 'Zebra GK888t', 'width_mm' => '100', 'height_mm' => '50', 'dpi' => 203, 'protocol' => 'zpl', 'margin_mm' => 2, 'offset_x_mm' => 0, 'offset_y_mm' => 0, 'media_type' => 'gap', 'orientation' => 0, 'created_by' => $owner->id]);
        $template = LabelTemplate::create(['business_id' => $business->id, 'name' => 'Standard carton', 'media_profile_id' => $profile->id, 'width_mm' => 100, 'height_mm' => 50, 'dpi' => 203, 'visible_fields' => ['company', 'product', 'carton', 'net'], 'required_fields' => ['carton', 'net'], 'include_barcode' => true, 'qr_level' => 'carton', 'qr_visibility' => 'internal', 'layout' => ['mode' => 'bitmap', 'font_mm' => 2.5], 'approved_by' => $owner->id, 'approved_at' => now(), 'created_by' => $owner->id]);

        return compact('business', 'owner', 'product', 'job', 'batch', 'cartons', 'profile', 'template');
    }

    private function as(array $c): self
    {
        return $this->actingAs($c['owner'])->withSession(['active_business_id' => $c['business']->id]);
    }

    private function generate(array $c, int $copies = 1): LabelPrintBatch
    {
        $this->as($c)->post(route('labels.batches.store'), ['template_id' => $c['template']->id, 'carton_ids' => $c['cartons']->pluck('id')->all(), 'copies' => $copies, 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        return LabelPrintBatch::query()->latest('id')->firstOrFail();
    }

    #[TestWith([203, 799, 400])]
    #[TestWith([300, 1181, 591])]
    public function test_batch_has_unique_carton_artwork_manifest_and_private_revision_codes(int $dpi, int $width, int $height): void
    {
        $c = $this->context();
        $c['profile']->update(['printer_model' => $dpi === 203 ? 'Zebra GK888t' : 'Other 300 dpi printer', 'dpi' => $dpi]);
        $c['template']->update(['dpi' => $dpi]);
        $batch = $this->generate($c, 2);
        $labels = $batch->generations;
        $this->assertCount(2, $labels);
        $this->assertSame(2, $labels->pluck('scan_token')->unique()->count());
        $this->assertNotSame($labels[0]->zpl_sha256, $labels[1]->zpl_sha256);
        $this->assertSame(['CTN-1', 'CTN-2'], $labels->pluck('source_snapshot.values.carton')->all());
        $this->assertSame(0, QrIdentity::query()->where('public_enabled', true)->count());
        $this->get(route('labels.batches.show', $batch->id))->assertOk()->assertSee('2 unique cartons')->assertSee('4 stickers')->assertSee('CTN-1')->assertSee('CTN-2');
        $this->get(route('labels.artwork', $labels[0]->id))->assertOk()->assertHeader('Content-Type', 'image/png');
        $image = imagecreatefromstring(base64_decode($labels[0]->artwork_png));
        $this->assertSame($width, imagesx($image));
        $this->assertSame($height, imagesy($image));
        imagedestroy($image);
        $this->get(route('labels.scan', $labels[0]->scan_token))->assertOk()->assertSee('Not yet verified');
    }

    public function test_identical_batch_request_is_deduplicated_but_changed_payload_is_refused(): void
    {
        $c = $this->context();
        $key = (string) Str::uuid();
        $data = ['template_id' => $c['template']->id, 'carton_ids' => $c['cartons']->pluck('id')->all(), 'copies' => 1, 'request_key' => $key];
        $this->as($c)->post(route('labels.batches.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('labels.batches.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('labels.batches.store'), [...$data, 'copies' => 2])->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('label_print_batches', 1);
        $this->assertDatabaseCount('label_generations', 2);
    }

    public function test_private_product_qr_identifies_the_record_without_publishing_it(): void
    {
        $c = $this->context();
        $c['template']->update(['qr_level' => 'product']);
        $this->generate($c);
        $qr = QrIdentity::query()->where('business_id', $c['business']->id)->sole();
        $this->assertFalse($qr->public_enabled);
        $this->get(route('labels.trace', $qr->public_token))->assertOk()->assertSee('Avocado')->assertSee('AVO');
        $this->get(route('qr.public', $qr->public_token))->assertNotFound();
        $other = $this->context('OTHER');
        $this->as($other)->get(route('labels.trace', $qr->public_token))->assertNotFound();
    }

    public function test_batch_preview_loads_related_sources_together(): void
    {
        $c = $this->context();
        for ($i = 3; $i <= 7; $i++) {
            $carton = Carton::create(['business_id' => $c['business']->id, 'packing_job_id' => $c['job']->id, 'carton_code' => 'CTN-'.$i, 'net_weight_kg' => 15, 'gross_weight_kg' => 16, 'status' => 'packed']);
            CartonContent::create(['business_id' => $c['business']->id, 'carton_id' => $carton->id, 'batch_id' => $c['batch']->id, 'quantity' => 15]);
            $c['cartons']->push($carton);
        }
        $batch = $this->generate($c);
        DB::enableQueryLog();
        try {
            $this->get(route('labels.batches.show', $batch->id))->assertOk()->assertSee('7 unique cartons');
            $this->assertLessThan(35, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_required_awb_blocks_the_entire_batch_without_partial_output(): void
    {
        $c = $this->context();
        $c['template']->update(['visible_fields' => ['carton', 'awb'], 'required_fields' => ['awb']]);
        $this->as($c)->post(route('labels.batches.store'), ['template_id' => $c['template']->id, 'carton_ids' => $c['cartons']->pluck('id')->all(), 'copies' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('label');
        $this->assertDatabaseCount('label_generations', 0);
        $this->assertDatabaseCount('label_print_batches', 0);
    }

    public function test_long_text_and_overlapping_fields_are_rejected_instead_of_clipped(): void
    {
        $c = $this->context();
        $c['product']->update(['name' => str_repeat('Avocado ', 30)]);
        $this->as($c)->post(route('labels.batches.store'), ['template_id' => $c['template']->id, 'carton_ids' => [$c['cartons'][0]->id], 'copies' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('label');
        $c['product']->update(['name' => 'Avocado']);
        $c['template']->update(['layout' => ['mode' => 'bitmap', 'fields' => ['company' => ['x_mm' => 0, 'y_mm' => 0], 'product' => ['x_mm' => 0, 'y_mm' => 0]]]]);
        $this->post(route('labels.batches.store'), ['template_id' => $c['template']->id, 'carton_ids' => [$c['cartons'][0]->id], 'copies' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('label');
        $this->assertDatabaseCount('label_generations', 0);
    }

    public function test_pdf_and_zpl_use_the_original_artwork_and_record_downloads_without_claiming_print(): void
    {
        $c = $this->context();
        $batch = $this->generate($c, 2);
        $pdf = $this->post(route('labels.batches.output', $batch->id), ['format' => 'pdf', 'reason' => 'First print after preview']);
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('/Count 4', $pdf->getContent());
        $zpl = $this->post(route('labels.batches.output', $batch->id), ['format' => 'zpl', 'reason' => 'Download for workstation']);
        $zpl->assertOk();
        $this->assertSame(4, substr_count($zpl->getContent(), '^XA'));
        $this->assertDatabaseCount('label_print_jobs', 0);
        $this->assertDatabaseCount('label_events', 6);
        $this->assertNull($batch->generations->first()->verified_at);
    }

    public function test_sheet_layout_must_fit_and_can_group_two_labels_on_a_sheet(): void
    {
        $c = $this->context();
        $batch = $this->generate($c);
        $this->post(route('labels.batches.output', $batch->id), ['format' => 'pdf', 'columns' => 3, 'rows' => 1, 'sheet_width' => 210, 'sheet_height' => 297, 'reason' => 'Print two labels on sheet'])->assertSessionHasErrors('columns');
        $this->post(route('labels.batches.output', $batch->id), ['format' => 'pdf', 'columns' => 2, 'rows' => 1, 'sheet_width' => 210, 'sheet_height' => 297, 'gap_x' => 2, 'offset_x' => 4, 'reason' => 'Print two labels on sheet'])->assertOk();
    }

    public function test_source_change_blocks_queue_download_and_applied_verification(): void
    {
        $c = $this->context();
        $batch = $this->generate($c);
        $label = $batch->generations->first();
        [$agent] = app(LabelPrintQueue::class)->register($c['business'], 'USB packing desk', $c['owner']->id, $c['profile']->id);
        $c['cartons'][0]->update(['net_weight_kg' => 14]);
        $this->get(route('labels.batches.show', $batch->id))->assertOk()->assertSee('STALE');
        $this->post(route('labels.batches.output', $batch->id), ['format' => 'pdf', 'reason' => 'Try old output after change'])->assertSessionHasErrors('label');
        $this->post(route('labels.printers.jobs'), ['agent_id' => $agent->id, 'generation_id' => $label->id, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('generation_id');
        $this->post(route('labels.verify', $label->id), ['scan' => 'L'.$label->scan_token, 'reason' => 'Checked applied sticker', 'applied' => 1])->assertSessionHasErrors('scan');
        $this->assertDatabaseCount('label_print_jobs', 0);
        $this->assertNull($label->fresh()->verified_at);
    }

    public function test_dispatch_check_requires_the_current_scanned_revision(): void
    {
        $c = $this->context();
        $batch = $this->generate($c);
        $labels = $batch->generations;
        $c['business']->update(['label_verification_required' => true]);
        $first = $labels[0];
        $this->post(route('labels.verify', $first->id), ['scan' => 'CTN-1', 'reason' => 'Checked carton reference', 'applied' => 1])->assertSessionHasErrors('scan');
        foreach ($labels as $label) {
            $this->post(route('labels.verify', $label->id), ['scan' => 'L'.$label->scan_token, 'reason' => 'Scanned and checked applied label', 'applied' => 1])->assertSessionHasNoErrors();
        }
        app(LabelWorkflow::class)->assertDispatch($c['business'], $c['cartons']->pluck('id')->all());
        $this->assertNotNull($first->fresh()->verified_at);
        $c['cartons'][0]->update(['gross_weight_kg' => 17]);
        $this->expectException(ValidationException::class);
        app(LabelWorkflow::class)->assertDispatch($c['business'], $c['cartons']->pluck('id')->all());
    }

    public function test_corrupt_saved_artwork_is_refused_by_preview_output_and_queue(): void
    {
        $c = $this->context();
        $batch = $this->generate($c);
        $label = $batch->generations->first();
        $label->update(['artwork_png' => base64_encode('corrupt image')]);
        $this->get(route('labels.artwork', $label->id))->assertStatus(422);
        $this->post(route('labels.batches.output', $batch->id), ['format' => 'pdf', 'reason' => 'Try corrupted old output'])->assertSessionHasErrors('label');
        $this->assertTrue(app(LabelWorkflow::class)->stale($label->fresh()));
    }

    public function test_shipment_dispatch_refuses_unverified_or_stale_stickers_then_accepts_replacements(): void
    {
        $c = $this->context();
        $c['business']->update(['label_verification_required' => true]);
        $customer = Customer::create(['business_id' => $c['business']->id, 'customer_code' => 'BUYER', 'name' => 'Export buyer', 'country_code' => 'AE', 'currency' => 'USD']);
        $shipment = ExportShipment::create(['business_id' => $c['business']->id, 'reference' => 'SHP-LABELS', 'customer_id' => $customer->id, 'status' => 'ready', 'origin_country' => 'UG', 'destination_country' => 'AE', 'origin_airport' => 'EBB', 'destination_airport' => 'DXB', 'flight_number' => 'EK-ONE', 'air_waybill' => 'AWB-ONE', 'scheduled_departure_at' => now()->addDay(), 'created_by' => $c['owner']->id]);
        foreach ($c['cartons'] as $carton) {
            ShipmentCarton::create(['business_id' => $c['business']->id, 'shipment_id' => $shipment->id, 'carton_id' => $carton->id, 'allocated_by' => $c['owner']->id]);
            $carton->update(['status' => 'reserved']);
        }
        $dispatch = ['actual_departure_at' => now()->format('Y-m-d\TH:i'), 'confirm_dispatch' => 1];
        $this->as($c)->post(route('shipments.dispatch', $shipment->id), $dispatch)->assertSessionHasErrors('dispatch');
        $batch = $this->generate($c);
        foreach ($batch->generations as $label) {
            $this->post(route('labels.verify', $label->id), ['scan' => 'L'.$label->scan_token, 'reason' => 'Checked applied sticker and carton', 'applied' => 1])->assertSessionHasNoErrors();
        }
        $shipment->update(['flight_number' => 'EK-TWO']);
        $this->post(route('shipments.dispatch', $shipment->id), $dispatch)->assertSessionHasErrors('dispatch');
        $replacement = $this->generate($c);
        foreach ($replacement->generations as $label) {
            $this->post(route('labels.verify', $label->id), ['scan' => 'L'.$label->scan_token, 'reason' => 'Replaced stale flight sticker and scanned', 'applied' => 1])->assertSessionHasNoErrors();
        }
        $this->post(route('shipments.dispatch', $shipment->id), $dispatch)->assertSessionHasNoErrors();
        $this->assertSame('dispatched', $shipment->fresh()->status);
    }

    public function test_expired_delivery_needs_checked_recovery_and_does_not_resend_automatically(): void
    {
        $c = $this->context();
        $batch = $this->generate($c, 2);
        $queue = app(LabelPrintQueue::class);
        [$agent,$secret] = $queue->register($c['business'], 'Windows USB GK888t', $c['owner']->id, $c['profile']->id);
        $job = $queue->enqueue($c['business'], $agent->id, $batch->generations->first()->id, (string) Str::uuid(), $c['owner']->id, 2);
        $packet = $queue->claim($agent);
        $this->assertSame(2, $packet['copies']);
        $this->travel(100)->seconds();
        $this->assertNull($queue->claim($agent));
        $this->assertSame('uncertain', $job->fresh()->status);
        $data = ['action' => 'retry', 'reason' => 'Printer checked; discarded damaged output', 'request_key' => (string) Str::uuid()];
        $this->as($c)->post(route('labels.jobs.recover', $job->id), $data)->assertSessionHasErrors('checked_printer');
        $this->post(route('labels.jobs.recover', $job->id), [...$data, 'checked_printer' => 1])->assertSessionHasNoErrors();
        $this->post(route('labels.jobs.recover', $job->id), [...$data, 'checked_printer' => 1])->assertSessionHasNoErrors();
        $child = LabelPrintJob::query()->where('parent_job_id', $job->id)->sole();
        $this->assertSame(2, $child->copies);
        $this->assertSame('resolved', $job->fresh()->status);
        $this->assertSame($child->id, $queue->claim($agent)['job_id']);
    }

    public function test_mismatched_media_and_reprint_without_reason_are_refused(): void
    {
        $c = $this->context();
        $batch = $this->generate($c);
        $label = $batch->generations->first();
        [$wrong] = app(LabelPrintQueue::class)->register($c['business'], 'Wrong media printer', $c['owner']->id);
        $this->post(route('labels.printers.jobs'), ['agent_id' => $wrong->id, 'generation_id' => $label->id, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('agent_id');
        [$right] = app(LabelPrintQueue::class)->register($c['business'], 'Correct media printer', $c['owner']->id, $c['profile']->id);
        $data = ['agent_id' => $right->id, 'generation_id' => $label->id, 'request_key' => (string) Str::uuid()];
        $this->post(route('labels.printers.jobs'), $data)->assertSessionHasNoErrors();
        $this->post(route('labels.printers.jobs'), [...$data, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('label_print_jobs', 1);
    }

    public function test_queued_job_can_be_cancelled_but_a_live_delivery_requires_acknowledgement(): void
    {
        $c = $this->context();
        $batch = $this->generate($c);
        $queue = app(LabelPrintQueue::class);
        [$agent] = $queue->register($c['business'], 'USB packing printer', $c['owner']->id, $c['profile']->id);
        $job = $queue->enqueue($c['business'], $agent->id, $batch->generations[0]->id, (string) Str::uuid(), $c['owner']->id);
        $data = ['action' => 'cancel', 'checked_printer' => 1, 'reason' => 'Printer and queue checked before cancellation', 'request_key' => (string) Str::uuid()];
        $this->post(route('labels.jobs.recover', $job->id), $data)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $job->fresh()->status);
        $live = $queue->enqueue($c['business'], $agent->id, $batch->generations[1]->id, (string) Str::uuid(), $c['owner']->id);
        $packet = $queue->claim($agent);
        $this->post(route('labels.jobs.recover', $live->id), [...$data, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('job');
        $queue->acknowledge($agent, $live->id, $packet['lease'], false, 'Printer disconnected during transfer');
        $this->assertSame('uncertain', $live->fresh()->status);
        $this->post(route('labels.jobs.recover', $live->id), [...$data, 'action' => 'resolve_transferred', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('transferred', $live->fresh()->status);
    }

    public function test_profiles_accept_fractional_media_but_reject_wrong_gk888t_dpi(): void
    {
        $c = $this->context();
        $data = ['name' => 'Fractional stickers', 'printer_model' => 'Other ZPL printer', 'protocol' => 'zpl', 'dpi' => 300, 'width_mm' => 100.25, 'height_mm' => 50.5, 'margin_mm' => 2, 'offset_x_mm' => 0, 'offset_y_mm' => 0, 'media_type' => 'gap', 'orientation' => 0];
        $this->as($c)->post(route('labels.profiles.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('label_media_profiles', ['business_id' => $c['business']->id, 'name' => 'Fractional stickers', 'width_mm' => 100.25]);
        $this->post(route('labels.profiles.store'), [...$data, 'name' => 'Invalid GK', 'printer_model' => 'Zebra GK888t'])->assertSessionHasErrors('printer_model');
        $this->post(route('labels.profiles.test', $c['profile']->id), ['test_evidence' => 'Dimensions and codes scanned on actual printer'])->assertSessionHasErrors('confirmed_scan');
        $this->post(route('labels.profiles.test', $c['profile']->id), ['test_evidence' => 'Measured 100 x 50 mm; QR and barcode scanned on Windows', 'confirmed_scan' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($c['profile']->fresh()->tested_at);
    }

    public function test_template_form_saves_layout_required_fields_logo_and_business_defaults(): void
    {
        $c = $this->context();
        $this->as($c)->get(route('labels.index'))->assertOk()->assertSee('GK888t');
        $this->get(route('labels.edit', $c['template']->id))->assertOk()->assertSee('Approve and save new version');
        $this->post(route('labels.store'), ['name' => 'New approved template', 'width_mm' => 100, 'height_mm' => 50, 'dpi' => 203, 'qr_level' => 'none', 'qr_visibility' => 'internal', 'include_barcode' => 1, 'visible_fields' => ['company', 'carton'], 'required_fields' => ['carton'], 'layout' => ['mode' => 'bitmap', 'font_mm' => 2.5], 'media_profile_id' => $c['profile']->id, 'is_default' => 1, 'logo' => UploadedFile::fake()->image('logo.png', 200, 80)])->assertSessionHasNoErrors();
        $template = LabelTemplate::query()->where('name', 'New approved template')->sole();
        $this->assertSame($c['owner']->id, $template->approved_by);
        $this->assertTrue($template->is_default);
        $this->assertNotEmpty($template->layout['logo_png']);
    }

    public function test_older_label_is_searchable_beyond_the_previous_sixty_record_limit(): void
    {
        $c = $this->context();
        $batch = $this->generate($c);
        $old = $batch->generations->first();
        for ($i = 0; $i < 65; $i++) {
            LabelGeneration::create(['business_id' => $c['business']->id, 'carton_id' => $c['cartons'][1]->id, 'template_id' => $c['template']->id, 'template_version' => 1, 'zpl_snapshot' => "^XA\n^XZ\n", 'zpl_sha256' => hash('sha256', "^XA\n^XZ\n"), 'generated_by' => $c['owner']->id]);
        }
        $this->get(route('labels.printers', ['search' => 'CTN-1']))->assertOk()->assertViewHas('generations', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $old->id);
        $this->get(route('labels.history', ['search' => 'CTN-1']))->assertOk()->assertSee('CTN-1');
    }

    public function test_tenant_boundaries_cover_batches_artwork_scan_profiles_and_queue(): void
    {
        $a = $this->context();
        $batch = $this->generate($a);
        $label = $batch->generations->first();
        $b = $this->context('OTHER');
        $this->as($b)->get(route('labels.batches.show', $batch->id))->assertNotFound();
        $this->get(route('labels.artwork', $label->id))->assertNotFound();
        $this->get(route('labels.scan', $label->scan_token))->assertNotFound();
        $this->get(route('labels.print', ['shipment' => 999999]))->assertNotFound();
        $this->post(route('labels.profiles.test', $a['profile']->id), ['confirmed_scan' => 1, 'test_evidence' => 'Physical scan and ruler test on an outside profile'])->assertNotFound();
        $this->post(route('labels.batches.store'), ['template_id' => $b['template']->id, 'carton_ids' => [$a['cartons'][0]->id], 'copies' => 1, 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('label_print_batches', 1);
    }

    #[TestWith(['packing'])]
    #[TestWith(['logistics'])]
    #[TestWith(['sales'])]
    #[TestWith(['viewer'])]
    public function test_only_template_managers_can_save_printer_profiles(string $role): void
    {
        $c = $this->context(role: $role);
        $this->as($c)->post(route('labels.profiles.store'), [])->assertForbidden();
        $this->get(route('labels.agent.download'))->assertForbidden();
    }

    public function test_login_invites_installation_but_keeps_sign_in_and_normal_authentication(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Install Boosted One')->assertSee('Not now')->assertSee('/manifest.webmanifest')->assertSee('/assets/pwa-install.js')->assertSee('name="password"', false);
        $this->get(route('labels.print'))->assertRedirect(route('login'));
        $this->get(route('labels.scan', '01AAAAAAAAAAAAAAAAAAAAAAAA'))->assertRedirect(route('login'));
        $c = $this->context();
        $this->as($c)->get(route('labels.profiles'))->assertOk()->assertSee('100 × 50');
        $this->get(route('labels.agent.download'))->assertOk()->assertDownload('zebra_agent.py');
    }
}
