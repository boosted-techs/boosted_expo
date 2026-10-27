<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\Customer;
use App\Models\ExportClearanceCheck;
use App\Models\ExportShipment;
use App\Models\ExternalProviderConnection;
use App\Models\ExternalProviderEvent;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\LabelGeneration;
use App\Models\LabelPrintJob;
use App\Models\LabelTemplate;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\User;
use App\Services\LabelPrintQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhaseThreeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $code): array
    {
        $business = Business::create([
            'name' => 'Export Business '.$code, 'code' => $code, 'country_code' => 'UG',
            'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala',
            'default_qr_level' => 'carton',
        ]);
        $users = [];
        foreach (['owner', 'admin', 'accountant', 'logistics', 'packing'] as $role) {
            $user = User::factory()->create();
            BusinessMembership::create([
                'business_id' => $business->id, 'user_id' => $user->id,
                'role' => $role, 'active' => true,
            ]);
            $users[$role] = $user;
        }
        $customer = Customer::create([
            'business_id' => $business->id, 'customer_code' => 'C-'.$code,
            'name' => 'Customer '.$code, 'country_code' => 'AE', 'active' => true,
            'currency' => 'USD',
        ]);
        $shipment = ExportShipment::create([
            'business_id' => $business->id, 'reference' => 'SHIP-'.$code,
            'creation_key' => (string) Str::uuid(),
            'customer_id' => $customer->id, 'status' => 'draft', 'transport_mode' => 'air',
            'origin_country' => 'UG', 'destination_country' => 'AE',
            'air_waybill' => 'AWB-'.$code, 'carrier' => 'Example Carrier',
            'created_by' => $users['owner']->id,
        ]);
        $account = FinanceAccount::create([
            'business_id' => $business->id, 'code' => '1020', 'name' => 'Bank',
            'type' => 'asset', 'system_key' => 'bank', 'active' => true,
        ]);
        $payment = FinancePayment::create([
            'business_id' => $business->id, 'reference' => 'PAY-'.$code,
            'direction' => 'in', 'cash_account_id' => $account->id,
            'currency' => 'USD', 'amount' => '75.50',
            'settled_base_amount' => '280000',
            'payment_on' => now()->toDateString(),
            'external_reference' => 'EXT-PAY-'.$code,
            'status' => 'posted', 'posted_at' => now(),
            'created_by' => $users['accountant']->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $product = Product::create([
            'business_id' => $business->id, 'sku' => 'AVO-'.$code,
            'name' => 'Avocado', 'base_unit' => 'kg',
        ]);
        $packing = PackingJob::create([
            'business_id' => $business->id, 'reference' => 'PACK-'.$code,
            'product_id' => $product->id, 'status' => 'completed',
            'created_by' => $users['owner']->id,
        ]);
        $carton = Carton::create([
            'business_id' => $business->id, 'carton_code' => 'CT-'.$code,
            'packing_job_id' => $packing->id, 'status' => 'packed',
            'net_weight_kg' => '10', 'gross_weight_kg' => '11',
        ]);
        $template = LabelTemplate::create([
            'business_id' => $business->id, 'name' => 'Label '.$code,
            'width_mm' => 100, 'height_mm' => 50, 'dpi' => 203,
            'qr_level' => 'none', 'include_barcode' => true, 'visible_fields' => ['carton'],
            'version' => 1, 'active' => true, 'created_by' => $users['owner']->id,
        ]);
        $zpl = "^XA\n^FO0,0^FDCT-".$code."^FS\n^XZ\n";
        $label = LabelGeneration::create([
            'business_id' => $business->id, 'carton_id' => $carton->id,
            'template_id' => $template->id, 'template_version' => 1,
            'zpl_sha256' => hash('sha256', $zpl), 'zpl_snapshot' => $zpl,
            'template_snapshot' => ['name' => 'Label '.$code],
            'generated_by' => $users['owner']->id,
        ]);

        return compact('business', 'users', 'shipment', 'payment', 'label');
    }

    private function as(array $fixture, string $role): self
    {
        return $this->actingAs($fixture['users'][$role])
            ->withSession(['active_business_id' => $fixture['business']->id]);
    }

    private function connect(array $fixture, string $kind, string $provider,
        string $account, string $secret): ExternalProviderConnection
    {
        return ExternalProviderConnection::create([
            'business_id' => $fixture['business']->id, 'kind' => $kind,
            'provider_code' => $provider, 'provider_account' => $account,
            'signing_secret' => $secret, 'trust_reference' => 'CONTRACT-REVIEW-2026',
            'active' => true,
        ]);
    }

    private function sendProvider(string $kind, string $provider,
        array $evidence, string $secret, ?string $signedAt = null,
        ?string $overrideSignature = null): TestResponse
    {
        $raw = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $time = $signedAt ?? (string) time();
        $signature = $overrideSignature ?? hash_hmac('sha256', $time."\n".$raw, $secret);
        $headers = $this->transformHeadersToServerVars([
            'X-Expo-Timestamp' => $time, 'X-Expo-Signature' => $signature,
            'Accept' => 'application/json', 'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', route('integrations.webhook', [
            'kind' => $kind, 'provider' => $provider,
        ]), [], [], [], $headers, $raw);
    }

    public function test_signed_payment_provider_notice_is_deduplicated_and_does_not_post_money(): void
    {
        $a = $this->fixture('AA');
        $b = $this->fixture('BB');
        $secret = str_repeat('a', 40);
        $this->connect($a, 'payment', 'examplepay', 'merchant-AA', $secret);
        $base = [
            'account' => 'merchant-AA', 'event_id' => 'PAYMENT-EVT-100',
            'event_type' => 'payment_settled', 'reference' => $a['payment']->external_reference,
            'currency' => 'USD', 'amount' => '75.50', 'occurred_at' => now()->toIso8601String(),
        ];
        $this->sendProvider('payment', 'examplepay', $base, 'different-invalid-secret')
            ->assertStatus(401);
        $this->sendProvider('payment', 'examplepay', $base, $secret,
            (string) (time() - 600))->assertStatus(401);
        $this->sendProvider('payment', 'examplepay', $base, $secret)
            ->assertStatus(202)->assertJsonPath('review_required', true);
        $event = ExternalProviderEvent::firstOrFail();
        $this->assertSame($a['payment']->id, $event->finance_payment_id);
        $this->assertSame('needs_review', $event->review_status);
        $this->assertNotSame(json_encode($base), $event->getRawOriginal('encrypted_payload'));
        $this->sendProvider('payment', 'examplepay', $base, $secret)->assertStatus(202);
        $this->assertDatabaseCount('external_provider_events', 1);
        $different = [...$base, 'amount' => '78.50'];
        $this->sendProvider('payment', 'examplepay', $different, $secret)
            ->assertStatus(422);
        $this->assertDatabaseCount('external_provider_events', 1);

        $this->as($a, 'accountant')->post(route('integrations.events.review', [
            'event' => $event->id, 'action' => 'accept',
        ]), ['note' => 'Accountant is not authorised to independently accept evidence.'])
            ->assertForbidden();
        $this->as($b, 'owner')->post(route('integrations.events.review', [
            'event' => $event->id, 'action' => 'accept',
        ]), ['note' => 'Cross-tenant review must never be accepted.'])->assertNotFound();
        $this->as($a, 'owner')->post(route('integrations.events.review', [
            'event' => $event->id, 'action' => 'accept',
        ]), ['note' => 'Confirmed the provider record with an independent merchant statement.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('reviewed_evidence', $event->fresh()->review_status);
        $this->assertSame('posted', $a['payment']->fresh()->status);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertSame('draft', $a['shipment']->fresh()->status);
    }

    public function test_provider_mismatch_stays_unmatched_and_false_carrier_arrival_cannot_auto_deliver(): void
    {
        $a = $this->fixture('AA');
        $secret = str_repeat('s', 45);
        $this->connect($a, 'payment', 'examplepay', 'merchant-AA', $secret);
        $payment = [
            'account' => 'merchant-AA', 'event_id' => 'AMOUNT-MISMATCH',
            'event_type' => 'payment_settled', 'reference' => $a['payment']->external_reference,
            'currency' => 'USD', 'amount' => '750.00', 'occurred_at' => now()->toIso8601String(),
        ];
        $this->sendProvider('payment', 'examplepay', $payment, $secret)->assertStatus(202);
        $mismatch = ExternalProviderEvent::firstOrFail();
        $this->assertSame('unmatched', $mismatch->review_status);
        $this->as($a, 'owner')->post(route('integrations.events.review', [
            'event' => $mismatch->id, 'action' => 'accept',
        ]), ['note' => 'Never approve mismatched source currency or payment amounts.'])
            ->assertSessionHasErrors('review');

        $carrierSecret = str_repeat('C', 45);
        $this->connect($a, 'carrier', 'examplecargo', 'carrier-account-1', $carrierSecret);
        $cargo = [
            'account' => 'carrier-account-1', 'event_id' => 'CARGO-EVT-001',
            'event_type' => 'carrier_arrived', 'reference' => $a['shipment']->air_waybill,
            'occurred_at' => now()->toIso8601String(), 'location' => 'DXB',
        ];
        $this->sendProvider('carrier', 'examplecargo', $cargo, $carrierSecret)
            ->assertStatus(202);
        $event = ExternalProviderEvent::latest('id')->firstOrFail();
        $this->assertSame($a['shipment']->id, $event->shipment_id);
        $this->assertSame('draft', $a['shipment']->fresh()->status);
        $this->as($a, 'owner')->post(route('integrations.events.review', [
            'event' => $event->id, 'action' => 'accept',
        ]), ['note' => 'Carrier says received; internal movement records must be separately confirmed.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('draft', $a['shipment']->fresh()->status);
        $this->assertDatabaseCount('shipment_events', 0);
    }

    public function test_print_agent_token_is_private_and_original_label_is_queued_idempotently(): void
    {
        $a = $this->fixture('AA');
        $b = $this->fixture('BB');
        [$agent,$token] = app(LabelPrintQueue::class)->register(
            $a['business'], 'Warehouse Zebra', $a['users']['owner']->id);
        $this->assertSame(64, strlen($token));
        $this->assertNotSame($token, $agent->credential_hash);
        $this->as($a, 'packing')->post(route('labels.printers.create'), [
            'name' => 'Another workstation',
        ])->assertForbidden();
        $key = (string) Str::uuid();
        $data = ['agent_id' => $agent->id, 'generation_id' => $a['label']->id,
            'request_key' => $key];
        $this->as($a, 'packing')->post(route('labels.printers.jobs'), $data)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('labels.printers.jobs'), $data)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('label_print_jobs', 1);
        $this->as($b, 'owner')->post(route('labels.printers.jobs'), $data)->assertNotFound();

        $url = route('print-agent.next', $agent->id);
        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson($url, [])->assertStatus(401);
        $this->withToken($token)->postJson($url, [])->assertOk()
            ->assertJsonPath('job.job_id', LabelPrintJob::firstOrFail()->id)
            ->assertJsonPath('job.zpl', $a['label']->zpl_snapshot);
        $job = LabelPrintJob::firstOrFail();
        $this->assertSame('leased', $job->status);
        $this->assertSame(1, $job->attempts);
        $lease = $this->withToken($token)->postJson($url, [])
            ->assertOk()->json('job');
        $this->assertNull($lease);
        $this->withToken($token)->postJson(route('print-agent.ack', [
            'agent' => $agent->id, 'job' => $job->id,
        ]), [
            'lease' => str_repeat('x', 64), 'transferred' => true,
        ])->assertStatus(403);
        // Existing leased token is hash-only, never displayed in an admin view.
        $this->assertSame(64, strlen($job->lease_hash));
        $this->as($a, 'owner')->get(route('labels.printers'))
            ->assertOk()->assertDontSee($token);
    }

    public function test_print_ack_reports_socket_transfer_not_physical_print_and_revoke_blocks_pulls(): void
    {
        $a = $this->fixture('AA');
        [$agent,$token] = app(LabelPrintQueue::class)->register(
            $a['business'], 'Packing print kiosk', $a['users']['owner']->id);
        $job = app(LabelPrintQueue::class)->enqueue(
            $a['business'], $agent->id, $a['label']->id, (string) Str::uuid(),
            $a['users']['owner']->id);
        $packet = $this->withToken($token)
            ->postJson(route('print-agent.next', $agent->id), [])->assertOk()->json('job');
        $this->assertSame($a['label']->zpl_snapshot, $packet['zpl']);
        $this->assertSame(hash('sha256', $packet['zpl']), $packet['sha256']);
        $this->withToken($token)->postJson(route('print-agent.ack', [
            'agent' => $agent->id, 'job' => $job->id,
        ]), ['lease' => $packet['lease'], 'transferred' => true])
            ->assertOk()->assertJsonPath('physical_print_confirmed', false)
            ->assertJsonPath('status', 'transferred');
        $this->assertSame('transferred', $job->fresh()->status);
        $this->withToken($token)->postJson(route('print-agent.ack', [
            'agent' => $agent->id, 'job' => $job->id,
        ]), ['lease' => $packet['lease'], 'transferred' => true])
            ->assertOk();
        $this->as($a, 'owner')->post(route('labels.printers.revoke', $agent->id))
            ->assertSessionHasNoErrors();
        $this->withToken($token)->postJson(route('print-agent.next', $agent->id), [])
            ->assertStatus(401);
    }

    public function test_print_leases_are_bounded_and_stale_acknowledgements_are_rejected(): void
    {
        $a = $this->fixture('AA');
        [$agent,$token] = app(LabelPrintQueue::class)->register(
            $a['business'], 'Printer A', $a['users']['owner']->id);
        $queue = app(LabelPrintQueue::class);
        $job = $queue->enqueue($a['business'], $agent->id,
            $a['label']->id, (string) Str::uuid(), $a['users']['owner']->id);
        $first = $this->withToken($token)
            ->postJson(route('print-agent.next', $agent->id), [])->assertOk()->json('job');
        $this->assertSame(1, $job->fresh()->attempts);
        $this->travel(100)->seconds();
        try {
            $this->withToken($token)->postJson(route('print-agent.ack', [
                'agent' => $agent->id, 'job' => $job->id,
            ]), ['lease' => $first['lease'], 'transferred' => true])
                ->assertStatus(422);
            $second = $this->withToken($token)
                ->postJson(route('print-agent.next', $agent->id), [])
                ->assertOk()->json('job');
            $this->assertNull($second);
            $this->assertSame('uncertain', $job->fresh()->status);
            $this->assertSame(1, $job->fresh()->attempts);
            $this->withToken($token)->postJson(route('print-agent.ack', [
                'agent' => $agent->id, 'job' => $job->id,
            ]), ['lease' => $first['lease'], 'transferred' => true])
                ->assertStatus(422);
        } finally {
            $this->travelBack();
        }
        $this->as($a, 'owner')->post(route('labels.printers.revoke', $agent->id))
            ->assertSessionHasNoErrors();
        $this->assertSame('uncertain', $job->fresh()->status);
    }

    public function test_provider_identity_cannot_be_reassigned_and_malformed_callbacks_fail(): void
    {
        $a = $this->fixture('AA');
        $b = $this->fixture('BB');
        $secret = str_repeat('P', 42);
        $this->connect($a, 'payment', 'examplepay', 'merchant-AA', $secret);
        $this->connect($b, 'payment', 'examplepay', 'merchant-BB', str_repeat('B', 42));
        $wrong = [
            'account' => 'merchant-BB', 'event_id' => 'WRONG-TENANT-EVENT',
            'event_type' => 'payment_settled', 'reference' => $a['payment']->external_reference,
            'currency' => 'USD', 'amount' => '75.50',
            'occurred_at' => now()->toIso8601String(),
        ];
        $this->sendProvider('payment', 'examplepay', $wrong, $secret)->assertStatus(401);
        $this->assertDatabaseCount('external_provider_events', 0);
        $badType = [...$wrong, 'account' => 'merchant-AA',
            'event_type' => 'post_gl_transfer'];
        $this->sendProvider('payment', 'examplepay', $badType, $secret)->assertStatus(422);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_expired_clearance_document_cannot_be_accepted_as_current(): void
    {
        $a = $this->fixture('AA');
        $this->as($a, 'logistics')->post(route('shipments.clearance.submit',
            $a['shipment']->id), [
                'document_kind' => 'certificate_of_origin',
                'authority_name' => 'Example issuing chamber',
                'external_reference' => 'COO-EXPIRED-1',
                'valid_until' => now()->subDay()->toDateString(),
                'source_evidence' => 'This old certificate was inspected as an example but is no longer legally valid.',
            ])->assertSessionHasNoErrors();
        $record = ExportClearanceCheck::firstOrFail();
        $this->as($a, 'owner')->post(route('shipments.clearance.review', [
            'shipment' => $a['shipment']->id, 'record' => $record->id,
            'action' => 'accept',
        ]), ['note' => 'An expired origin certificate must not be accepted.'])
            ->assertSessionHasErrors('valid_until');
        $this->assertSame('pending_review', $record->fresh()->status);
    }

    public function test_export_clearance_reference_requires_independent_review_and_expiry(): void
    {
        $a = $this->fixture('AA');
        $b = $this->fixture('BB');
        $payload = [
            'document_kind' => 'phytosanitary',
            'authority_name' => 'Issuing inspectorate named on external evidence',
            'external_reference' => 'PHYTO-REF-44',
            'valid_until' => now()->addDays(30)->toDateString(),
            'source_evidence' => 'Original certificate was shown by the exporter; validate its number against the actual authority.',
        ];
        $this->as($a, 'logistics')->post(route('shipments.clearance.submit',
            $a['shipment']->id), $payload)->assertSessionHasNoErrors();
        $record = ExportClearanceCheck::firstOrFail();
        $this->assertSame('pending_review', $record->status);
        $this->post(route('shipments.clearance.review', [
            'shipment' => $a['shipment']->id, 'record' => $record->id, 'action' => 'accept',
        ]), [
            'note' => 'The submitting employee cannot mark this document reviewed.',
        ])->assertForbidden();
        $this->as($b,'owner')->post(route('shipments.clearance.review',[
            'shipment' => $a['shipment']->id, 'record' => $record->id, 'action' => 'accept',
        ]),[
            'note' => 'Must not review another exporter document.',
        ])->assertNotFound();
        $this->as($a,'owner')->post(route('shipments.clearance.review',[
            'shipment' => $a['shipment']->id, 'record' => $record->id, 'action' => 'accept',
        ]),[
            'note' => 'Original paperwork and issuing body reference were checked separately.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('internally_checked',$record->fresh()->status);
        $this->assertSame('draft',$a['shipment']->fresh()->status);
        $this->get(route('shipments.show',$a['shipment']->id))
            ->assertOk()->assertSee('Internal records are not government approvals')
            ->assertSee('PHYTO-REF-44');
    }
}
