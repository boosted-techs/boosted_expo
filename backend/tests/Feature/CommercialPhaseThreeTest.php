<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\Product;
use App\Models\QuotationAccess;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\FinanceRates;
use App\Services\QuotationAccessService;
use App\Services\SalesWorkflow;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CommercialPhaseThreeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(): array
    {
        $business = Business::create(['name' => 'Kampala Exporters', 'code' => 'QFX', 'base_currency' => 'UGX', 'country_code' => 'UG', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$owner, $reviewer] as $user) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true]);
        }
        $customer = Customer::create(['business_id' => $business->id, 'customer_code' => 'BUY', 'name' => 'Foreign Buyer', 'country_code' => 'AE', 'currency' => 'USD', 'active' => true]);
        $product = Product::create(['business_id' => $business->id, 'sku' => 'MANG', 'name' => 'Mango', 'base_unit' => 'kg', 'active' => true]);
        $rate = app(FinanceRates::class)->propose($business, ['currency' => 'USD', 'effective_on' => now()->toDateString(), 'rate_to_base' => '3700', 'source_note' => 'Approved manual pricing rate'], $owner->id);
        app(FinanceRates::class)->approve($rate, $reviewer->id);
        $sales = app(SalesWorkflow::class);
        $request = $sales->createRequest($business->id, ['customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => '10']]], $owner->id);
        $quote = $sales->draft($business->id, $request->id, $owner->id);
        $sales->updateDraft($quote, ['currency' => 'USD', 'pricing_exchange_rate_id' => $rate->id, 'payment_terms_days' => 14, 'discount_percent' => 0, 'tax_percent' => 0, 'valid_until' => now()->addDays(7)->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => '10', 'unit_price' => '2']]]);

        return compact('business', 'owner', 'reviewer', 'customer', 'product', 'rate', 'quote', 'sales');
    }

    public function test_buyer_sees_frozen_foreign_offer_and_can_accept_once_without_internal_ugx_or_staff_access(): void
    {
        $this->freezeTime();
        $c = $this->context();
        $c['sales']->issue($c['quote'], $c['owner']->id);
        $quote = $c['quote']->fresh();
        $this->assertSame('74000.00', $quote->pricing_snapshot['base_grand_total']);
        $token = app(QuotationAccessService::class)->create($quote, 'buyer@example.test', $c['owner']->id);
        $this->get(route('buyer-quotation.show', $token))->assertOk()->assertDontSee('74000')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('buyer-quotation.document', $token))->assertOk()->assertSee('Total quotation: USD 20')->assertDontSee('74000');
        $data = ['decision' => 'accepted', 'buyer_name' => 'Buyer Manager', 'buyer_reference' => 'PURCHASE-123'];
        $this->post(route('buyer-quotation.decide', $token), $data)->assertRedirect()->assertSessionHasNoErrors();
        $order = SalesOrder::firstOrFail();
        $this->assertNull($order->confirmed_by);
        $this->assertSame($quote->id, $order->sales_quotation_id);
        $this->post(route('buyer-quotation.decide', $token), $data)->assertRedirect();
        $this->post(route('buyer-quotation.decide', $token), [...$data, 'decision' => 'rejected'])->assertStatus(409);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertSame('accepted', QuotationAccess::firstOrFail()->decision);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_links_fail_after_revision_revocation_expiry_or_business_suspension(): void
    {
        $this->freezeTime();
        $c = $this->context();
        $c['sales']->issue($c['quote'], $c['owner']->id);
        $links = app(QuotationAccessService::class);
        $old = $links->create($c['quote'], 'buyer@example.test', $c['owner']->id);
        $new = $links->create($c['quote'], 'buyer@example.test', $c['owner']->id);
        $this->get(route('buyer-quotation.show', $old))->assertStatus(410);
        $c['sales']->draft($c['business']->id, $c['quote']->sales_request_id, $c['owner']->id);
        $this->get(route('buyer-quotation.show', $new))->assertStatus(410);
        $c['business']->update(['is_active' => false]);
        $this->get(route('buyer-quotation.show', $new))->assertNotFound();
    }

    public function test_foreign_issue_has_no_rate_one_fallback_and_same_day_corrections_preserve_prior_rate(): void
    {
        $c = $this->context();
        $c['quote']->update(['pricing_exchange_rate_id' => null]);
        $this->actingAs($c['owner'])->post(route('sales-quotes.issue', $c['quote']->id))->assertSessionHasErrors('exchange_rate_id');
        $c['quote']->update(['pricing_exchange_rate_id' => $c['rate']->id]);
        $c['sales']->issue($c['quote'], $c['owner']->id);
        $next = app(FinanceRates::class)->propose($c['business'], ['currency' => 'USD', 'effective_on' => now()->toDateString(), 'rate_to_base' => '3800', 'source_note' => 'Corrected approved manual quote'], $c['owner']->id);
        app(FinanceRates::class)->approve($next, $c['reviewer']->id);
        $this->assertSame(2, $next->revision);
        $this->assertSame('74000.00', $c['quote']->fresh()->pricing_snapshot['base_grand_total']);
        $this->assertTrue(BigDecimal::of((string) $c['rate']->fresh()->rate_to_base)->isEqualTo('3700'));
        $this->get(route('sales-quotes.show',$c['quote']->id))->assertOk()->assertSee('74000.00');
    }
}
