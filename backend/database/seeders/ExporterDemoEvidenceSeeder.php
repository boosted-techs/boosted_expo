<?php

namespace Database\Seeders;

use App\Models\BillingPaymentMethod;
use App\Models\CatalogueImport;
use App\Models\ExternalProviderConnection;
use App\Models\ProductImage;
use App\Services\ExportClearanceRegister;
use App\Services\ExternalProviderInbox;
use App\Services\FinanceTreasury;
use App\Services\FinanceWorkflows;
use App\Services\PlatformBilling;
use App\Services\PlatformUsage;
use App\Services\ReleaseAcceptanceManager;
use App\Services\ShipmentOperations;
use App\Services\ShipmentReadiness;
use App\Support\ExporterDemoContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ExporterDemoEvidenceSeeder extends Seeder
{
    public function run(ExporterDemoContext $d): void
    {
        $b = $d->business;
        $owner = $d->id('owner');
        $a = $d->id('accountant');
        $logistics = $d->id('logistics');
        $billing = app(PlatformBilling::class);
        $invoice = $billing->invoice($d->get('subscription'), BillingPaymentMethod::where('label', 'DEMO payment instructions · training only')->firstOrFail(), ['due_on' => $d->date(-30), 'notes' => $d->note('Next subscription invoice with an example goodwill credit.')], $d->id('superadmin'));
        $billing->adjust($invoice, ['kind' => 'credit', 'amount_ugx' => 10000, 'reason' => $d->note('Illustrative support goodwill credit.'), 'evidence_reference' => 'DEMO-PLATFORM-CREDIT', 'request_key' => $d->key()], $d->id('superadmin'));
        CatalogueImport::create(['id' => $d->key(), 'business_id' => $b->id, 'user_id' => $owner, 'rows' => [['sku' => 'DEMO-AVO', 'name' => 'Avocado', 'base_unit' => 'kg', 'description' => 'DEMO reviewed catalogue row', 'active' => true]], 'row_count' => 1, 'status' => 'applied', 'expires_at' => now()->addDay(), 'applied_at' => now()]);
        $image = imagecreatetruecolor(320, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 240, 225));
        imagestring($image, 5, 80, 80, 'AVOCADO - DEMO', imagecolorallocate($image, 30, 70, 35));
        ob_start();
        imagewebp($image, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $path = $d->file('demo-media', 'avocado.webp', $bytes);
        ProductImage::create(['business_id' => $b->id, 'product_id' => $d->get('AVO')->id, 'original_key' => $path, 'thumbnail_key' => $path, 'width' => 320, 'height' => 200, 'stored_bytes' => strlen($bytes)]);
        $shipment = app(ShipmentOperations::class)->create($b->id, ['creation_key' => $d->key(), 'customer_id' => $d->get('customer')->id, 'origin_country' => 'UG', 'destination_country' => 'AE', 'origin_airport' => 'Entebbe (EBB)', 'destination_airport' => 'Dubai (DXB)', 'carrier' => 'DEMO carrier', 'notes' => $d->note('Upcoming shipment remains blocked until required documents and cartons are complete.')], $logistics);
        app(ShipmentReadiness::class)->require($shipment, ['document_kind' => 'phytosanitary', 'file_required' => true, 'staff_person_id' => $d->get('staff_logistics')->id, 'due_on' => $d->date(-1), 'reason' => $d->note('Required plant-health evidence still outstanding.')], $logistics);
        app(ExportClearanceRegister::class)->submit($shipment, ['document_kind' => 'phytosanitary', 'authority_name' => 'DEMO ONLY — no issuing authority contacted', 'external_reference' => 'DEMO-NOT-A-VALID-PERMIT', 'source_evidence' => $d->note('Example clearance claim awaiting genuine attached evidence and review.'), 'valid_until' => $d->date(-7)], $logistics);
        $secret = Str::random(64);
        $connection = ExternalProviderConnection::create(['business_id' => $b->id, 'kind' => 'carrier', 'provider_code' => 'demo-carrier', 'provider_account' => 'DEMO-NOT-A-REAL-CARRIER', 'signing_secret' => $secret, 'trust_reference' => $d->note('Local signature simulation; no external provider connection.'), 'active' => true]);
        $timestamp = (string) time();
        $raw = json_encode(['account' => $connection->provider_account, 'event_id' => 'DEMO-CARRIER-EXCEPTION-1', 'event_type' => 'carrier_exception', 'reference' => $d->get('shipment')->reference, 'occurred_at' => now()->subMinute()->toIso8601String(), 'location' => 'DEMO Entebbe terminal', 'note' => 'DEMO ONLY simulated delay; no carrier API contacted.'], JSON_THROW_ON_ERROR);
        app(ExternalProviderInbox::class)->accept('carrier', 'demo-carrier', $raw, $timestamp, hash_hmac('sha256', $timestamp."\n".$raw, $secret));
        $connection->update(['active' => false]);
        $manifest = json_encode(['demo' => true, 'business_id' => $b->id, 'scope' => 'Illustrative acceptance checklist only; no deployment or physical acceptance has occurred.'], JSON_THROW_ON_ERROR);
        $d->file('demo-release', 'illustrative-candidate.json', $manifest);
        app(ReleaseAcceptanceManager::class)->begin($b, ['artifact_sha256' => hash('sha256', $manifest), 'assets_sha256' => hash_file('sha256', public_path('assets/fonts.css')), 'release_label' => 'DEMO ONLY · pending acceptance checklist', 'scope_note' => $d->note('All checks remain pending. This illustrative candidate is not a deployable release or production sign-off.')], $owner);
        $exchange = app(FinanceTreasury::class)->prepare($b, 'exchange', $d->input(['currency' => 'USD', 'bank_profile_id' => $d->get('usd_bank')->id, 'exchange_rate_id' => $d->get('closing_rate')->id, 'amount' => '100', 'fee_amount' => '1', 'destination_currency' => 'UGX', 'destination_account_key' => 'cash', 'received_amount' => '374000']), $a);
        $d->post('finance_workflow', $exchange);
        $custody = $d->get('cash_custody');
        $basis = app(FinanceWorkflows::class)->accountBasis($b->id, $custody->account_id, $d->date());
        $count = app(FinanceTreasury::class)->count($b, $custody, ['counted' => (string) $basis['units'], 'reason' => $d->note('Illustrative cash count agrees with all posted cash movements.')], $a);
        app(FinanceTreasury::class)->reviewCount($b, $count, true, $d->note('Independent training count review.'), $owner);
        foreach (['owner', 'accountant', 'buyer', 'packing'] as $role) {
            app(PlatformUsage::class)->record($b, $d->id($role));
        }app(PlatformUsage::class)->snapshot($b, now('Africa/Kampala')->startOfMonth()->toDateString(), $d->date());
    }
}
