<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\CartonContent;
use App\Models\Customer;
use App\Models\ExportClearanceCheck;
use App\Models\ExportShipment;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\ShipmentCarton;
use App\Models\ShipmentRequirement;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\ShipmentReadiness;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShipmentReadinessPhaseFourTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(string $code = 'DOC'): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Document Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        $logistics = User::factory()->create();
        foreach ([[$owner, 'owner'], [$logistics, 'logistics']] as [$user,$role]) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => $role, 'active' => true]);
        }
        $customer = Customer::create(['business_id' => $business->id, 'customer_code' => 'BUY', 'name' => 'Overseas buyer', 'country_code' => 'AE', 'currency' => 'USD', 'active' => true]);
        $product = Product::create(['business_id' => $business->id, 'sku' => 'AVO', 'name' => 'Avocado', 'base_unit' => 'kg', 'active' => true]);
        $batch = StockBatch::create(['business_id' => $business->id, 'product_id' => $product->id, 'batch_code' => 'B-'.$code, 'received_on' => '2026-10-27']);
        $job = PackingJob::create(['business_id' => $business->id, 'product_id' => $product->id, 'reference' => 'PK-'.$code, 'status' => 'completed', 'created_by' => $owner->id]);
        $carton = Carton::create(['business_id' => $business->id, 'packing_job_id' => $job->id, 'carton_code' => 'CT-'.$code, 'status' => 'reserved', 'net_weight_kg' => '10', 'gross_weight_kg' => '11', 'packed_by' => $owner->id, 'packed_at' => now()]);
        CartonContent::create(['business_id' => $business->id, 'carton_id' => $carton->id, 'batch_id' => $batch->id, 'quantity' => '10']);
        $shipment = ExportShipment::create(['business_id' => $business->id, 'customer_id' => $customer->id, 'reference' => 'SH-'.$code, 'status' => 'ready', 'transport_mode' => 'air', 'origin_country' => 'UG', 'destination_country' => 'AE', 'flight_number' => 'FL-101', 'air_waybill' => 'AWB-101', 'created_by' => $owner->id]);
        ShipmentCarton::create(['business_id' => $business->id, 'shipment_id' => $shipment->id, 'carton_id' => $carton->id, 'allocated_by' => $owner->id]);

        return compact('business', 'owner', 'logistics', 'shipment', 'carton');
    }

    private function requireDocument(array $c, bool $fileRequired = true): ShipmentRequirement
    {
        $this->actingAs($c['owner'])->withSession(['active_business_id' => $c['business']->id])->post(route('shipments.readiness.store', $c['shipment']->id), ['document_kind' => 'phytosanitary', 'file_required' => $fileRequired ? '1' : '0', 'due_on' => '2026-10-27', 'reason' => 'This exporter requires the verified plant health document before release.'])->assertRedirect()->assertSessionHasNoErrors();

        return ShipmentRequirement::query()->where('business_id', $c['business']->id)->firstOrFail();
    }

    private function document(array $c, bool $withFile = true): ExportClearanceCheck
    {
        $data = ['document_kind' => 'phytosanitary', 'authority_name' => 'Issuer shown on original', 'external_reference' => 'PHYTO-101', 'valid_until' => '2026-10-27', 'source_evidence' => 'The original certificate and source authority reference were independently checked.'];
        if ($withFile) {
            $data['file'] = UploadedFile::fake()->create('certificate.pdf', 2, 'application/pdf');
        }
        $this->actingAs($c['logistics'])->withSession(['active_business_id' => $c['business']->id])->post(route('shipments.clearance.submit', $c['shipment']->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $check = ExportClearanceCheck::query()->where('business_id', $c['business']->id)->latest('id')->firstOrFail();
        $this->post(route('shipments.clearance.review', ['shipment' => $c['shipment']->id, 'record' => $check->id, 'action' => 'accept']), ['note' => 'Own source document review is not allowed here.'])->assertForbidden();
        $this->actingAs($c['owner'])->post(route('shipments.clearance.review', ['shipment' => $c['shipment']->id, 'record' => $check->id, 'action' => 'accept']), ['note' => 'Independently verified the current manifest and original authority evidence.'])->assertRedirect()->assertSessionHasNoErrors();

        return $check->fresh();
    }

    private function dispatch(array $c): TestResponse
    {
        return $this->actingAs($c['logistics'])->withSession(['active_business_id' => $c['business']->id])->post(route('shipments.dispatch', $c['shipment']->id), ['actual_departure_at' => '2026-10-27T14:30', 'confirm_dispatch' => '1']);
    }

    public function test_missing_required_document_blocks_dispatch_and_current_checked_original_allows_it(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $this->requireDocument($c);
        $this->dispatch($c)->assertSessionHasErrors('dispatch');
        $this->assertSame('ready', $c['shipment']->fresh()->status);
        $check = $this->document($c);
        $this->dispatch($c)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('dispatched', $c['shipment']->fresh()->status);
        $this->assertSame('shipped', $c['carton']->fresh()->status);
        $proof = $c['shipment']->events()->where('action', 'dispatched')->firstOrFail()->details['document_readiness'];
        $this->assertSame($check->id, $proof['documents'][0]['check_id']);
        $this->assertSame($check->file_sha256, $proof['documents'][0]['file_sha256']);
        $this->actingAs($c['owner'])->post(route('shipments.readiness.defaults'), ['kinds' => ['air_waybill'], 'reason' => 'New shipments now require the original carrier document too.'])->assertRedirect();
        $this->assertCount(1, app(ShipmentReadiness::class)->report($c['shipment']->fresh())['rows']);
        $this->get(route('shipments.show', $c['shipment']->id))->assertOk()->assertSee('Document readiness before dispatch');
    }

    public function test_manifest_change_or_changed_supporting_file_cannot_reuse_old_clearance_review(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $this->requireDocument($c);
        $check = $this->document($c);
        Storage::disk('local')->put($check->file_path, 'Changed certificate contents');
        $this->dispatch($c)->assertSessionHasErrors('dispatch');
        $this->actingAs($c['owner'])->get(route('shipments.readiness.evidence', $check->id))->assertNotFound();
        $this->post(route('shipments.readiness.revoke', $check->id), ['reason' => 'The original file no longer matches the independently checked evidence.'])->assertRedirect();
        $this->assertSame('revoked', $check->fresh()->status);
        $this->assertSame($c['owner']->id, $check->fresh()->reviewed_by);
        $fresh = $this->document($c);
        $c['shipment']->update(['flight_number' => 'FL-CHANGED']);
        $this->dispatch($c)->assertSessionHasErrors('dispatch');
        $this->assertFalse(app(ShipmentReadiness::class)->report($c['shipment']->fresh())['ready']);
        $this->assertSame('internally_checked', $fresh->fresh()->status);
    }

    public function test_owner_waiver_is_explicit_and_other_tenants_and_logistics_cannot_relax_it(): void
    {
        $c = $this->context();
        $other = $this->context('OTHER');
        $requirement = $this->requireDocument($c);
        $this->actingAs($c['logistics'])->post(route('shipments.readiness.waive', $requirement->id), ['reason' => 'A logistics operator must not bypass the owner requirement.'])->assertForbidden();
        $this->actingAs($other['owner'])->withSession(['active_business_id' => $other['business']->id])->post(route('shipments.readiness.waive', $requirement->id), ['reason' => 'Another tenant cannot see or alter this requirement.'])->assertNotFound();
        $this->actingAs($c['owner'])->withSession(['active_business_id' => $c['business']->id])->post(route('shipments.readiness.waive', $requirement->id), ['reason' => 'Owner recorded a specific business exception with supporting release evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->dispatch($c)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('waived', $requirement->fresh()->status);
        $this->assertSame($c['owner']->id, $requirement->fresh()->waived_by);
    }

    public function test_reference_review_without_a_required_original_file_still_blocks_release(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $this->requireDocument($c, true);
        $check = $this->document($c, false);
        $this->assertSame('internally_checked', $check->status);
        $this->dispatch($c)->assertSessionHasErrors('dispatch');
        $this->assertSame('ready', $c['shipment']->fresh()->status);
    }
}
