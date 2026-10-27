<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessAsset;
use App\Models\BusinessMembership;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinanceJournal;
use App\Models\FinancePostingRequest;
use App\Models\FinanceRegisterEntry;
use App\Models\LoanFacility;
use App\Models\StaffPerson;
use App\Models\User;
use App\Services\AssetRegister;
use App\Services\FinanceBankProfiles;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\LoanRegister;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetBorrowingPhaseFourTest extends TestCase
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

    public function test_owned_equipment_custody_depreciation_disposal_and_journals_agree(): void
    {
        $c = $this->company();
        $asset = $this->asset($c);
        $this->postEntry($c, $this->prepare($c, 'asset_purchase', ['asset_id' => $asset->id, 'amount' => '900', 'paid' => '1', 'event_on' => '2026-08-01']));
        $person = StaffPerson::create(['business_id' => $c['business']->id, 'name' => 'Packing supervisor', 'staff_code' => 'PACK-01', 'kind' => 'employee', 'active' => true]);
        $activity = ['kind' => 'assign', 'staff_person_id' => $person->id, 'location' => 'Packing room', 'event_on' => '2026-10-27', 'reference' => 'HANDOVER-001', 'note' => 'Supervisor received and checked the scale.', 'request_key' => (string) Str::uuid()];
        $this->post(route('finance.assets.activity', $asset->id), $activity)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.assets.activity', $asset->id), $activity)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('asset_activities', 1);
        $this->assertSame($person->id, $asset->fresh()->staff_person_id);
        foreach (['2026-08-31', '2026-09-30'] as $date) {
            $this->postEntry($c, $this->prepare($c, 'asset_depreciation', ['asset_id' => $asset->id, 'event_on' => $date]));
        }
        $state = app(AssetRegister::class)->state($asset);
        $this->assertSame('300.00', (string) $state['book']->toScale(2));
        $journal = $this->postEntry($c, $this->prepare($c, 'asset_disposal', ['asset_id' => $asset->id, 'amount' => '400']));
        $this->assertTrue($journal->lines->contains(fn ($l) => $l->account->system_key === 'asset_disposal_gain' && (int) $l->credit === 100));
        $state = app(AssetRegister::class)->state($asset);
        $this->assertTrue($state['book']->isZero());
        $this->assertTrue($state['disposed']);
        $this->get(route('finance.assets.index'))->assertOk()->assertSee('Packing scale');
        $this->get(route('finance.assets.show', $asset->id))->assertOk()->assertSee('Disposed (posted)');
        $this->get(route('finance.register.show', FinanceRegisterEntry::query()->latest('id')->firstOrFail()->id))->assertOk();
        $this->get(route('finance.reviews'))->assertOk();
        $this->post(route('finance.assets.activity', $asset->id), [...$activity, 'request_key' => (string) Str::uuid()])->assertStatus(422);
    }

    public function test_unpaid_equipment_and_partial_payment_do_not_expense_the_purchase_twice(): void
    {
        $c = $this->company();
        $asset = $this->asset($c);
        $journal = $this->postEntry($c, $this->prepare($c, 'asset_purchase', ['asset_id' => $asset->id, 'amount' => '900', 'paid' => '0', 'event_on' => '2026-08-01']));
        $this->assertFalse($journal->lines->contains(fn ($l) => $l->account->system_key === 'cash'));
        $this->postEntry($c, $this->prepare($c, 'asset_payment', ['asset_id' => $asset->id, 'amount' => '400']));
        $state = app(AssetRegister::class)->state($asset);
        $this->assertTrue($state['book']->isEqualTo('900'));
        $this->assertTrue($state['payable']->isEqualTo('500'));
        $this->post(route('finance.register.store', 'asset_payment'), $this->data(['asset_id' => $asset->id, 'amount' => '501']))->assertStatus(422);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_foreign_borrowing_revaluation_and_partial_repayment_keep_principal_interest_fees_and_fx_separate(): void
    {
        $c = $this->company();
        $loan = LoanFacility::factory()->create(['business_id' => $c['business']->id, 'created_by' => $c['preparer']->id, 'currency' => 'USD', 'limit_amount' => '1000']);
        $profile = app(FinanceBankProfiles::class)->create($c['business'], ['label' => 'Dollar bank', 'institution' => 'Example bank', 'currency' => 'USD'], $c['preparer']->id);
        $rates = [];
        foreach (['3700', '3800', '3900'] as $rate) {
            $fx = app(FinanceRates::class)->propose($c['business'], ['currency' => 'USD', 'rate_to_base' => $rate, 'effective_on' => '2026-10-20', 'source_note' => 'Verified manual lender and bank rate'], $c['preparer']->id);
            app(FinanceRates::class)->approve($fx, $c['reviewer']->id);
            $rates[] = $fx;
        }
        $this->postEntry($c, $this->prepare($c, 'loan_drawdown', ['loan_id' => $loan->id, 'currency' => 'USD', 'amount' => '1000', 'exchange_rate_id' => $rates[0]->id, 'bank_profile_id' => $profile->id]));
        $this->postEntry($c, $this->prepare($c, 'loan_interest', ['loan_id' => $loan->id, 'currency' => 'USD', 'amount' => '20', 'exchange_rate_id' => $rates[0]->id]));
        $this->postEntry($c, $this->prepare($c, 'loan_revaluation', ['loan_id' => $loan->id, 'currency' => 'USD', 'exchange_rate_id' => $rates[1]->id]));
        $instalment = app(LoanRegister::class)->schedule($c['business'], $loan, ['reference' => 'INST-01', 'due_on' => '2026-10-27', 'principal' => '500', 'interest' => '10', 'fees' => '1'], $c['preparer']->id);
        $repay = $this->prepare($c, 'loan_repayment', ['loan_id' => $loan->id, 'currency' => 'USD', 'principal' => '500', 'interest' => '10', 'fees' => '1', 'interest_treatment' => 'accrued', 'exchange_rate_id' => $rates[2]->id, 'bank_profile_id' => $profile->id, 'instalment_id' => $instalment->id]);
        $this->postEntry($c, $repay);
        $state = app(LoanRegister::class)->state($loan);
        $this->assertTrue($state['principal']->isEqualTo('500'));
        $this->assertTrue($state['principal_base']->isEqualTo('1900000'));
        $this->assertTrue($state['interest']->isEqualTo('10'));
        $this->assertTrue($state['interest_base']->isEqualTo('38000'));
        $this->assertTrue($state['unreal']['principal']->isEqualTo('-50000'));
        $this->assertTrue($state['unreal']['interest']->isEqualTo('-1000'));
        $this->assertTrue(app(LoanRegister::class)->instalmentBalances($instalment)['principal']->isZero());
        $this->get(route('finance.loans.index'))->assertOk()->assertSee($loan->reference);
        $this->get(route('finance.loans.show', $loan->id))->assertOk()->assertSee('500');
        $this->post(route('finance.register.store', 'loan_repayment'), $this->data(['loan_id' => $loan->id, 'currency' => 'USD', 'principal' => '501', 'interest' => '0', 'fees' => '0', 'exchange_rate_id' => $rates[2]->id, 'bank_profile_id' => $profile->id]))->assertStatus(422);
    }

    public function test_register_sources_are_tenant_scoped_and_frozen_and_hired_assets_have_no_owned_book_value(): void
    {
        $c = $this->company();
        $other = $this->company('OTHER');
        $asset = $this->asset($c);
        $this->actingAs($other['preparer'])->withSession(['active_business_id' => $other['business']->id])->get(route('finance.assets.show', $asset->id))->assertNotFound();
        $this->post(route('finance.register.store', 'asset_purchase'), $this->data(['asset_id' => $asset->id, 'amount' => '100', 'paid' => '1']))->assertNotFound();
        $source = $this->prepare($c, 'asset_purchase', ['asset_id' => $asset->id, 'amount' => '900', 'paid' => '1']);
        $source->update(['amount' => '1']);
        $this->post(route('finance.reviews.request', ['type' => 'register_entry', 'source' => $source->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Altered immutable register source must be refused.'])->assertStatus(422);
        $hired = BusinessAsset::factory()->create(['business_id' => $c['business']->id, 'created_by' => $c['preparer']->id, 'ownership' => 'hired']);
        $this->post(route('finance.register.store', 'asset_purchase'), $this->data(['asset_id' => $hired->id, 'amount' => '100', 'paid' => '1']))->assertStatus(422);
        $viewer = User::factory()->create();
        BusinessMembership::create(['business_id' => $c['business']->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'active' => true]);
        $this->actingAs($viewer)->get(route('finance.assets.index'))->assertForbidden();
        $this->post(route('finance.loans.store'), [])->assertForbidden();
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_late_non_cash_correction_retains_original_and_can_replace_the_depreciation_month(): void
    {
        $c = $this->company();
        $asset = $this->asset($c);
        $this->postEntry($c, $this->prepare($c, 'asset_purchase', ['asset_id' => $asset->id, 'amount' => '900', 'paid' => '1', 'event_on' => '2026-08-01']));
        $depreciation = $this->prepare($c, 'asset_depreciation', ['asset_id' => $asset->id, 'event_on' => '2026-08-31']);
        $this->postEntry($c, $depreciation);
        $reverse = $this->prepare($c, 'register_reversal', ['asset_id' => $asset->id, 'original_entry_id' => $depreciation->id, 'event_on' => '2026-09-01']);
        $this->postEntry($c, $reverse);
        $this->assertSame('reversed', $depreciation->fresh()->posting_status);
        $this->assertTrue(app(AssetRegister::class)->state($asset)['book']->isEqualTo('900'));
        $replacement = $this->prepare($c, 'asset_depreciation', ['asset_id' => $asset->id, 'event_on' => '2026-09-01', 'depreciation_month' => '2026-08']);
        $this->postEntry($c, $replacement);
        $this->assertTrue(app(AssetRegister::class)->state($asset)['book']->isEqualTo('600'));
        $this->assertDatabaseCount('finance_journals', 4);
        $this->get(route('finance.position'))->assertOk()->assertViewHas('report', fn ($r) => $r['registerReconciliation']['matches']);
    }

    public function test_cash_correction_needs_an_actual_matched_return_and_restores_the_loan_balance(): void
    {
        $c = $this->company();
        $loan = LoanFacility::factory()->create(['business_id' => $c['business']->id, 'created_by' => $c['preparer']->id, 'limit_amount' => '1000']);
        $profile = app(FinanceBankProfiles::class)->create($c['business'], ['label' => 'Operating bank', 'institution' => 'Example bank', 'currency' => 'UGX'], $c['preparer']->id);
        $draw = $this->prepare($c, 'loan_drawdown', ['loan_id' => $loan->id, 'amount' => '1000', 'bank_profile_id' => $profile->id]);
        $this->postEntry($c, $draw);
        $this->actingAs($c['preparer'])->post(route('finance.register.store', 'register_reversal'), $this->data(['loan_id' => $loan->id, 'original_entry_id' => $draw->id]))->assertStatus(422);
        $statement = FinanceBankStatement::create(['business_id' => $c['business']->id, 'account_id' => $profile->account_id, 'reference' => 'BANK-RETURN', 'currency' => 'UGX', 'period_from' => '2026-10-27', 'period_to' => '2026-10-27', 'opening_balance' => '1000', 'closing_balance' => '0', 'credits' => '0', 'debits' => '1000', 'file_sha256' => hash('sha256', 'Loan return statement'), 'filename' => 'statement.csv', 'status' => 'open', 'imported_by' => $c['preparer']->id]);
        $line = FinanceBankStatementLine::create(['business_id' => $c['business']->id, 'statement_id' => $statement->id, 'line_number' => 1, 'transaction_on' => '2026-10-27', 'direction' => 'debit', 'amount' => '1000', 'bank_reference' => 'LENDER-RETURN', 'description' => 'Full principal returned to lender']);
        $reverse = $this->prepare($c, 'register_reversal', ['loan_id' => $loan->id, 'original_entry_id' => $draw->id, 'return_bank_line_id' => $line->id]);
        $line->update(['bank_reference' => 'CHANGED-AFTER-PREPARATION']);
        $this->post(route('finance.reviews.request', ['type' => 'register_entry', 'source' => $reverse->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'A changed bank return must not enter the approval queue.'])->assertStatus(422);
        $line->update(['bank_reference' => 'LENDER-RETURN']);
        $this->postEntry($c, $reverse);
        $state = app(LoanRegister::class)->state($loan);
        $this->assertTrue($state['principal']->isZero());
        $this->assertTrue($state['drawn']->isZero());
        $this->actingAs($c['preparer'])->get(route('finance.bank.show', $statement->id))->assertOk()->assertSee($reverse->reference);
        $this->post(route('finance.bank.match', [$statement->id, $line->id]), ['candidate' => 'register_entry:'.$reverse->id, 'note' => 'Actual full return matches this reviewed correction.'])->assertRedirect()->assertSessionHasNoErrors();
        $match = FinanceBankMatch::firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.bank.confirm', $match->id), ['note' => 'Independently checked lender and bank return evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $match->fresh()->status);
    }

    public function test_unsubmitted_and_approved_sources_can_be_withdrawn_without_erasing_history(): void
    {
        $c = $this->company();
        $asset = $this->asset($c);
        $draft = $this->prepare($c, 'asset_purchase', ['asset_id' => $asset->id, 'amount' => '900', 'paid' => '1']);
        $this->post(route('finance.register.cancel', $draft->id), ['reason' => 'Wrong source reference entered before submission.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected', $draft->fresh()->posting_status);
        $next = $this->prepare($c, 'asset_purchase', ['asset_id' => $asset->id, 'amount' => '900', 'paid' => '1']);
        $this->post(route('finance.reviews.request', ['type' => 'register_entry', 'source' => $next->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Source reviewed for independent financial approval.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Approved after independent source review.'])->assertRedirect();
        $this->actingAs($c['preparer'])->post(route('finance.reviews.withdraw', $review->id), ['reason' => 'The preparer cannot revoke another person approval.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.withdraw', $review->id), ['reason' => 'The reviewer withdrew approval after a corrected source was supplied.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected', $review->fresh()->status);
        $this->assertSame('rejected', $next->fresh()->posting_status);
        $this->assertDatabaseCount('finance_register_entries', 2);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_schedule_replacement_retains_posted_debt_and_paid_history(): void
    {
        $c = $this->company();
        $loan = LoanFacility::factory()->create(['business_id' => $c['business']->id, 'created_by' => $c['preparer']->id, 'limit_amount' => '1000']);
        $draw = $this->prepare($c, 'loan_drawdown', ['loan_id' => $loan->id, 'amount' => '1000']);
        $this->postEntry($c, $draw);
        $schedule = app(LoanRegister::class)->schedule($c['business'], $loan, ['due_on' => '2026-10-30', 'principal' => '1000', 'interest' => '0', 'fees' => '0', 'reference' => 'ORIGINAL-PLAN'], $c['preparer']->id);
        $repayment = $this->prepare($c, 'loan_repayment', ['loan_id' => $loan->id, 'instalment_id' => $schedule->id, 'principal' => '400', 'interest' => '0', 'fees' => '0']);
        $this->postEntry($c, $repayment);
        $accountant = User::factory()->create();
        BusinessMembership::create(['business_id' => $c['business']->id, 'user_id' => $accountant->id, 'role' => 'accountant', 'active' => true]);
        $this->actingAs($accountant)->post(route('finance.loans.schedule.cancel', $schedule->id), ['reason' => 'Replace the remaining lender schedule with its agreed amendment.'])->assertForbidden();
        $this->actingAs($c['reviewer'])->post(route('finance.loans.schedule.cancel', $schedule->id), ['reason' => 'Replace the remaining lender schedule with its agreed amendment.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $schedule->fresh()->status);
        $this->assertTrue(app(LoanRegister::class)->state($loan)['principal']->isEqualTo('600'));
        $this->assertSame('posted', $repayment->fresh()->posting_status);
        $this->get(route('finance.position'))->assertOk()->assertViewHas('report', fn ($r) => ! isset($r['funding'][7]['UGX']) || $r['funding'][7]['UGX']['loans']->isZero());
        $this->post(route('finance.loans.schedule', $loan->id), ['due_on' => '2026-10-31', 'principal' => '601', 'interest' => '0', 'fees' => '0', 'reference' => 'TOO-LARGE'])->assertStatus(422);
        $this->post(route('finance.loans.schedule', $loan->id), ['due_on' => '2026-10-31', 'principal' => '600', 'interest' => '0', 'fees' => '0', 'reference' => 'REPLACEMENT-PLAN'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('finance.position'))->assertOk()->assertViewHas('report', fn ($r) => $r['funding'][7]['UGX']['loans']->isEqualTo('600'));
        $this->get(route('finance.loans.show', $loan->id))->assertOk()->assertSee('REPLACEMENT-PLAN')->assertSee('ORIGINAL-PLAN');
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_asset_identity_can_be_corrected_but_posted_financial_policy_is_locked(): void
    {
        $c = $this->company();
        $asset = $this->asset($c);
        $purchase = $this->prepare($c, 'asset_purchase', ['asset_id' => $asset->id, 'amount' => '900', 'paid' => '1']);
        $this->postEntry($c, $purchase);
        $data = ['asset_code' => $asset->asset_code, 'name' => 'Corrected packing scale', 'category' => 'equipment', 'serial_number' => 'SERIAL-123', 'reason' => 'Correct the manufacturer serial number from the original receipt.', 'ownership' => 'hired'];
        $this->actingAs($c['preparer'])->put(route('finance.assets.update', $asset->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Corrected packing scale', $asset->fresh()->name);
        $this->assertSame('owned', $asset->fresh()->ownership);
        $this->assertSame('Packing scale', $purchase->fresh()->data_snapshot['register_name']);
        $this->put(route('finance.assets.update', $asset->id), [...$data, 'residual_base' => '100'])->assertStatus(422);
        $this->assertTrue(app(AssetRegister::class)->state($asset->fresh())['book']->isEqualTo('900'));
    }
}
