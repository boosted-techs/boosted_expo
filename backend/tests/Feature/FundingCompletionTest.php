<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\FundingCommitment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\FinanceFunding;
use App\Services\FinanceSetup;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FundingCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(string $code = 'FUN'): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Funding exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$owner, $reviewer] as $user) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true]);
        }app(FinanceSetup::class)->activate($business);
        $this->actingAs($owner)->withSession(['active_business_id' => $business->id]);

        return compact('business', 'owner', 'reviewer');
    }

    private function data(array $extra = []): array
    {
        return [...['kind' => 'operating', 'currency' => 'UGX', 'amount' => '1000', 'due_on' => '2026-10-30', 'purpose' => 'Expected operating invoice', 'evidence' => 'Reviewed supplier agreement and expected service delivery date.', 'request_key' => (string) Str::uuid()], ...$extra];
    }

    private function postSource(array $c, string $type, int $id): void
    {
        $this->actingAs($c['owner'])->post(route('finance.reviews.request', ['type' => $type, 'source' => $id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Verified original event and independent source evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::where('business_id', $c['business']->id)->where('source_type', $type)->where('source_id', $id)->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independently verified source and actual event evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_reviewed_plan_is_replaced_by_its_actual_source_without_double_forecasting(): void
    {
        $c = $this->context();
        $data = $this->data();
        $this->post(route('finance.funding.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.funding.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('funding_commitments', 1);
        $plan = FundingCommitment::firstOrFail();
        $this->post(route('finance.funding.review', [$plan->id, 'approve']), ['note' => 'Own forecast must be independently reviewed.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.funding.review', [$plan->id, 'approve']), ['note' => 'Independently verified the contract, amount and expected due date.'])->assertRedirect()->assertSessionHasNoErrors();
        $report = app(FinanceFunding::class)->report($c['business']);
        $this->assertTrue($report['windows'][7]['UGX']['planned']->isEqualTo('1000'));
        $this->actingAs($c['owner'])->post(route('finance.money.store'), ['kind' => 'expense', 'currency' => 'UGX', 'amount' => '1000', 'event_on' => '2026-10-27', 'due_on' => '2026-10-30', 'category' => 'utilities', 'description' => 'Actual operating service invoice', 'external_reference' => 'UTILITY-001', 'idempotency_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $expense = FinanceOperation::firstOrFail();
        $this->postSource($c, 'money_operation', $expense->id);
        $this->actingAs($c['reviewer'])->post(route('finance.funding.recognise', $plan->id), ['source_type' => 'expense', 'source_id' => $expense->id, 'amount' => '1000', 'reason' => 'This posted actual invoice fully replaces the expected service commitment.'])->assertRedirect()->assertSessionHasNoErrors();
        $report = app(FinanceFunding::class)->report($c['business']);
        $this->assertTrue($report['windows'][7]['UGX']['planned']->isZero());
        $this->assertTrue($report['windows'][7]['UGX']['expenses']->isEqualTo('1000'));
        $this->assertTrue($report['windows'][7]['UGX']['outgoing']->isEqualTo('1000'));
        $this->get(route('finance.funding.index'))->assertOk()->assertSee('Expected operating invoice');
    }

    public function test_unbilled_purchase_recurring_and_pending_cash_are_included_in_correct_windows(): void
    {
        $c = $this->context();
        $product = Product::create(['business_id' => $c['business']->id, 'name' => 'Avocado', 'sku' => 'AVO', 'base_unit' => 'kg', 'active' => true]);
        PurchaseOrder::create(['business_id' => $c['business']->id, 'reference' => 'PO-FUND', 'kind' => 'market', 'product_id' => $product->id, 'quantity' => '10.125', 'unit_price' => '100', 'estimated_total' => '1012.50', 'currency' => 'UGX', 'status' => 'purchased', 'purchased_on' => '2026-10-27', 'created_by' => $c['owner']->id, 'idempotency_key' => (string) Str::uuid()]);
        $this->post(route('finance.recurring.store'), ['name' => 'Monthly rent', 'currency' => 'UGX', 'amount' => '2000', 'category' => 'rent', 'next_due_on' => '2026-11-10', 'interval_months' => 1, 'terms' => 'Monthly fixed rent expected under the signed tenancy agreement.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.money.store'), ['kind' => 'paid_expense', 'currency' => 'UGX', 'amount' => '300', 'event_on' => '2026-10-27', 'category' => 'utilities', 'description' => 'Actual cash purchase awaiting review', 'external_reference' => 'CASH-PENDING', 'cash_account_key' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $report = app(FinanceFunding::class)->report($c['business']);
        $this->assertTrue($report['windows'][7]['UGX']['purchases']->isEqualTo('1012.50'));
        $this->assertTrue($report['windows'][7]['UGX']['pending_cash']->isEqualTo('300'));
        $this->assertTrue($report['windows'][7]['UGX']['recurring']->isZero());
        $this->assertTrue($report['windows'][30]['UGX']['recurring']->isEqualTo('2000'));
        $this->assertTrue($report['windows'][30]['UGX']['projected']->isEqualTo('-3312.50'));
    }

    public function test_plan_and_actual_sources_cannot_cross_businesses_or_exceed_original_amount(): void
    {
        $c = $this->context();
        $this->post(route('finance.funding.store'), $this->data())->assertRedirect();
        $plan = FundingCommitment::firstOrFail();
        $other = $this->context('OTF');
        $this->post(route('finance.funding.review', [$plan->id, 'approve']), ['note' => 'Different tenant must not be able to review this plan.'])->assertNotFound();
        $this->post(route('finance.funding.recognise', $plan->id), ['source_type' => 'expense', 'source_id' => 1, 'amount' => '1', 'reason' => 'Different tenant must not be able to claim actual sources.'])->assertNotFound();
        $this->actingAs($c['owner'])->withSession(['active_business_id' => $c['business']->id])->get(route('finance.position'))->assertOk()->assertSee('Open funding and commitments');
    }
}
