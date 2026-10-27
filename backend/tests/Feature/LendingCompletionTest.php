<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\FinancePostingRequest;
use App\Models\FinanceRegisterEntry;
use App\Models\LoanFacility;
use App\Models\User;
use App\Services\FinanceBankProfiles;
use App\Services\FinanceFunding;
use App\Services\FinanceManagementReports;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\FinanceStatements;
use App\Services\FinanceTreasury;
use App\Services\FinanceWorkflows;
use App\Services\LoanAgreementAmendments;
use App\Services\LoanRegister;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LendingCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $b = Business::create(['name' => 'Exporter lending', 'code' => 'LEND', 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $a = User::factory()->create();
        $r = User::factory()->create();
        foreach ([$a, $r] as $u) {
            BusinessMembership::create(['business_id' => $b->id, 'user_id' => $u->id, 'role' => 'owner', 'active' => true]);
        }app(FinanceSetup::class)->activate($b);
        $this->actingAs($a)->withSession(['active_business_id' => $b->id]);

        return compact('b', 'a', 'r');
    }

    private function data(array $extra = []): array
    {
        return [...['event_on' => '2026-10-27', 'external_reference' => 'SIGNED-LEND-001', 'reason' => 'Signed counterparty agreement and actual money independently verified.', 'request_key' => (string) Str::uuid(), 'cash_account_key' => 'cash'], ...$extra];
    }

    private function postSource(array $c, string $type, int $id, ?int $rate = null): void
    {
        $this->actingAs($c['a'])->post(route('finance.reviews.request', ['type' => $type, 'source' => $id]), ['posting_on' => '2026-10-27', 'exchange_rate_id' => $rate, 'basis_notes' => 'Actual borrower agreement, source amounts and independent original evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::where('source_type', $type)->where('source_id', $id)->firstOrFail();
        $this->actingAs($c['r'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independently verified the actual loan and currency carrying amounts.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['a'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    private function fund(array $c, string $currency = 'UGX', string $amount = '10000', ?int $bank = null, ?int $rate = null): void
    {
        $data = $this->data(['currency' => $currency, 'amount' => $amount, 'account_key' => 'cash', 'bank_profile_id' => $bank, 'exchange_rate_id' => $rate]);
        $flow = app(FinanceTreasury::class)->prepare($c['b'], 'owner_contribution', $data, $c['a']->id);
        $this->postSource($c, 'finance_workflow', $flow->id, $flow->exchange_rate_id);
    }

    private function entry(array $c, LoanFacility $loan, string $kind, array $extra): FinanceRegisterEntry
    {
        $data = $this->data(['loan_id' => $loan->id, ...$extra]);
        $this->actingAs($c['a'])->post(route('finance.register.store', $kind), $data)->assertRedirect()->assertSessionHasNoErrors();
        $entry = FinanceRegisterEntry::where('request_key', $data['request_key'])->firstOrFail();
        $this->postSource($c, 'register_entry', $entry->id, $entry->exchange_rate_id);

        return $entry;
    }

    public function test_lending_is_a_receivable_and_scheduled_collections_not_business_borrowing_or_duplicate_income(): void
    {
        $c = $this->context();
        $this->fund($c);
        $loan = LoanFacility::factory()->create(['business_id' => $c['b']->id, 'loan_direction' => 'lend', 'currency' => 'UGX', 'limit_amount' => '2000', 'starts_on' => '2026-10-01', 'matures_on' => '2028-10-31', 'created_by' => $c['a']->id]);
        $this->entry($c, $loan, 'loan_drawdown', ['amount' => '1000']);
        $this->entry($c, $loan, 'loan_interest', ['amount' => '100']);
        $this->entry($c, $loan, 'loan_fee_accrual', ['amount' => '20']);
        $inst = app(LoanRegister::class)->schedule($c['b'], $loan, ['reference' => 'DUE-BORROWER-01', 'due_on' => '2026-10-29', 'principal' => '500', 'interest' => '100', 'fees' => '20'], $c['a']->id);
        $this->entry($c, $loan, 'loan_repayment', ['principal' => '300', 'interest' => '50', 'fees' => '20', 'instalment_id' => $inst->id]);
        $state = app(LoanRegister::class)->state($loan);
        $this->assertTrue($state['principal']->isEqualTo('700'));
        $this->assertTrue($state['interest']->isEqualTo('50'));
        $this->assertTrue($state['fees']->isZero());
        $report = app(FinanceStatements::class)->report($c['b'], '2026-10-27', '2026-10-27');
        $this->assertSame('120.00', $report['income']);
        $this->assertSame('0.00', $report['liabilities']);
        $maturity = app(FinanceManagementReports::class)->maturity($c['b'], '2026-10-27', $report);
        $this->assertTrue($maturity['totals']['noncurrent_asset']->isEqualTo('500'));
        $funding = app(FinanceFunding::class)->report($c['b']);
        $this->assertTrue($funding['windows'][7]['UGX']['collections']->isEqualTo('250'));
        $this->assertTrue($funding['windows'][7]['UGX']['loans']->isZero());
        $this->get(route('finance.loans.show', $loan->id))->assertOk()->assertSee('Money owed to this business')->assertSee('Record money lent');
    }

    public function test_foreign_loan_revaluation_and_partial_receipt_release_only_the_repaid_exchange_gain(): void
    {
        $c = $this->context();
        $old = app(FinanceRates::class)->propose($c['b'], ['currency' => 'USD', 'rate_to_base' => '3700', 'effective_on' => '2026-10-27', 'source_note' => 'Agreed original lending and bank currency basis.'], $c['a']->id);
        app(FinanceRates::class)->approve($old, $c['r']->id);
        $new = app(FinanceRates::class)->propose($c['b'], ['currency' => 'USD', 'rate_to_base' => '3800', 'effective_on' => '2026-10-27', 'source_note' => 'Agreed new foreign lending reporting valuation.'], $c['a']->id);
        app(FinanceRates::class)->approve($new, $c['r']->id);
        $bank = app(FinanceBankProfiles::class)->create($c['b'], ['label' => 'USD lending bank', 'institution' => 'Exporter bank', 'currency' => 'USD'], $c['a']->id);
        $this->fund($c, 'USD', '1000', $bank->id, $old->id);
        $loan = LoanFacility::factory()->create(['business_id' => $c['b']->id, 'loan_direction' => 'lend', 'currency' => 'USD', 'limit_amount' => '2000', 'starts_on' => '2026-10-01', 'matures_on' => '2028-10-31', 'created_by' => $c['a']->id]);
        $this->entry($c, $loan, 'loan_drawdown', ['amount' => '1000', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $old->id]);
        $this->entry($c, $loan, 'loan_revaluation', ['exchange_rate_id' => $new->id]);
        $this->entry($c, $loan, 'loan_repayment', ['principal' => '400', 'interest' => '0', 'fees' => '0', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $new->id]);
        $state = app(LoanRegister::class)->state($loan);
        $this->assertTrue($state['principal']->isEqualTo('600'));
        $this->assertTrue($state['principal_base']->isEqualTo('2280000'));
        $this->assertTrue($state['unreal']['principal']->isEqualTo('60000'));
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['b']->id, $bank->account_id, '2026-10-27')['base']->isEqualTo('1520000'));
    }

    public function test_lending_refuses_unfunded_disbursement_and_actual_partial_error_returns_restore_draw_capacity(): void
    {
        $c = $this->context();
        $this->fund($c);
        $loan = LoanFacility::factory()->create(['business_id' => $c['b']->id, 'loan_direction' => 'lend', 'currency' => 'UGX', 'limit_amount' => '20000', 'starts_on' => '2026-10-01', 'matures_on' => '2028-10-31', 'created_by' => $c['a']->id]);
        $this->post(route('finance.register.store', 'loan_drawdown'), $this->data(['loan_id' => $loan->id, 'amount' => '10001']))->assertStatus(422);
        $draw = $this->entry($c, $loan, 'loan_drawdown', ['amount' => '1000']);
        $this->entry($c, $loan, 'register_partial_return', ['original_entry_id' => $draw->id, 'amount' => '200', 'cash_witness' => 'Borrower, cash custodian and independent witness signed the actual unused cash return.']);
        $state = app(LoanRegister::class)->state($loan);
        $this->assertTrue($state['principal']->isEqualTo('800'));
        $this->assertTrue($state['drawn']->isEqualTo('800'));
    }

    public function test_loan_writeoffs_preserve_cash_require_plan_resolution_and_independent_resumption(): void
    {
        $c = $this->context();
        $this->fund($c);
        $loan = LoanFacility::factory()->create(['business_id' => $c['b']->id, 'loan_direction' => 'lend', 'currency' => 'UGX', 'limit_amount' => '2000', 'starts_on' => '2026-10-01', 'matures_on' => '2028-10-31', 'created_by' => $c['a']->id]);
        $this->entry($c, $loan, 'loan_drawdown', ['amount' => '1000']);
        $this->entry($c, $loan, 'loan_interest', ['amount' => '100']);
        $inst = app(LoanRegister::class)->schedule($c['b'], $loan, ['reference' => 'PLAN-TO-CANCEL', 'due_on' => '2026-10-29', 'principal' => '1000', 'interest' => '100', 'fees' => '0'], $c['a']->id);
        $this->actingAs($c['a'])->post(route('finance.register.store', 'loan_writeoff'), $this->data(['loan_id' => $loan->id, 'principal' => '400', 'interest' => '100', 'fees' => '0']))->assertStatus(422);
        $this->post(route('finance.loans.schedule.cancel', $inst->id), ['reason' => 'Signed loss decision replaces this unpaid plan with the recoverable remainder.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->entry($c, $loan, 'loan_writeoff', ['principal' => '400', 'interest' => '100', 'fees' => '0']);
        $state = app(LoanRegister::class)->state($loan->fresh());
        $this->assertTrue($state['principal']->isEqualTo('600'));
        $this->assertTrue($state['interest']->isZero());
        $this->assertTrue($loan->fresh()->lending_suspended);
        $cash = app(FinanceSetup::class)->account($c['b']->id, 'cash');
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['b']->id, $cash->id, '2026-10-27')['units']->isEqualTo('9000'));
        $this->actingAs($c['a'])->post(route('finance.register.store', 'loan_drawdown'), $this->data(['loan_id' => $loan->id, 'amount' => '100']))->assertStatus(422);
        $amend = app(LoanAgreementAmendments::class)->propose($c['b'], $loan->fresh(), ['request_key' => (string) Str::uuid(), 'effective_on' => '2026-10-27', 'limit_amount' => '2000', 'revolving' => false, 'matures_on' => '2028-10-31', 'agreement_reference' => 'SIGNED-NEW-SECURITY', 'terms' => 'Additional signed security authorises a limited future lending agreement.', 'reason' => 'Independent risk review after the agreed partial bad debt write-off.', 'resume_lending' => true], $c['a']->id);
        app(LoanAgreementAmendments::class)->review($c['b'], $amend, true, 'Independent risk and signed agreement checked before lending resumes.', $c['r']->id);
        $this->assertFalse($loan->fresh()->lending_suspended);
        $this->entry($c, $loan->fresh(), 'loan_drawdown', ['amount' => '100']);
        $borrow = LoanFacility::factory()->create(['business_id' => $c['b']->id, 'loan_direction' => 'borrow', 'currency' => 'UGX', 'limit_amount' => '2000', 'starts_on' => '2026-10-01', 'matures_on' => '2028-10-31', 'created_by' => $c['a']->id]);
        $this->entry($c, $borrow, 'loan_drawdown', ['amount' => '1000']);
        $this->entry($c, $borrow, 'loan_writeoff', ['principal' => '250', 'interest' => '0', 'fees' => '0']);
        $this->assertTrue(app(LoanRegister::class)->state($borrow)['principal']->isEqualTo('750'));
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['b']->id, $cash->id, '2026-10-27')['units']->isEqualTo('9900'));
    }
}
