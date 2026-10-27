<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\FinanceWorkflow;
use App\Models\StaffPerson;
use App\Models\User;
use App\Services\FinanceBankProfiles;
use App\Services\FinanceOperations;
use App\Services\FinanceSetup;
use App\Services\FinanceWorkflows;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MoneyCorrectionCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Return Exporters', 'code' => 'RET', 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$owner, $reviewer] as $user) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true]);
        }app(FinanceSetup::class)->activate($business);
        $this->actingAs($owner)->withSession(['active_business_id' => $business->id]);

        return compact('business', 'owner', 'reviewer');
    }

    private function money(array $c, array $extra): FinanceOperation
    {
        $data = [...['kind' => 'customer_deposit', 'currency' => 'UGX', 'amount' => '1000', 'event_on' => '2026-10-27', 'description' => 'Actual source money with documented purpose', 'external_reference' => 'SOURCE-001', 'cash_account_key' => 'cash', 'idempotency_key' => (string) Str::uuid()], ...$extra];
        $row = app(FinanceOperations::class)->prepare($c['business'], $data, $c['owner']->id);
        $this->postSource($c, 'money_operation', $row);

        return $row->fresh();
    }

    private function postSource(array $c, string $type, $source): void
    {
        $this->actingAs($c['owner'])->post(route('finance.reviews.request', ['type' => $type, 'source' => $source->id]), ['posting_on' => '2026-10-27', 'exchange_rate_id' => $source->exchange_rate_id, 'basis_notes' => 'Verified original event and independent supporting evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('source_type', $type)->where('source_id', $source->id)->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Separately checked the source amounts and original documentation.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    private function data(array $extra = []): array
    {
        return [...['amount' => '250', 'event_on' => '2026-10-27', 'reason' => 'Actual returned money verified against the original source.', 'external_reference' => 'RETURN-001', 'cash_witness' => 'Named cashier and owner signed the actual return voucher.', 'request_key' => (string) Str::uuid()], ...$extra];
    }

    private function correction(array $c, FinanceOperation $source, array $data): FinanceWorkflow
    {
        $this->actingAs($c['owner'])->post(route('finance.money.corrections.store', $source->id), $data)->assertRedirect()->assertSessionHasNoErrors();

        return FinanceWorkflow::query()->where('business_id', $c['business']->id)->where('request_key', $data['request_key'])->firstOrFail();
    }

    public function test_partial_deposit_correction_reserves_once_and_changes_unused_balance_only_when_posted(): void
    {
        $c = $this->context();
        $customer = Customer::create(['business_id' => $c['business']->id, 'customer_code' => 'BUY', 'name' => 'Buyer', 'currency' => 'UGX', 'country_code' => 'UG', 'active' => true]);
        $source = $this->money($c, ['customer_id' => $customer->id]);
        $data = $this->data();
        $flow = $this->correction($c, $source, $data);
        $this->correction($c, $source, $data);
        $this->assertDatabaseCount('finance_money_corrections', 1);
        $this->assertTrue(app(FinanceOperations::class)->remaining($source)->isEqualTo('750'));
        $this->assertTrue(app(FinanceOperations::class)->remaining($source, false)->isEqualTo('1000'));
        $this->post(route('finance.money.corrections.store', $source->id), $this->data(['amount' => '751']))->assertStatus(422);
        $this->postSource($c, 'finance_workflow', $flow);
        $this->assertTrue(app(FinanceOperations::class)->remaining($source, false)->isEqualTo('750'));
        $this->assertSame('posted', $source->fresh()->posting_status);
        $this->get(route('finance.money.corrections.create', $source->id))->assertOk()->assertSee($flow->reference);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_corrected_advance_expense_restores_only_the_returned_control_value(): void
    {
        $c = $this->context();
        $staff = StaffPerson::create(['business_id' => $c['business']->id, 'staff_code' => 'BUYER', 'name' => 'Market buyer', 'kind' => 'employee', 'active' => true]);
        $advance = $this->money($c, ['kind' => 'staff_advance', 'staff_person_id' => $staff->id, 'amount' => '500']);
        $expense = $this->money($c, ['kind' => 'staff_expense', 'parent_id' => $advance->id, 'amount' => '200', 'category' => 'transport']);
        $flow = $this->correction($c, $expense, $this->data(['amount' => '50']));
        $this->assertTrue(app(FinanceOperations::class)->remaining($advance)->isEqualTo('300'));
        $this->postSource($c, 'finance_workflow', $flow);
        $this->assertTrue(app(FinanceOperations::class)->remaining($advance, false)->isEqualTo('350'));
        $this->money($c, ['kind' => 'staff_expense', 'parent_id' => $advance->id, 'amount' => '350', 'category' => 'transport']);
        $this->assertTrue(app(FinanceOperations::class)->remaining($advance, false)->isZero());
        $account = app(FinanceSetup::class)->account($c['business']->id, 'staff_advances');
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['business']->id, $account->id, '2026-10-27')['base']->isZero());
    }

    public function test_bank_expense_refund_requires_exact_frozen_bank_evidence_and_keeps_original_cost(): void
    {
        $c = $this->context();
        $bank = app(FinanceBankProfiles::class)->create($c['business'], ['label' => 'Operating bank', 'institution' => 'Test bank', 'currency' => 'UGX'], $c['owner']->id);
        $source = $this->money($c, ['kind' => 'paid_expense', 'category' => 'rent', 'bank_profile_id' => $bank->id]);
        $this->post(route('finance.money.corrections.store', $source->id), $this->data())->assertStatus(422);
        $statement = FinanceBankStatement::create(['business_id' => $c['business']->id, 'account_id' => $bank->account_id, 'reference' => 'ACTUAL-REFUND', 'currency' => 'UGX', 'period_from' => '2026-10-27', 'period_to' => '2026-10-27', 'opening_balance' => '0', 'closing_balance' => '250', 'credits' => '250', 'debits' => '0', 'file_sha256' => hash('sha256', 'actual returned bank file'), 'filename' => 'refund.csv', 'status' => 'open', 'imported_by' => $c['owner']->id]);
        $line = FinanceBankStatementLine::create(['business_id' => $c['business']->id, 'statement_id' => $statement->id, 'line_number' => 1, 'transaction_on' => '2026-10-27', 'direction' => 'credit', 'amount' => '250', 'bank_reference' => 'RENT-REFUND', 'description' => 'Actual partial expense returned']);
        $flow = $this->correction($c, $source, $this->data(['return_bank_line_id' => $line->id]));
        $line->update(['bank_reference' => 'CHANGED-RETURN']);
        $this->post(route('finance.reviews.request', ['type' => 'finance_workflow', 'source' => $flow->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Changed bank evidence must not be approved or posted.'])->assertStatus(422);
        $line->update(['bank_reference' => 'RENT-REFUND']);
        $this->postSource($c, 'finance_workflow', $flow);
        $this->post(route('finance.bank.match', [$statement->id, $line->id]), ['candidate' => 'workflow_leg:'.$flow->legs()->firstOrFail()->id, 'note' => 'The exact returned bank credit is bound to this source correction.'])->assertRedirect()->assertSessionHasNoErrors();
        $account = app(FinanceSetup::class)->account($c['business']->id, 'general_expense');
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['business']->id, $account->id, '2026-10-27')['base']->isEqualTo('750'));
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_transfer_return_has_two_actual_legs_and_no_second_expense(): void
    {
        $c = $this->context();
        $bank = app(FinanceBankProfiles::class)->create($c['business'], ['label' => 'Operating bank', 'institution' => 'Test bank', 'currency' => 'UGX'], $c['owner']->id);
        $source = $this->money($c, ['kind' => 'transfer', 'amount' => '1000', 'cash_account_key' => 'cash', 'destination_bank_profile_id' => $bank->id]);
        $statement = FinanceBankStatement::create(['business_id' => $c['business']->id, 'account_id' => $bank->account_id, 'reference' => 'TRANSFER-RETURN', 'currency' => 'UGX', 'period_from' => '2026-10-27', 'period_to' => '2026-10-27', 'opening_balance' => '1000', 'closing_balance' => '750', 'credits' => '0', 'debits' => '250', 'file_sha256' => hash('sha256', 'actual transfer return'), 'filename' => 'transfer.csv', 'status' => 'open', 'imported_by' => $c['owner']->id]);
        $line = FinanceBankStatementLine::create(['business_id' => $c['business']->id, 'statement_id' => $statement->id, 'line_number' => 1, 'transaction_on' => '2026-10-27', 'direction' => 'debit', 'amount' => '250', 'bank_reference' => 'CASH-RETURN', 'description' => 'Actual bank withdrawal returning part of transfer to cash']);
        $flow = $this->correction($c, $source, $this->data(['other_bank_line_id' => $line->id]));
        $this->postSource($c, 'finance_workflow', $flow);
        $this->assertCount(2, $flow->legs()->get());
        $clear = app(FinanceSetup::class)->account($c['business']->id, 'fx_clearing');
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['business']->id, $clear->id, '2026-10-27')['base']->isZero());
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['business']->id, $bank->account_id, '2026-10-27')['units']->isEqualTo('750'));
    }
}
