<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\FinanceExchangeRate;
use App\Models\OrderAmendment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\OrderOperations;
use App\Services\OrderSourcing;
use App\Services\SalesWorkflow;
use App\Services\StockAvailability;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderAmendmentCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(string $code = 'STK'): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Stock Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        $accountant = User::factory()->create();
        $warehouse = User::factory()->create();
        foreach ([[$owner, 'owner'], [$accountant, 'accountant'], [$warehouse, 'warehouse']] as [$user,$role]) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => $role, 'active' => true]);
        }
        app(FinanceSetup::class)->activate($business);
        $product = Product::create(['business_id' => $business->id, 'name' => 'Avocado', 'sku' => 'AVO', 'base_unit' => 'kg', 'active' => true]);
        $batch = StockBatch::create(['business_id' => $business->id, 'product_id' => $product->id, 'batch_code' => 'BATCH-'.$code, 'received_on' => '2026-10-27']);
        StockMovement::create(['business_id' => $business->id, 'batch_id' => $batch->id, 'movement_type' => 'received', 'quantity' => '10', 'unit' => 'kg', 'source_type' => 'fixture_receipt', 'source_id' => 1, 'created_by' => $owner->id]);

        return compact('business', 'owner', 'accountant', 'warehouse', 'product', 'batch');
    }

    private function order(array $c, bool $priced = false): SalesOrder
    {
        $customer = Customer::create(['business_id' => $c['business']->id, 'customer_code' => 'B-'.Str::ulid(), 'name' => 'Order importer', 'country_code' => 'AE', 'currency' => $priced ? 'USD' : 'UGX', 'active' => true]);
        $sales = app(SalesWorkflow::class);
        $request = $sales->createRequest($c['business']->id, ['customer_id' => $customer->id, 'items' => [['product_id' => $c['product']->id, 'quantity' => '10']]], $c['owner']->id);
        if (! $priced) {
            return app(OrderOperations::class)->approve($c['business'], $request->id, $c['owner']->id, 'Verified actual buyer quantity-only order instruction.');
        }$rate = app(FinanceRates::class)->propose($c['business'], ['currency' => 'USD', 'effective_on' => '2026-10-27', 'rate_to_base' => '3700', 'source_note' => 'Verified manual buyer quote conversion.'], $c['owner']->id);
        app(FinanceRates::class)->approve($rate, $c['accountant']->id);
        $quote = $sales->draft($c['business']->id, $request->id, $c['owner']->id);
        $sales->updateDraft($quote, ['currency' => 'USD', 'pricing_exchange_rate_id' => $rate->id, 'payment_terms_days' => 14, 'discount_percent' => 0, 'tax_percent' => 0, 'valid_until' => '2026-11-03', 'items' => [['product_id' => $c['product']->id, 'quantity' => '10', 'unit_price' => '2']]]);
        $sales->issue($quote, $c['owner']->id);

        return $sales->confirm($quote, 'Actual buyer original price acceptance.', $c['owner']->id);
    }

    private function data(SalesOrder $order, array $extra = []): array
    {
        return [...['lines' => [['id' => $order->lines()->firstOrFail()->id, 'quantity' => '15', 'closed_quantity' => '0', 'unit_price' => $order->pricing_status === 'accepted' ? '3.0007' : null]], 'buyer_mode' => 'external', 'buyer_name' => 'Actual buyer', 'buyer_reference' => 'BUYER-CHANGE-001', 'buyer_evidence' => 'Verified signed purchase order change retained in original buyer correspondence.', 'reason' => 'Buyer changed the requested quantities and delivery arrangement.', 'request_key' => (string) Str::uuid()], ...$extra];
    }

    private function propose(array $c, SalesOrder $order, array $data): OrderAmendment
    {
        $this->actingAs($c['owner'])->withSession(['active_business_id' => $c['business']->id])->post(route('order-amendments.store', $order->id), $data)->assertRedirect()->assertSessionHasNoErrors();

        return OrderAmendment::latest('id')->firstOrFail();
    }

    private function approve(array $c, OrderAmendment $row): void
    {
        $this->actingAs($c['accountant'])->post(route('order-amendments.review', [$row->id, 'approve']), ['note' => 'An accountant without order-amendment authority must not approve.'])->assertForbidden();
        $admin = User::factory()->create();
        BusinessMembership::create(['business_id' => $c['business']->id, 'user_id' => $admin->id, 'role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->post(route('order-amendments.review', [$row->id, 'approve']), ['note' => 'Independently verified buyer agreement and current source quantities.'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_reviewed_quantity_changes_keep_unpriced_amounts_empty_and_historical_lines(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $data = $this->data($order);
        $row = $this->propose($c, $order, $data);
        $this->propose($c, $order, $data);
        $this->assertDatabaseCount('order_amendments', 1);
        $this->post(route('order-amendments.review', [$row->id, 'approve']), ['note' => 'Own proposed order changes require separate review.'])->assertStatus(422);
        $this->approve($c, $row);
        $order->refresh();
        $this->assertSame('unpriced', $order->pricing_status);
        $this->assertNull($order->grand_total);
        $this->assertNull($order->lines()->firstOrFail()->unit_price);
        $this->assertTrue(BigDecimal::of((string) $order->lines()->firstOrFail()->quantity)->isEqualTo('15'));
        $this->get(route('order-amendments.index', $order->id))->assertOk()->assertSee($row->reference);
    }

    public function test_order_reduction_requires_sources_and_reservations_to_be_reconciled_first(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $line = $order->lines()->firstOrFail();
        $reservation = app(OrderSourcing::class)->reserve($line, $c['batch'], ['quantity' => '8', 'reason' => 'Allocate actual available source stock for buyer demand.', 'request_key' => (string) Str::uuid()], $c['owner']->id);
        $data = $this->data($order, ['lines' => [['id' => $line->id, 'quantity' => '5', 'closed_quantity' => '0']]]);
        $this->actingAs($c['owner'])->withSession(['active_business_id' => $c['business']->id])->post(route('order-amendments.store', $order->id), $data)->assertStatus(422);
        $this->post(route('order-work.release', $order->id), ['kind' => 'reservation', 'record_id' => $reservation->id, 'reason' => 'Buyer reduction releases the unused reservation with its source preserved.'])->assertRedirect()->assertSessionHasNoErrors();
        $row = $this->propose($c, $order, $data);
        $this->approve($c, $row);
        $this->assertTrue(BigDecimal::of((string) $line->fresh()->quantity)->isEqualTo('5'));
        $this->assertNotNull($reservation->fresh()->released_at);
        $this->assertTrue(app(StockAvailability::class)->available($c['business']->id, $c['batch']->id)->isEqualTo('10'));
    }

    public function test_private_buyer_acceptance_applies_reviewed_foreign_prices_once_without_exposing_internal_currency(): void
    {
        $c = $this->context();
        $order = $this->order($c, true);
        $rate = FinanceExchangeRate::firstOrFail();
        $row = $this->propose($c, $order, $this->data($order, ['buyer_mode' => 'link', 'exchange_rate_id' => $rate->id]));
        $this->approve($c, $row);
        $this->actingAs($c['owner'])->post(route('order-amendments.link', $row->id))->assertRedirect()->assertSessionHasNoErrors();
        $url = session('amendment_url');
        $this->assertNotEmpty($url);
        $this->get($url)->assertOk()->assertSee('USD')->assertSee('3.0007')->assertDontSee('UGX')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('approved_for_buyer', $row->fresh()->status);
        $this->assertTrue(BigDecimal::of((string) $order->lines()->firstOrFail()->unit_price)->isEqualTo('2'));
        $decision = ['decision' => 'accepted', 'buyer_name' => 'Importer representative', 'buyer_reference' => 'PO-UPDATED-001'];
        $this->post($url, $decision)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $decision)->assertRedirect()->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('applied', $row->fresh()->status);
        $this->assertTrue(BigDecimal::of((string) $order->lines()->firstOrFail()->unit_price)->isEqualTo('3.0007'));
        $this->assertTrue(BigDecimal::of((string) $order->grand_total)->isEqualTo('45.01'));
        $this->assertSame('166537.00', $order->pricing_snapshot['base_grand_total']);
        $this->post($url, [...$decision, 'decision' => 'rejected'])->assertStatus(409);
        $this->assertTrue(BigDecimal::of((string) $order->quotation->grand_total)->isEqualTo('20'));
    }

    public function test_cross_tenant_amendments_and_withdrawn_buyer_links_are_refused(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $row = $this->propose($c, $order, $this->data($order, ['buyer_mode' => 'link']));
        $this->approve($c, $row);
        $this->actingAs($c['owner'])->post(route('order-amendments.link', $row->id))->assertRedirect();
        $url = session('amendment_url');
        $this->post(route('order-amendments.withdraw', $row->id), ['reason' => 'Buyer requested different changes; revoke this undecided link.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($url)->assertGone();
        $other = $this->context('AMO');
        $this->actingAs($other['owner'])->withSession(['active_business_id' => $other['business']->id])->get(route('order-amendments.index', $order->id))->assertNotFound();
    }
}
