<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\CashClose;
use App\Models\CashCustody;
use App\Models\FinanceAccount;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\FinanceWorkflow;
use App\Models\RecurringObligation;
use App\Models\StaffPerson;
use App\Models\User;
use App\Services\FinanceBankProfiles;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\FinanceWorkflows;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TreasuryCompletionTest extends TestCase
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

    public function test_owner_funding_and_drawings_are_equity_and_submission_is_idempotent(): void
    {
        $c = $this->context();
        $data = $this->data();
        $flow = $this->prepare($c, $data);
        $this->prepare($c, $data);
        $this->assertDatabaseCount('finance_workflows', 1);
        $this->post(route('finance.treasury.store'), [...$data, 'amount' => '200000'])->assertStatus(422);
        $this->postFlow($c, $flow);
        $draw = $this->prepare($c, $this->data(['kind' => 'owner_drawing', 'amount' => '20000']));
        $this->postFlow($c, $draw);
        $this->assertSame('80000.00', $this->balance($c, 'cash'));
        $this->assertSame('-100000.00', $this->balance($c, 'owner_capital'));
        $this->assertSame('20000.00', $this->balance($c, 'owner_drawings'));
        $this->assertSame('0.00', $this->balance($c, 'general_expense'));
        $this->get(route('finance.treasury.index'))->assertOk()->assertSee('Cash floats and daily counts');
        $this->get(route('finance.treasury.show', $flow->id))->assertOk()->assertSee('Posted journal');
        $this->get(route('finance.reviews'))->assertOk();
    }

    public function test_currency_conversion_posts_both_account_events_and_clears_the_historical_base_value(): void
    {
        $c = $this->context();
        $bank = app(FinanceBankProfiles::class)->create($c['business'], ['label' => 'USD account', 'institution' => 'Test bank', 'currency' => 'USD'], $c['owner']->id);
        $fx = app(FinanceRates::class)->propose($c['business'], ['currency' => 'USD', 'rate_to_base' => '3700', 'effective_on' => '2026-10-20', 'source_note' => 'Manual verified bank evidence rate'], $c['owner']->id);
        app(FinanceRates::class)->approve($fx, $c['reviewer']->id);
        $capital = $this->prepare($c, $this->data(['currency' => 'USD', 'amount' => '1002', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $fx->id]));
        $this->postFlow($c, $capital);
        $data = $this->data(['kind' => 'exchange', 'currency' => 'USD', 'amount' => '1000', 'fee_amount' => '2', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $fx->id, 'destination_currency' => 'UGX', 'destination_account_key' => 'cash', 'received_amount' => '3800000']);
        $flow = $this->prepare($c, $data);
        $this->postFlow($c, $flow);
        $this->assertSame('0.00', $this->balance($c, 'fx_clearing'));
        $this->assertSame('7400.00', $this->balance($c, 'exchange_fees'));
        $this->assertSame('-100000.00', $this->balance($c, 'fx_gain'));
        $this->assertSame('3800000.00', $this->balance($c, 'cash'));
        $this->assertSame('0.00', $this->balance($c, $bank->account->system_key));
        $this->assertCount(2, $flow->legs()->get());
        $this->assertDatabaseCount('finance_journals', 3);
        $statement = FinanceBankStatement::create(['business_id' => $c['business']->id, 'account_id' => $bank->account_id, 'reference' => 'EXCHANGE-BANK', 'currency' => 'USD', 'period_from' => '2026-10-27', 'period_to' => '2026-10-27', 'opening_balance' => '1002', 'closing_balance' => '0', 'credits' => '0', 'debits' => '1002', 'file_sha256' => hash('sha256', 'actual exchange bank export'), 'filename' => 'bank.csv', 'status' => 'open', 'imported_by' => $c['owner']->id]);
        $line = FinanceBankStatementLine::create(['business_id' => $c['business']->id, 'statement_id' => $statement->id, 'line_number' => 1, 'transaction_on' => '2026-10-27', 'direction' => 'debit', 'amount' => '1002', 'bank_reference' => 'FX-ACTUAL', 'description' => 'Currency sold plus explicit charge']);
        $leg = $flow->legs()->firstOrFail();
        $this->get(route('finance.bank.show', $statement->id))->assertOk()->assertSee($flow->reference);
        $this->post(route('finance.bank.match', [$statement->id, $line->id]), ['candidate' => 'workflow_leg:'.$leg->id, 'note' => 'Actual debit equals the sold dollars and source charge.'])->assertRedirect()->assertSessionHasNoErrors();
        $match = FinanceBankMatch::firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.bank.confirm', $match->id), ['note' => 'Independently checked the actual conversion debit on this bank.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $match->fresh()->status);
    }

    public function test_changed_account_balance_refuses_stale_exchange_without_half_posting(): void
    {
        $c = $this->context();
        $bank = app(FinanceBankProfiles::class)->create($c['business'], ['label' => 'USD account', 'institution' => 'Test bank', 'currency' => 'USD'], $c['owner']->id);
        $fx = app(FinanceRates::class)->propose($c['business'], ['currency' => 'USD', 'rate_to_base' => '3700', 'effective_on' => '2026-10-20', 'source_note' => 'Manual verified bank evidence rate'], $c['owner']->id);
        app(FinanceRates::class)->approve($fx, $c['reviewer']->id);
        $capital = $this->prepare($c, $this->data(['currency' => 'USD', 'amount' => '1000', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $fx->id]));
        $this->postFlow($c, $capital);
        $flow = $this->prepare($c, $this->data(['kind' => 'exchange', 'currency' => 'USD', 'amount' => '1000', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $fx->id, 'destination_currency' => 'UGX', 'received_amount' => '3700000']));
        $extra = $this->prepare($c, $this->data(['currency' => 'USD', 'amount' => '10', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $fx->id]));
        $this->postFlow($c, $extra);
        $this->post(route('finance.reviews.request', ['type' => 'finance_workflow', 'source' => $flow->id]), ['posting_on' => '2026-10-27', 'exchange_rate_id' => $fx->id, 'basis_notes' => 'This old carrying value changed after more capital arrived.'])->assertStatus(422);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertSame('not_posted', $flow->fresh()->posting_status);
    }

    public function test_cash_shortage_requires_separate_count_review_and_posting_then_blocks_closed_day_changes(): void
    {
        $c = $this->context();
        $person = StaffPerson::create(['business_id' => $c['business']->id, 'staff_code' => 'CASHIER', 'name' => 'Cash custodian', 'kind' => 'employee', 'active' => true]);
        $this->post(route('finance.cash.custody'), ['name' => 'Packing petty cash', 'currency' => 'UGX', 'staff_person_id' => $person->id, 'authorised_float' => '50000'])->assertRedirect()->assertSessionHasNoErrors();
        $cash = CashCustody::firstOrFail();
        $this->postFlow($c, $this->prepare($c, $this->data()));
        $this->postFlow($c, $this->prepare($c, $this->data(['kind' => 'cash_topup', 'amount' => '10000', 'custody_id' => $cash->id])));
        $this->post(route('finance.cash.count', $cash->id), ['counted' => '9500', 'reason' => 'Two people counted every note; one voucher is missing.'])->assertRedirect()->assertSessionHasNoErrors();
        $count = CashClose::firstOrFail();
        $this->post(route('finance.cash.review', [$count->id, 'approve']), ['note' => 'Own count cannot be approved by its preparer.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.cash.review', [$count->id, 'approve']), ['note' => 'Independently verified the cash notes and missing voucher.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('variance_pending', $count->fresh()->status);
        $this->assertSame('10000.00', $this->balance($c, FinanceAccount::findOrFail($cash->account_id)->system_key));
        $this->postFlow($c, FinanceWorkflow::findOrFail($count->fresh()->workflow_id));
        $this->assertSame('closed', $count->fresh()->status);
        $this->assertSame('500.00', $this->balance($c, 'cash_shortage'));
        $topup = $this->prepare($c, $this->data(['kind' => 'cash_topup', 'amount' => '100', 'custody_id' => $cash->id]));
        $this->post(route('finance.reviews.request', ['type' => 'finance_workflow', 'source' => $topup->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Later cash activity requires the closed count to be reopened.'])->assertRedirect();
        $review = FinancePostingRequest::where('source_id', $topup->id)->where('source_type', 'finance_workflow')->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Checked the topup but the cash day remains closed.'])->assertRedirect();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertStatus(422);
        $this->post(route('finance.cash.reopen', $count->id), ['reason' => 'A late source voucher requires this cash close to be reopened.'])->assertRedirect();
        $this->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reopened', $count->fresh()->status);
    }

    public function test_recurring_bill_is_only_recognised_once_and_does_not_pay_cash(): void
    {
        $c = $this->context();
        $this->post(route('finance.recurring.store'), ['name' => 'Packhouse rent', 'currency' => 'UGX', 'amount' => '500000', 'category' => 'rent', 'next_due_on' => '2026-10-27', 'interval_months' => '1', 'terms' => 'Monthly rental agreement, confirm the actual invoice before recognition.'])->assertRedirect()->assertSessionHasNoErrors();
        $obligation = RecurringObligation::firstOrFail();
        $this->assertDatabaseCount('finance_operations', 0);
        $data = ['due_on' => '2026-10-27', 'event_on' => '2026-10-27', 'external_reference' => 'LANDLORD-RENT-OCT', 'request_key' => (string) Str::uuid()];
        $this->post(route('finance.recurring.recognise', $obligation->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.recurring.recognise', $obligation->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('finance_operations', 1);
        $this->assertSame('expense', FinanceOperation::firstOrFail()->kind);
        $this->assertSame('2026-11-27', $obligation->fresh()->next_due_on->toDateString());
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_treasury_records_and_custodians_are_scoped_and_warehouse_cannot_create_money(): void
    {
        $c = $this->context();
        $foreign = $this->context('OTHER');
        $row = $this->prepare($foreign, $this->data());
        $this->actingAs($c['owner'])->withSession(['active_business_id' => $c['business']->id])->get(route('finance.treasury.show', $row->id))->assertNotFound();
        $warehouse = User::factory()->create();
        BusinessMembership::create(['business_id' => $c['business']->id, 'user_id' => $warehouse->id, 'role' => 'warehouse', 'active' => true]);
        $this->actingAs($warehouse)->post(route('finance.treasury.store'), $this->data())->assertForbidden();
        $this->assertDatabaseCount('finance_journals', 0);
    }
}
