<?php

namespace Tests\Feature;

use App\Mail\CustomerOrderingMail;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\GoodsReceipt;
use App\Models\MarketTrip;
use App\Models\OrderSupply;
use App\Models\PackingJob;
use App\Models\PartnerFollowup;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesRequest;
use App\Models\StaffPerson;
use App\Models\StockBatch;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\SupplyRequest;
use App\Models\TransportLeg;
use App\Models\TransportPartner;
use App\Models\User;
use App\Services\CustomerOrdering;
use App\Services\GoodsReceiving;
use App\Services\OrderOperations;
use App\Services\OrderSourcing;
use App\Services\PackingOperations;
use App\Services\SalesWorkflow;
use App\Services\ShipmentOperations;
use App\Services\StockAvailability;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatingSpineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
    }

    private function context(string $code = 'KLA'): array
    {
        $business = Business::create(['name' => $code.' Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        BusinessMembership::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'owner', 'active' => true]);
        $person = StaffPerson::create(['business_id' => $business->id, 'user_id' => $owner->id, 'name' => 'Sarah Buyer', 'staff_code' => 'SARAH', 'kind' => 'employee']);
        $customer = Customer::create(['business_id' => $business->id, 'name' => 'Dubai Buyer', 'customer_code' => 'BUYER', 'country_code' => 'AE', 'currency' => 'USD', 'email' => 'buyer-'.$code.'@example.test', 'active' => true]);
        $product = Product::create(['business_id' => $business->id, 'sku' => 'AVO', 'name' => 'Export Avocado', 'base_unit' => 'kg', 'active' => true]);

        return compact('business', 'owner', 'customer', 'product', 'person');
    }

    private function as(array $c): self
    {
        return $this->actingAs($c['owner'])->withSession(['active_business_id' => $c['business']->id]);
    }

    private function demand(array $c, string $quantity = '100'): SalesRequest
    {
        return app(SalesWorkflow::class)->createRequest($c['business']->id, ['customer_id' => $c['customer']->id, 'required_on' => '2026-10-28', 'destination' => 'Dubai', 'items' => [['product_id' => $c['product']->id, 'quantity' => $quantity]]], $c['owner']->id);
    }

    private function order(array $c, string $quantity = '100'): SalesOrder
    {
        $r = $this->demand($c, $quantity);
        $this->as($c)->post(route('sales-requests.approve', $r), ['reason' => 'Owner authorizes quantity-only export fulfilment'])->assertRedirect()->assertSessionHasNoErrors();

        return $r->order()->firstOrFail();
    }

    private function purchase(array $c, string $quantity = '100', array $extra = []): PurchaseOrder
    {
        return PurchaseOrder::create(['business_id' => $c['business']->id, 'reference' => 'PO-'.Str::random(8), 'kind' => 'market', 'vendor_name' => 'Nakasero stall', 'market_location' => 'Nakasero', 'product_id' => $c['product']->id, 'quantity' => $quantity, 'unit_price' => '1500', 'estimated_total' => (string) BigDecimal::of($quantity)->multipliedBy(1500), 'currency' => 'UGX', 'purchased_on' => '2026-10-27', 'status' => 'ordered', 'idempotency_key' => (string) Str::uuid(), 'created_by' => $c['owner']->id, ...$extra]);
    }

    private function receipt(array $c, PurchaseOrder $p, string $accepted, string $rejected = '0'): GoodsReceipt
    {
        return app(GoodsReceiving::class)->post($p, ['idempotency_key' => (string) Str::uuid(), 'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected, 'received_on' => '2026-10-27'], $c['owner']->id);
    }

    private function commit(array $c, SalesOrder $o, PurchaseOrder $p, string $qty): OrderSupply
    {
        $line = $o->lines()->first();
        $this->as($c)->post(route('order-work.supply', [$o, $line]), ['kind' => 'purchase', 'purchase_order_id' => $p->id, 'quantity' => $qty, 'reason' => 'Supplier commitment to this export order', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        return OrderSupply::latest('id')->firstOrFail();
    }

    private function pack(array $c, SalesOrder $o, StockBatch $batch, string $qty): Carton
    {
        $line = $o->lines()->first();
        $this->as($c)->post(route('order-work.packing', [$o, $line]), ['request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $job = PackingJob::latest('id')->firstOrFail();
        $carton = app(PackingOperations::class)->packCarton($job, ['packing_key' => (string) Str::uuid(), 'contents' => [['batch_id' => $batch->id, 'quantity' => $qty]], 'net_weight_kg' => $qty, 'gross_weight_kg' => (string) BigDecimal::of($qty)->plus('1')], $c['owner']->id);
        app(PackingOperations::class)->complete($job, $c['owner']->id);

        return $carton;
    }

    private function shipment(array $c, SalesOrder $o, array $cartons): ExportShipment
    {
        $s = app(ShipmentOperations::class)->create($c['business']->id, ['creation_key' => (string) Str::uuid(), 'customer_id' => $c['customer']->id, 'sales_order_id' => $o->id, 'origin_country' => 'UG', 'destination_country' => 'AE', 'origin_airport' => 'EBB', 'destination_airport' => 'DXB', 'flight_number' => 'EK730', 'air_waybill' => '176-12345675', 'scheduled_departure_at' => '2026-10-28 12:00:00'], $c['owner']->id);
        app(ShipmentOperations::class)->allocate($s, $cartons, $c['owner']->id);

        return $s;
    }

    public function test_quantity_only_order_can_source_pack_and_fulfil_before_pricing_without_zero_value_sale(): void
    {
        $c = $this->context();
        $o = $this->order($c, '10');
        $this->assertNull($o->grand_total);
        $this->assertNull($o->sales_quotation_id);
        $this->assertSame('unpriced', $o->pricing_status);
        $this->assertNull($o->lines()->first()->unit_price);
        $p = $this->purchase($c, '10');
        $this->commit($c, $o, $p, '10');
        $r = $this->receipt($c, $p, '10');
        $carton = $this->pack($c, $o, $r->batch, '10');
        $s = $this->shipment($c, $o, [$carton->id]);
        $ops = app(ShipmentOperations::class);
        $ops->ready($s, $c['owner']->id);
        $ops->dispatch($s, '2026-10-27 12:00:00', $c['owner']->id);
        $ops->arrive($s, '2026-10-27 13:00:00', $c['owner']->id);
        $this->assertSame('delivered', app(OrderOperations::class)->progress($o->fresh())['stage']);
        $this->assertTrue(app(StockAvailability::class)->available($c['business']->id, $r->batch_id)->isZero());
        $this->post(route('commercial-invoices.issue', $s), ['freight_amount' => '0', 'insurance_amount' => '0', 'notes' => 'Invoice attempted before prices'])->assertStatus(422);
        $this->post(route('order-work.close', $o), ['action' => 'complete', 'reason' => 'All cartons delivered and customer confirms completion'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $o->fresh()->status);
        $this->assertDatabaseCount('commercial_invoices', 0);
    }

    public function test_one_order_splits_two_suppliers_and_own_market_buying_without_double_counting(): void
    {
        $c = $this->context();
        $o = $this->order($c, '1000');
        $line = $o->lines()->first();
        $a = $this->purchase($c, '400', ['vendor_name' => 'Supplier A']);
        $b = $this->purchase($c, '350', ['vendor_name' => 'Supplier B']);
        $this->commit($c, $o, $a, '400');
        $this->commit($c, $o, $b, '350');
        $trip = MarketTrip::create(['business_id' => $c['business']->id, 'reference' => 'MARKET-1', 'staff_person_id' => $c['person']->id, 'location' => 'Nakasero', 'due_at' => now()->addHours(2), 'budget' => '1200000', 'currency' => 'UGX', 'request_key' => (string) Str::uuid(), 'created_by' => $c['owner']->id]);
        $this->post(route('order-work.supply', [$o, $line]), ['kind' => 'market', 'market_trip_id' => $trip->id, 'quantity' => '250', 'reason' => 'Sarah buys the remaining demand at market', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $target = OrderSupply::latest('id')->firstOrFail();
        $this->receipt($c, $a, '390', '10');
        $this->receipt($c, $b, '210', '10');
        $this->post(route('purchases.store'), ['kind' => 'market', 'vendor_name' => 'Market vendor 1', 'market_location' => 'Nakasero', 'product_id' => $c['product']->id, 'market_trip_id' => $trip->id, 'sales_order_line_id' => $line->id, 'origin_supply_id' => $target->id, 'purchased_on' => '2026-10-27', 'quantity' => '150', 'unit_price' => '1500', 'currency' => 'UGX', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $market = PurchaseOrder::latest('id')->firstOrFail();
        $this->receipt($c, $market, '150');
        $summary = app(OrderSourcing::class)->summary($line);
        $this->assertTrue(BigDecimal::of($summary['reserved'])->isEqualTo('750'));
        $this->assertTrue(BigDecimal::of($summary['market_pending'])->isEqualTo('100'));
        $this->assertTrue(BigDecimal::of($summary['expected'])->isEqualTo('130'));
        $this->assertTrue(BigDecimal::of($summary['shortage'])->isEqualTo('20'));
        $this->assertTrue(BigDecimal::of($summary['rejected'])->isEqualTo('20'));
    }

    public function test_bulk_purchase_allocates_receipts_across_orders_once_and_exposes_reject_shortage(): void
    {
        $c = $this->context();
        $a = $this->order($c, '60');
        $b = $this->order($c, '40');
        $p = $this->purchase($c, '100');
        $this->commit($c, $a, $p, '60');
        $this->commit($c, $b, $p, '40');
        $payload = ['idempotency_key' => (string) Str::uuid(), 'accepted_quantity' => '90', 'rejected_quantity' => '10', 'received_on' => '2026-10-27'];
        $receiving = app(GoodsReceiving::class);
        $r = $receiving->post($p, $payload, $c['owner']->id);
        $receiving->post($p, $payload, $c['owner']->id);
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->assertDatabaseCount('stock_reservations', 2);
        $this->assertDatabaseCount('order_receipt_allocations', 2);
        $sum = app(OrderSourcing::class)->summary($b->lines()->first());
        $this->assertTrue(BigDecimal::of($sum['shortage'])->isEqualTo('10'));
        $this->assertTrue(BigDecimal::of($sum['reserved'])->isEqualTo('30'));
        $this->assertTrue(BigDecimal::of((string) app(OrderSourcing::class)->free($r->batch))->isZero());
    }

    public function test_reserved_stock_cannot_be_reserved_or_consumed_by_another_order_or_unlinked_job(): void
    {
        $c = $this->context();
        $a = $this->order($c, '10');
        $b = $this->order($c, '10');
        $r = $this->receipt($c, $this->purchase($c, '10'), '10');
        $line = $a->lines()->first();
        $payload = ['batch_id' => $r->batch_id, 'quantity' => '10', 'reason' => 'Reserve cleared batch for first export', 'request_key' => (string) Str::uuid()];
        $this->post(route('order-work.reserve', [$a, $line]), $payload)->assertSessionHasNoErrors();
        $this->post(route('order-work.reserve', [$a, $line]), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->post(route('order-work.reserve', [$b, $b->lines()->first()]), [...$payload, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('quantity');
        $job = PackingJob::create(['business_id' => $c['business']->id, 'product_id' => $c['product']->id, 'reference' => 'UNLINKED', 'status' => 'open', 'created_by' => $c['owner']->id]);
        $this->post(route('packing.pack', $job), ['packing_key' => (string) Str::uuid(), 'contents' => [['batch_id' => $r->batch_id, 'quantity' => '10']], 'net_weight_kg' => '10', 'gross_weight_kg' => '11'])->assertSessionHasErrors('contents');
        $this->assertDatabaseCount('cartons', 0);
    }

    public function test_sorting_loss_releases_no_fake_stock_and_requires_replacement_quantity(): void
    {
        $c = $this->context();
        $o = $this->order($c, '10');
        $p = $this->purchase($c, '10');
        $this->commit($c, $o, $p, '10');
        $r = $this->receipt($c, $p, '10');
        $line = $o->lines()->first();
        $this->post(route('order-work.packing', [$o, $line]), ['request_key' => (string) Str::uuid()]);
        $job = PackingJob::latest('id')->firstOrFail();
        $this->post(route('packing.loss', $job), ['idempotency_key' => (string) Str::uuid(), 'batch_id' => $r->batch_id, 'quantity' => '2', 'reason' => 'sorting_reject', 'notes' => 'Poor grade removed during sorting'])->assertSessionHasNoErrors();
        $summary = app(OrderSourcing::class)->summary($line);
        $this->assertTrue(BigDecimal::of($summary['shortage'])->isEqualTo('2'));
        $this->assertTrue(BigDecimal::of($summary['reserved'])->isEqualTo('8'));
        $this->assertTrue(BigDecimal::of($summary['loss'])->isEqualTo('2'));
    }

    public function test_order_owned_carton_cannot_be_allocated_to_another_order(): void
    {
        $c = $this->context();
        $a = $this->order($c, '10');
        $b = $this->order($c, '10');
        $p = $this->purchase($c, '10');
        $this->commit($c, $a, $p, '10');
        $r = $this->receipt($c, $p, '10');
        $carton = $this->pack($c, $a, $r->batch, '10');
        $s = $this->shipment($c, $b, []);
        $this->post(route('shipments.allocate', $s), ['carton_ids' => [$carton->id]])->assertStatus(422);
        $this->assertDatabaseCount('shipment_cartons', 0);
    }

    public function test_cross_tenant_order_line_purchase_batch_and_task_actions_are_refused(): void
    {
        $a = $this->context('A');
        $b = $this->context('B');
        $oa = $this->order($a, '10');
        $ob = $this->order($b, '10');
        $p = $this->purchase($b, '10');
        $r = $this->receipt($b, $p, '10');
        $line = $oa->lines()->first();
        $this->as($a)->post(route('order-work.reserve', [$oa, $ob->lines()->first()]), ['batch_id' => $r->batch_id, 'quantity' => '1', 'reason' => 'Cross tenant must not reserve produce', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->post(route('order-work.supply', [$oa, $line]), ['kind' => 'purchase', 'purchase_order_id' => $p->id, 'quantity' => '1', 'reason' => 'Cross tenant purchase must be refused', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->post(route('order-work.task', $oa), ['staff_person_id' => $b['person']->id, 'title' => 'Wrong staff', 'kind' => 'packing', 'due_at' => '2026-10-28T12:00', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->get(route('sales-orders.show', $ob))->assertNotFound();
    }

    public function test_ordinary_buyer_cannot_approve_demand_or_edit_selling_prices(): void
    {
        $c = $this->context();
        $o = $this->order($c);
        $buyer = User::factory()->create();
        BusinessMembership::create(['business_id' => $c['business']->id, 'user_id' => $buyer->id, 'role' => 'buyer', 'active' => true]);
        $this->actingAs($buyer)->get(route('sales-orders.show', $o))->assertOk();
        $this->get(route('sales-orders.show', [$o, 'tab' => 'money']))->assertForbidden();
        $this->post(route('sales-requests.approve', $this->demand($c)), ['reason' => 'An unauthorized operational approval'])->assertForbidden();
    }

    public function test_approved_quantity_order_can_gain_prices_without_losing_supply_history(): void
    {
        $c = $this->context();
        $o = $this->order($c, '10');
        $p = $this->purchase($c, '10');
        $this->commit($c, $o, $p, '10');
        $this->post(route('sales-requests.draft', $o->sales_request_id))->assertSessionHasNoErrors();
        $q = SalesQuotation::firstOrFail();
        $this->put(route('sales-quotes.update', $q), ['currency' => 'USD', 'discount_percent' => '0', 'tax_percent' => '0', 'items' => [['product_id' => $c['product']->id, 'quantity' => '10', 'unit_price' => '2.50']]])->assertSessionHasNoErrors();
        $this->post(route('sales-quotes.issue', $q))->assertSessionHasNoErrors();
        $this->post(route('sales-quotes.confirm', $q), ['buyer_confirmation' => 'Verified buyer PO reference 1001'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('order_supplies', 1);
        $this->assertSame('accepted', $o->fresh()->pricing_status);
        $this->assertTrue(BigDecimal::of((string) $o->fresh()->grand_total)->isEqualTo('25'));
    }

    public function test_customer_submission_uses_invited_products_is_repeat_safe_and_has_no_staff_seat(): void
    {
        Mail::fake();
        $c = $this->context();
        $link = app(CustomerOrdering::class)->invite($c['business'], $c['customer'], [$c['product']->id], $c['owner']->id);
        $token = null;
        Mail::assertSent(CustomerOrderingMail::class, function ($mail) use (&$token) {
            $token = $mail->token;

            return true;
        });
        $this->get(route('customer-ordering.show', $token))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
        $payload = ['submission_key' => (string) Str::uuid(), 'destination' => 'Dubai', 'buyer_reference' => 'BUY-PRIVATE', 'required_on' => '2026-11-01', 'items' => [['product_id' => $c['product']->id, 'quantity' => '10']]];
        $this->post(route('customer-ordering.store', $token), $payload)->assertSessionHasNoErrors();
        $this->post(route('customer-ordering.store', $token), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_requests', 1);
        $r = SalesRequest::firstOrFail();
        $this->assertNull($r->created_by);
        $this->assertSame('customer', $r->source);
        $this->assertDatabaseCount('business_memberships', 1);
        $this->post(route('customer-ordering.store', $token), [...$payload, 'destination' => 'Changed destination'])->assertStatus(409);
        $o = $this->orderFromRequest($c, $r);
        app(OrderOperations::class)->event($o, $c['owner']->id, 'internal.note', 'SECRET BUYING COST', []);
        app(OrderOperations::class)->event($o, $c['owner']->id, 'customer.update', 'Verified publish', [], 'Your produce is being prepared.');
        $this->get(route('customer-ordering.request', [$token, $r]))->assertOk()->assertSee('Your produce is being prepared.')->assertDontSee('SECRET BUYING COST');
    }

    private function orderFromRequest(array $c, SalesRequest $r): SalesOrder
    {
        return app(OrderOperations::class)->approve($c['business'], $r->id, $c['owner']->id, 'Operational approval after customer submission.');
    }

    public function test_customer_links_expire_revoke_and_never_open_another_customer_request(): void
    {
        Mail::fake();
        $a = $this->context('A');
        $b = $this->context('B');
        $link = app(CustomerOrdering::class)->invite($a['business'], $a['customer'], [$a['product']->id], $a['owner']->id);
        $token = null;
        Mail::assertSent(CustomerOrderingMail::class, function ($mail) use (&$token) {
            $token = $mail->token;

            return true;
        });
        $other = $this->demand($b);
        $this->get(route('customer-ordering.request', [$token, $other]))->assertNotFound();
        $this->post(route('customer-ordering.store', $token), ['submission_key' => (string) Str::uuid(), 'destination' => 'Dubai', 'required_on' => '2026-11-01', 'items' => [['product_id' => $b['product']->id, 'quantity' => '10']]])->assertNotFound();
        $this->as($a)->post(route('customer-ordering.revoke', $link))->assertRedirect();
        $this->get(route('customer-ordering.show', $token))->assertNotFound();
        $this->assertDatabaseCount('sales_requests', 1);
    }

    public function test_every_order_tab_people_and_partner_history_render_for_unpriced_order(): void
    {
        $c = $this->context();
        $o = $this->order($c);
        foreach (['overview', 'supply', 'preparation', 'delivery', 'money', 'history'] as $tab) {
            $this->get(route('sales-orders.show', [$o, 'tab' => $tab]))->assertOk();
        }
        $this->get(route('people.index'))->assertOk();
        $this->get(route('partners.receiving'))->assertOk();
        $this->get(route('partners.show', ['customer', $c['customer']]))->assertOk();
        $this->get(route('customers.index'))->assertOk();
        $this->get(route('workspace'))->assertOk()->assertSee('What needs attention today?');
    }

    public function test_supplier_date_report_filters_before_export_and_preserves_units_receiver_and_vendor(): void
    {
        $c = $this->context();
        $p = $this->purchase($c, '10', ['vendor_name' => '=Dangerous vendor']);
        $r = $this->receipt($c, $p, '8', '2');
        $this->as($c)->get(route('partners.receiving', ['from' => '2026-10-27', 'to' => '2026-10-27', 'vendor' => 'Dangerous']))->assertOk()->assertSee($r->reference);
        $response = $this->get(route('partners.receiving', ['from' => '2026-10-27', 'to' => '2026-10-27', 'vendor' => 'Dangerous', 'output' => 'csv']))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=Dangerous vendor", $csv);
        $this->assertStringContainsString($c['owner']->name, $csv);
        $this->assertStringContainsString('kg', $csv);
        $this->get(route('partners.receiving', ['from' => '2026-10-28', 'to' => '2026-10-29']))->assertDontSee($r->reference);
    }

    public function test_source_picker_search_finds_older_batches_and_purchase_without_exposing_other_tenants(): void
    {
        $c = $this->context();
        $o = $this->order($c);
        $line = $o->lines()->first();
        $p = $this->purchase($c, '10', ['reference' => 'OLD-PURCHASE']);
        $r = $this->receipt($c, $this->purchase($c, '10'), '10');
        for ($i = 0; $i < 25; $i++) {
            $this->purchase($c, '1');
        }
        $url = route('order-work.candidates', [$o, $line]);
        $this->getJson($url.'?kind=purchase&search=OLD-PURCHASE')->assertOk()->assertJsonPath('items.0.id', $p->id);
        $this->getJson($url.'?kind=batch&search='.$r->batch->batch_code)->assertOk()->assertJsonPath('items.0.id', $r->batch_id);
    }

    public function test_two_transport_legs_require_ordered_custody_evidence_and_frozen_cartons(): void
    {
        $c = $this->context();
        $o = $this->order($c, '10');
        $p = $this->purchase($c, '10');
        $this->commit($c, $o, $p, '10');
        $r = $this->receipt($c, $p, '10');
        $carton = $this->pack($c, $o, $r->batch, '10');
        $shipment = $this->shipment($c, $o, [$carton->id]);
        $partner = TransportPartner::create(['business_id' => $c['business']->id, 'partner_code' => 'DRIVER', 'name' => 'Airport Transport', 'phone' => '0700000000']);
        $payload = ['sequence' => 1, 'transport_partner_id' => $partner->id, 'staff_person_id' => $c['person']->id, 'mode' => 'road', 'origin' => 'Packhouse', 'destination' => 'Entebbe Airport', 'due_at' => '2026-10-28T10:00', 'driver_name' => 'Moses', 'vehicle_reference' => 'UBX-123A', 'booking_reference' => 'AIRPORT-01', 'carton_ids' => [$carton->id], 'request_key' => (string) Str::uuid()];
        $this->post(route('transport.legs.store', $shipment), $payload)->assertSessionHasNoErrors();
        $first = TransportLeg::firstOrFail();
        $this->post(route('transport.legs.store', $shipment), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transport_legs', 1);
        $secondPayload = [...$payload, 'sequence' => 2, 'mode' => 'air', 'origin' => 'Entebbe Airport', 'destination' => 'Dubai Airport', 'due_at' => '2026-10-28T20:00', 'request_key' => (string) Str::uuid()];
        $this->post(route('transport.legs.store', $shipment), $secondPayload)->assertSessionHasNoErrors();
        $second = TransportLeg::latest('id')->first();
        $evidence = ['action' => 'collected', 'from_person' => 'Packhouse supervisor', 'to_person' => 'Moses driver', 'occurred_at' => '2026-10-27T14:00', 'evidence_reference' => 'SIGNED-001', 'request_key' => (string) Str::uuid()];
        $this->post(route('transport.handover', $second), $evidence)->assertStatus(422);
        $this->post(route('transport.handover', $first), $evidence)->assertSessionHasNoErrors();
        $this->post(route('transport.handover', $first), $evidence)->assertSessionHasNoErrors();
        $this->post(route('transport.handover', $first), [...$evidence, 'action' => 'handed_over', 'from_person' => 'Moses driver', 'to_person' => 'Air cargo desk', 'occurred_at' => '2026-10-27T14:30', 'evidence_reference' => 'CARGO-001', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('transport.handover', $second), [...$evidence, 'occurred_at' => '2026-10-27T14:45', 'evidence_reference' => 'AIR-CUSTODY-01', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->delete(route('shipments.release', [$shipment, $carton]))->assertStatus(422);
        $this->delete(route('transport.legs.cancel', $first), ['reason' => 'Cannot erase actual custody evidence'])->assertStatus(422);
        $this->assertDatabaseCount('transport_handovers', 3);
        $partner->update(['name' => 'Renamed transport provider']);
        $this->assertSame('Airport Transport', $first->fresh()->partner_snapshot['name']);
        $this->get(route('sales-orders.show', [$o, 'tab' => 'delivery']))->assertOk()->assertSee('Moses')->assertSee('UBX-123A')->assertSee('CARGO-001');
    }

    public function test_unused_transport_plan_can_be_cancelled_and_replaced_with_history(): void
    {
        $c = $this->context();
        $o = $this->order($c, '10');
        $p = $this->purchase($c, '10');
        $this->commit($c, $o, $p, '10');
        $r = $this->receipt($c, $p, '10');
        $carton = $this->pack($c, $o, $r->batch, '10');
        $shipment = $this->shipment($c, $o, [$carton->id]);
        $partner = TransportPartner::create(['business_id' => $c['business']->id, 'partner_code' => 'VAN', 'name' => 'Local van']);
        $payload = ['sequence' => 1, 'transport_partner_id' => $partner->id, 'staff_person_id' => $c['person']->id, 'mode' => 'road', 'origin' => 'Packhouse', 'destination' => 'Airport', 'due_at' => '2026-10-28T10:00', 'carton_ids' => [$carton->id], 'request_key' => (string) Str::uuid()];
        $this->post(route('transport.legs.store', $shipment), $payload)->assertSessionHasNoErrors();
        $leg = TransportLeg::firstOrFail();
        $this->delete(route('transport.legs.cancel', $leg), ['reason' => 'Original transporter unavailable; booking cancelled'])->assertSessionHasNoErrors();
        $this->post(route('transport.legs.store', $shipment), [...$payload, 'sequence' => 2, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $leg->fresh()->status);
        $this->assertDatabaseCount('transport_legs', 2);
    }

    public function test_partial_supplier_awards_keep_remaining_request_open_and_refuse_overaward(): void
    {
        $c = $this->context();
        $this->as($c);
        $rfq = SupplyRequest::create(['business_id' => $c['business']->id, 'reference' => 'RFQ-SPLIT', 'product_id' => $c['product']->id, 'quantity' => '100', 'currency' => 'UGX', 'response_deadline' => now()->addDay(), 'status' => 'sent', 'created_by' => $c['owner']->id]);
        $offers = [];
        foreach (['A', 'B'] as $name) {
            $supplier = Supplier::create(['business_id' => $c['business']->id, 'supplier_code' => $name, 'name' => 'Farm '.$name, 'active' => true]);
            $offers[] = SupplierQuote::create(['business_id' => $c['business']->id, 'supply_request_id' => $rfq->id, 'supplier_id' => $supplier->id, 'quantity' => '100', 'unit_price' => '1500', 'currency' => 'UGX', 'status' => 'submitted', 'submitted_at' => now(), 'delivery_on' => '2026-10-28']);
        }
        $this->post(route('supply-requests.award', [$rfq, $offers[0]]), ['quantity' => '60'])->assertSessionHasNoErrors();
        $this->assertSame('part_awarded', $rfq->fresh()->status);
        $this->post(route('supply-requests.award', [$rfq, $offers[1]]), ['quantity' => '50'])->assertSessionHasErrors('quantity');
        $this->post(route('supply-requests.award', [$rfq, $offers[1]]), ['quantity' => '40'])->assertSessionHasNoErrors();
        $this->assertSame('awarded', $rfq->fresh()->status);
        $this->assertDatabaseCount('purchase_orders', 2);
        $this->get(route('supply-requests.show', $rfq))->assertOk();
    }

    public function test_cancellation_releases_reservations_and_stops_new_work_without_deleting_receipts(): void
    {
        $c = $this->context();
        $o = $this->order($c, '10');
        $p = $this->purchase($c, '10');
        $this->commit($c, $o, $p, '10');
        $r = $this->receipt($c, $p, '10');
        $line = $o->lines()->first();
        $this->post(route('order-work.close', $o), ['action' => 'cancel', 'reason' => 'Buyer withdrew demand before any preparation or shipment'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $o->fresh()->status);
        $closedPosition = app(OrderSourcing::class)->summary($line->fresh());
        $this->assertCount(1, $closedPosition['supplies']);
        $this->assertTrue(BigDecimal::of($closedPosition['accepted'])->isEqualTo('10'));
        $this->assertTrue(BigDecimal::of($closedPosition['shortage'])->isZero());
        $this->assertNotNull(StockReservation::first()->released_at);
        $this->assertTrue(app(OrderSourcing::class)->free($r->batch)->isEqualTo('10'));
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->post(route('order-work.packing', [$o, $line]), ['request_key' => (string) Str::uuid()])->assertStatus(422);
    }

    public function test_multi_batch_carton_cannot_exceed_order_even_when_each_batch_would_fit(): void
    {
        $c = $this->context();
        $o = $this->order($c, '10');
        $p = $this->purchase($c, '10');
        $this->commit($c, $o, $p, '10');
        $r = $this->receipt($c, $p, '6');
        $second = $this->receipt($c, $p, '4');
        $line = $o->lines()->first();
        $this->post(route('order-work.packing', [$o, $line]), ['request_key' => (string) Str::uuid()]);
        $job = PackingJob::latest('id')->firstOrFail();
        $this->post(route('packing.pack', $job), ['packing_key' => (string) Str::uuid(), 'contents' => [['batch_id' => $r->batch_id, 'quantity' => '6'], ['batch_id' => $second->batch_id, 'quantity' => '6']], 'net_weight_kg' => '12', 'gross_weight_kg' => '13'])->assertSessionHasErrors('contents');
        $this->assertDatabaseCount('cartons', 0);
        $this->assertTrue(BigDecimal::of((string) StockReservation::first()->consumed_quantity)->isZero());
    }

    public function test_partner_performance_has_a_sample_and_followups_have_owner_deadline_and_audit(): void
    {
        $c = $this->context();
        $supplier = Supplier::create(['business_id' => $c['business']->id, 'supplier_code' => 'FARM', 'name' => 'Performance Farm']);
        $p = $this->purchase($c, '10', ['kind' => 'supplier', 'supplier_id' => $supplier->id, 'expected_on' => '2026-10-27']);
        $this->receipt($c, $p, '8', '2');
        $this->as($c)->get(route('partners.show', ['supplier', $supplier]))->assertOk()->assertViewHas('performance', fn ($data) => $data['due_promises'] === 1 && $data['on_time'] === 1 && $data['products'][$c['product']->id]['receipts'] === 1);
        $this->post(route('partners.followup', ['supplier', $supplier]), ['staff_person_id' => $c['person']->id, 'title' => 'Replace poor grade produce', 'due_at' => '2026-10-28T10:00', 'note' => 'Supplier to replace the two rejected kilograms'])->assertSessionHasNoErrors();
        $followup = PartnerFollowup::firstOrFail();
        $this->assertSame('2026-10-28 07:00:00', $followup->due_at->format('Y-m-d H:i:s'));
        $this->get(route('workspace'))->assertOk()->assertSee('Replace poor grade produce');
        $this->post(route('partners.followup', ['supplier', $supplier]), ['followup_id' => $followup->id, 'staff_person_id' => $c['person']->id, 'title' => $followup->title, 'due_at' => '2026-10-28T10:00', 'status' => 'resolved', 'note' => 'Replacement received; linked receipt independently reviewed'])->assertSessionHasNoErrors();
        $this->assertSame('resolved', $followup->fresh()->status);
        $this->assertDatabaseHas('platform_audit_events', ['business_id' => $c['business']->id, 'action' => 'partner.followup']);
    }

    public function test_reservation_release_picker_restores_free_stock_and_preserves_its_history(): void
    {
        $c = $this->context();
        $o = $this->order($c, '10');
        $r = $this->receipt($c, $this->purchase($c, '10'), '10');
        $line = $o->lines()->first();
        $this->post(route('order-work.reserve', [$o, $line]), ['batch_id' => $r->batch_id, 'quantity' => '10', 'reason' => 'Reserve stock for this buyer demand', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $reservation = StockReservation::firstOrFail();
        $this->post(route('order-work.release', $o), ['selection' => 'reservation:'.$reservation->id, 'reason' => 'Demand reallocated with business owner approval'])->assertSessionHasNoErrors();
        $this->assertNotNull($reservation->fresh()->released_at);
        $this->assertTrue(app(OrderSourcing::class)->free($r->batch)->isEqualTo('10'));
        $this->assertDatabaseHas('order_events', ['sales_order_id' => $o->id, 'action' => 'supply.released']);
    }

    public function test_completed_priced_order_can_be_invoiced_after_operational_closure(): void
    {
        $c = $this->context();
        $o = $this->order($c, '10');
        $this->post(route('sales-requests.draft', $o->sales_request_id))->assertSessionHasNoErrors();
        $q = SalesQuotation::firstOrFail();
        $this->put(route('sales-quotes.update', $q), ['currency' => 'USD', 'discount_percent' => '0', 'tax_percent' => '0', 'items' => [['product_id' => $c['product']->id, 'quantity' => '10', 'unit_price' => '2']]])->assertSessionHasNoErrors();
        $this->post(route('sales-quotes.issue', $q))->assertSessionHasNoErrors();
        $this->post(route('sales-quotes.confirm', $q), ['buyer_confirmation' => 'Verified buyer purchase order to proceed'])->assertSessionHasNoErrors();
        $p = $this->purchase($c, '10');
        $this->commit($c, $o, $p, '10');
        $r = $this->receipt($c, $p, '10');
        $carton = $this->pack($c, $o, $r->batch, '10');
        $shipment = $this->shipment($c, $o, [$carton->id]);
        $ops = app(ShipmentOperations::class);
        $ops->ready($shipment, $c['owner']->id);
        $ops->dispatch($shipment, '2026-10-27 10:00:00', $c['owner']->id);
        $ops->arrive($shipment, '2026-10-27 11:00:00', $c['owner']->id);
        $this->post(route('order-work.close', $o), ['action' => 'complete', 'reason' => 'Delivered order completed with retained invoicing obligation'])->assertSessionHasNoErrors();
        $this->post(route('commercial-invoices.issue', $shipment), ['freight_amount' => '0', 'insurance_amount' => '0'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('commercial_invoices', 1);
    }

    public function test_changing_staff_to_viewer_revokes_task_updates_immediately(): void
    {
        $c = $this->context();
        $o = $this->order($c);
        $worker = User::factory()->create();
        $membership = BusinessMembership::create(['business_id' => $c['business']->id, 'user_id' => $worker->id, 'role' => 'packing', 'active' => true]);
        $person = StaffPerson::create(['business_id' => $c['business']->id, 'user_id' => $worker->id, 'name' => 'Assigned Worker', 'staff_code' => 'ASSIGNED']);
        $task = app(OrderOperations::class)->task($o, ['staff_person_id' => $person->id, 'title' => 'Grade produce for this order', 'kind' => 'packing', 'due_at' => '2026-10-28T10:00', 'request_key' => (string) Str::uuid()], $c['owner']->id);
        $this->actingAs($worker)->post(route('order-work.task-progress', $task), ['status' => 'in_progress', 'note' => 'Started grading the reserved produce'])->assertSessionHasNoErrors();
        $membership->update(['role' => 'viewer']);
        $this->post(route('order-work.task-progress', $task), ['status' => 'completed', 'note' => 'Read-only roles must not complete work'])->assertForbidden();
        $this->assertSame('in_progress', $task->fresh()->status);
    }
}
