<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessAsset;
use App\Models\BusinessMembership;
use App\Models\FinanceJournal;
use App\Models\FinancePostingRequest;
use App\Models\FinanceRegisterEntry;
use App\Models\LoanAgreementAmendment;
use App\Models\LoanFacility;
use App\Models\User;
use App\Services\FinanceSetup;
use App\Services\LoanAgreementAmendments;
use App\Services\LoanRegister;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoanPolicyCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function company(string $code = 'REG'): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Export Equipment Ltd', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $preparer = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$preparer, $reviewer] as $user) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true]);
        }
        app(FinanceSetup::class)->activate($business);

        return compact('business', 'preparer', 'reviewer');
    }

    private function data(array $extra = []): array
    {
        return [...['event_on' => '2026-10-27', 'currency' => 'UGX', 'external_reference' => 'VOUCHER-REG-001', 'reason' => 'Verified source voucher, agreed policy and actual business event.', 'request_key' => (string) Str::uuid(), 'cash_account_key' => 'cash'], ...$extra];
    }

    private function prepare(array $c, string $kind, array $extra): FinanceRegisterEntry
    {
        $data = $this->data($extra);
        $this->actingAs($c['preparer'])->withSession(['active_business_id' => $c['business']->id])->post(route('finance.register.store', $kind), $data)->assertRedirect()->assertSessionHasNoErrors();

        return FinanceRegisterEntry::query()->where('business_id', $c['business']->id)->where('request_key', $data['request_key'])->firstOrFail();
    }

    private function postEntry(array $c, FinanceRegisterEntry $entry): FinanceJournal
    {
        $this->actingAs($c['preparer'])->post(route('finance.reviews.request', ['type' => 'register_entry', 'source' => $entry->id]), ['posting_on' => $entry->event_on->toDateString(), 'exchange_rate_id' => $entry->exchange_rate_id, 'basis_notes' => $entry->reason])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('business_id', $c['business']->id)->where('source_type', 'register_entry')->where('source_id', $entry->id)->firstOrFail();
        $this->post(route('finance.reviews.approve', $review->id), ['notes' => 'Own financial preparation cannot be approved.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independently verified source and register amounts.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.reviews.post', $review->id))->assertStatus(422);
        $this->actingAs($c['preparer'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
        $journal = FinanceJournal::query()->where('source_type', 'register_entry')->where('source_id', $entry->id)->firstOrFail();
        foreach ([['debit', 'credit'], ['base_debit', 'base_credit']] as [$debit,$credit]) {
            $dr = BigDecimal::zero();
            $cr = BigDecimal::zero();
            foreach ($journal->lines as $line) {
                $dr = $dr->plus((string) $line->{$debit});
                $cr = $cr->plus((string) $line->{$credit});
            }
            $this->assertTrue($dr->isEqualTo($cr));
        }

        return $journal;
    }

    private function asset(array $c): BusinessAsset
    {
        return BusinessAsset::factory()->create(['business_id' => $c['business']->id, 'created_by' => $c['preparer']->id, 'useful_life_months' => 3, 'depreciation_start_on' => '2026-08-01']);
    }

    private function loan(array $c): LoanFacility
    {
        return LoanFacility::factory()->create(['business_id' => $c['business']->id, 'currency' => 'UGX', 'limit_amount' => '2000', 'starts_on' => '2026-07-01', 'matures_on' => '2026-12-31', 'created_by' => $c['preparer']->id]);
    }

    public function test_unpaid_deferred_fee_and_monthly_allocation_are_separate_from_payment(): void
    {
        $c = $this->company();
        $loan = $this->loan($c);
        $fee = $this->prepare($c, 'loan_fee_accrual', ['loan_id' => $loan->id, 'amount' => '300', 'fee_policy' => 'straight_line', 'amortization_start_on' => '2026-08-01', 'amortization_months' => '3', 'event_on' => '2026-08-01', 'due_on' => '2026-10-30']);
        $this->postEntry($c, $fee);
        $state = app(LoanRegister::class)->state($loan);
        $this->assertTrue($state['fees']->isEqualTo('300'));
        $this->assertTrue($state['deferred_fees_base']->isEqualTo('300'));
        $allocation = $this->prepare($c, 'loan_fee_amortization', ['loan_id' => $loan->id, 'original_entry_id' => $fee->id, 'depreciation_month' => '2026-08']);
        $this->postEntry($c, $allocation);
        $this->assertTrue(BigDecimal::of((string) $allocation->amount)->isEqualTo('100'));
        $state = app(LoanRegister::class)->state($loan);
        $this->assertTrue($state['deferred_fees_base']->isEqualTo('200'));
        $this->assertTrue($state['fees']->isEqualTo('300'));
        $this->actingAs($c['preparer'])->post(route('finance.register.store', 'loan_fee_amortization'), $this->data(['loan_id' => $loan->id, 'original_entry_id' => $fee->id, 'depreciation_month' => '2026-08']))->assertStatus(422);
        $pay = $this->prepare($c, 'loan_repayment', ['loan_id' => $loan->id, 'principal' => '0', 'interest' => '0', 'fees' => '120', 'fees_treatment' => 'accrued']);
        $journal = $this->postEntry($c, $pay);
        $this->assertTrue(app(LoanRegister::class)->state($loan)['fees']->isEqualTo('180'));
        $this->assertFalse($journal->lines()->where('account_id', app(FinanceSetup::class)->account($c['business']->id, 'loan_fee_expense')->id)->exists());
    }

    public function test_capitalising_interest_moves_existing_debt_without_duplicate_expense_or_cash(): void
    {
        $c = $this->company();
        $loan = $this->loan($c);
        $this->postEntry($c, $this->prepare($c, 'loan_drawdown', ['loan_id' => $loan->id, 'amount' => '1000']));
        $this->postEntry($c, $this->prepare($c, 'loan_interest', ['loan_id' => $loan->id, 'amount' => '100']));
        $entry = $this->prepare($c, 'loan_capitalise_interest', ['loan_id' => $loan->id, 'amount' => '60']);
        $journal = $this->postEntry($c, $entry);
        $state = app(LoanRegister::class)->state($loan);
        $this->assertTrue($state['principal']->isEqualTo('1060'));
        $this->assertTrue($state['interest']->isEqualTo('40'));
        $this->assertNull($entry->cash_account_id);
        $this->assertFalse($journal->lines()->where('account_id', app(FinanceSetup::class)->account($c['business']->id, 'loan_interest_expense')->id)->exists());
    }

    public function test_independently_reviewed_terms_preserve_prior_maturity_and_never_forgive_principal(): void
    {
        $c = $this->company();
        $loan = $this->loan($c);
        $this->postEntry($c, $this->prepare($c, 'loan_drawdown', ['loan_id' => $loan->id, 'amount' => '1000']));
        $data = ['effective_on' => '2026-10-27', 'limit_amount' => '1500', 'revolving' => '0', 'matures_on' => '2027-06-30', 'agreement_reference' => 'Signed revised lender agreement 001', 'terms' => 'Lender agreed changed maturity and explicit principal limit without debt forgiveness.', 'reason' => 'Verified lender restructuring agreement and unchanged outstanding debt.', 'request_key' => (string) Str::uuid()];
        $this->actingAs($c['preparer'])->post(route('finance.loans.amend', $loan->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $amend = LoanAgreementAmendment::firstOrFail();
        $this->post(route('finance.loans.amend-review', [$amend->id, 'approve']), ['note' => 'Own proposed agreement cannot approve itself.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.loans.amend-review', [$amend->id, 'approve']), ['note' => 'Independently checked signed agreement, maturity and limit.'])->assertRedirect()->assertSessionHasNoErrors();
        $loan->refresh();
        $this->assertSame('2027-06-30', $loan->matures_on->toDateString());
        $old = app(LoanAgreementAmendments::class)->terms($loan, '2026-10-26');
        $this->assertSame('2026-12-31', $old['matures_on']);
        $this->assertSame('2000.00', $old['limit_amount']);
        $this->assertTrue(app(LoanRegister::class)->state($loan)['principal']->isEqualTo('1000'));
        $this->get(route('finance.loans.show', $loan->id))->assertOk()->assertSee('Signed revised lender agreement 001');
        $this->actingAs($c['preparer'])->post(route('finance.loans.amend', $loan->id), [...$data, 'limit_amount' => '999', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $bad = LoanAgreementAmendment::latest('id')->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.loans.amend-review', [$bad->id, 'approve']), ['note' => 'A term amendment must not silently cancel posted principal debt.'])->assertStatus(422);
    }
}
