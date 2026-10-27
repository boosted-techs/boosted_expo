<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\FinanceWorkflow;
use App\Models\User;
use App\Services\FinanceSetup;
use App\Services\FinanceWorkflows;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoucherCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(string $code = 'TRY'): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Treasury Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
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
        return [...['kind' => 'owner_contribution', 'event_on' => '2026-10-27', 'currency' => 'UGX', 'amount' => '100000', 'account_key' => 'cash', 'reason' => 'Verified actual cash received with signed source evidence.', 'external_reference' => 'CASH-VOUCHER-001', 'request_key' => (string) Str::uuid()], ...$extra];
    }

    private function prepare(array $c, array $data): FinanceWorkflow
    {
        $this->actingAs($c['owner'])->post(route('finance.treasury.store'), $data)->assertRedirect()->assertSessionHasNoErrors();

        return FinanceWorkflow::query()->where('business_id', $c['business']->id)->where('request_key', $data['request_key'])->firstOrFail();
    }

    private function postFlow(array $c, FinanceWorkflow $flow): void
    {
        $this->actingAs($c['owner'])->post(route('finance.reviews.request', ['type' => 'finance_workflow', 'source' => $flow->id]), ['posting_on' => $flow->event_on->toDateString(), 'exchange_rate_id' => $flow->exchange_rate_id, 'basis_notes' => $flow->reason])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('source_type', 'finance_workflow')->where('source_id', $flow->id)->firstOrFail();
        $this->post(route('finance.reviews.approve', $review->id), ['notes' => 'Own preparation cannot approve the money event.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independently checked the actual receipt and prepared financial legs.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.reviews.post', $review->id))->assertStatus(422);
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    private function balance(array $c, string $key): string
    {
        return (string) app(FinanceWorkflows::class)->accountBasis($c['business']->id, app(FinanceSetup::class)->account($c['business']->id, $key)->id, '2026-10-27')['base']->toScale(2);
    }

    private function postMoney(array $c, FinanceOperation $source): void
    {
        $this->actingAs($c['owner'])->post(route('finance.reviews.request', ['type' => 'money_operation', 'source' => $source->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Verified business expense and personal claimant receipt evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::where('source_type', 'money_operation')->where('source_id', $source->id)->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independently checked the source and actual claimant reimbursement.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_owner_personal_business_expense_is_reimbursed_once_without_becoming_a_drawing(): void
    {
        $c = $this->context();
        $fund = $this->prepare($c, $this->data(['amount' => '1000']));
        $this->postFlow($c, $fund);
        $data = ['kind' => 'expense', 'currency' => 'UGX', 'amount' => '300', 'event_on' => '2026-10-27', 'description' => 'Owner paid a business transport invoice personally', 'owner_user_id' => $c['owner']->id, 'category' => 'transport', 'external_reference' => 'OWNER-PAID-001', 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($c['owner'])->post(route('finance.money.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $expense = FinanceOperation::latest('id')->firstOrFail();
        $this->postMoney($c, $expense);
        $this->assertSame($c['owner']->id, $expense->owner_user_id);
        $this->get(route('finance.position'))->assertOk()->assertSee('Owner-paid business claims awaiting reimbursement');
        $this->post(route('finance.money.store'), ['kind' => 'expense_payment', 'currency' => 'UGX', 'amount' => '300', 'parent_id' => $expense->id, 'event_on' => '2026-10-27', 'description' => 'Actual reimbursement of owner-paid business expense', 'cash_account_key' => 'cash', 'external_reference' => 'OWNER-REIMBURSE-001', 'idempotency_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $payment = FinanceOperation::latest('id')->firstOrFail();
        $this->assertSame($c['owner']->id, $payment->owner_user_id);
        $this->postMoney($c, $payment);
        $this->assertSame('300.00', $this->balance($c, 'general_expense'));
        $this->assertSame('0.00', $this->balance($c, 'owner_drawings'));
        $this->assertSame('0.00', $this->balance($c, 'expense_payable'));
        $this->assertSame('700.00', $this->balance($c, 'cash'));
    }

    public function test_shipment_cost_retries_are_idempotent_and_changed_payload_is_refused(): void
    {
        $c = $this->context();
        $customer = Customer::create(['business_id' => $c['business']->id, 'customer_code' => 'BUY', 'name' => 'Importer', 'country_code' => 'AE', 'currency' => 'UGX', 'active' => true]);
        $shipment = ExportShipment::create(['business_id' => $c['business']->id, 'customer_id' => $customer->id, 'reference' => 'SHIP-VOUCHER', 'status' => 'dispatched', 'transport_mode' => 'air', 'origin_country' => 'UG', 'destination_country' => 'AE', 'created_by' => $c['owner']->id]);
        $data = ['shipment_id' => $shipment->id, 'category' => 'handling', 'description' => 'Actual airport handling invoice', 'currency' => 'UGX', 'amount' => '1000', 'cash_account_key' => 'cash', 'paid_on' => '2026-10-27', 'external_reference' => 'HANDLING-001', 'idempotency_key' => (string) Str::uuid()];
        $this->post(route('finance.costs.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.costs.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('finance_shipment_costs', 1);
        $this->post(route('finance.costs.store'), [...$data, 'amount' => '2000'])->assertStatus(422);
        $this->assertDatabaseCount('finance_shipment_costs', 1);
    }
}
