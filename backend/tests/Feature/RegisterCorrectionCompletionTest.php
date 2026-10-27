<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessAsset;
use App\Models\BusinessMembership;
use App\Models\FinanceJournal;
use App\Models\FinancePostingRequest;
use App\Models\FinanceRegisterEntry;
use App\Models\LoanFacility;
use App\Models\User;
use App\Services\AssetRegister;
use App\Services\FinanceBankProfiles;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\FinanceWorkflows;
use App\Services\LoanRegister;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegisterCorrectionCompletionTest extends TestCase
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

    public function test_partial_disposal_proceeds_refund_never_restores_the_sold_asset(): void
    {
        $c = $this->company();
        $asset = $this->asset($c);
        $this->postEntry($c, $this->prepare($c, 'asset_opening', ['asset_id' => $asset->id, 'amount' => '1000', 'opening_accumulated' => '0']));
        $sale = $this->prepare($c, 'asset_disposal', ['asset_id' => $asset->id, 'amount' => '1200']);
        $this->postEntry($c, $sale);
        $refund = $this->prepare($c, 'register_partial_return', ['asset_id' => $asset->id, 'original_entry_id' => $sale->id, 'amount' => '200', 'cash_witness' => 'Independent custodian and witness signed the agreed price refund.']);
        $this->postEntry($c, $refund);
        $state = app(AssetRegister::class)->state($asset);
        $this->assertTrue($state['disposed']);
        $this->assertTrue($state['cost']->isZero());
        $this->assertTrue($state['book']->isZero());
        $last = $this->prepare($c, 'register_partial_return', ['asset_id' => $asset->id, 'original_entry_id' => $sale->id, 'amount' => '1000', 'cash_witness' => 'Independent custodian and witness signed the final price refund.']);
        $this->postEntry($c, $last);
        $this->assertTrue(app(AssetRegister::class)->state($asset)['disposed']);
        $this->assertSame('posted', $sale->fresh()->posting_status);
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['business']->id, app(FinanceSetup::class)->account($c['business']->id, 'cash')->id, '2026-10-27')['units']->isZero());
    }

    public function test_partial_cash_purchase_returns_restore_only_the_returned_cost_and_reject_excess(): void
    {
        $c = $this->company();
        $asset = $this->asset($c);
        $purchase = $this->prepare($c, 'asset_purchase', ['asset_id' => $asset->id, 'amount' => '1000', 'paid' => '1']);
        $this->postEntry($c, $purchase);
        $this->actingAs($c['preparer'])->post(route('finance.register.store', 'register_partial_return'), $this->data(['asset_id' => $asset->id, 'original_entry_id' => $purchase->id, 'amount' => '200']))->assertStatus(422);
        $returned = $this->prepare($c, 'register_partial_return', ['asset_id' => $asset->id, 'original_entry_id' => $purchase->id, 'amount' => '200', 'cash_witness' => 'Cash custodian and independent witness signed return voucher 01.']);
        $this->postEntry($c, $returned);
        $state = app(AssetRegister::class)->state($asset);
        $this->assertTrue($state['cost']->isEqualTo('800'));
        $this->assertSame('posted', $purchase->fresh()->posting_status);
        $this->actingAs($c['preparer'])->post(route('finance.register.store', 'register_partial_return'), $this->data(['asset_id' => $asset->id, 'original_entry_id' => $purchase->id, 'amount' => '801', 'cash_witness' => 'Verified signed cash return witnessed independently.']))->assertStatus(422);
        $last = $this->prepare($c, 'register_partial_return', ['asset_id' => $asset->id, 'original_entry_id' => $purchase->id, 'amount' => '800', 'cash_witness' => 'Cash custodian and independent witness signed return voucher 02.']);
        $this->postEntry($c, $last);
        $this->assertSame('reversed', $purchase->fresh()->posting_status);
        $this->assertTrue(app(AssetRegister::class)->state($asset)['cost']->isZero());
    }

    public function test_full_physical_cash_correction_requires_signed_voucher_and_keeps_original_history(): void
    {
        $c = $this->company();
        $asset = $this->asset($c);
        $purchase = $this->prepare($c, 'asset_purchase', ['asset_id' => $asset->id, 'amount' => '1000', 'paid' => '1']);
        $this->postEntry($c, $purchase);
        $reverse = $this->prepare($c, 'register_reversal', ['asset_id' => $asset->id, 'original_entry_id' => $purchase->id, 'cash_witness' => 'Independent custodian witnessed actual cash returned in signed voucher.']);
        $this->postEntry($c, $reverse);
        $this->assertSame('reversed', $purchase->fresh()->posting_status);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertTrue(app(AssetRegister::class)->state($asset)['cost']->isZero());
    }

    public function test_asset_sale_receipt_uses_actual_foreign_currency_and_historical_asset_value(): void
    {
        $c = $this->company();
        $asset = $this->asset($c);
        $opening = $this->prepare($c, 'asset_opening', ['asset_id' => $asset->id, 'amount' => '3700000', 'opening_accumulated' => '370000']);
        $this->postEntry($c, $opening);
        $bank = app(FinanceBankProfiles::class)->create($c['business'], ['label' => 'Asset USD proceeds', 'institution' => 'Bank', 'currency' => 'USD'], $c['preparer']->id);
        $rate = app(FinanceRates::class)->propose($c['business'], ['currency' => 'USD', 'rate_to_base' => '3800', 'effective_on' => '2026-10-27', 'source_note' => 'Verified manual asset sale proceeds rate.'], $c['preparer']->id);
        app(FinanceRates::class)->approve($rate, $c['reviewer']->id);
        $sale = $this->prepare($c, 'asset_disposal', ['asset_id' => $asset->id, 'amount' => '1200', 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'bank_profile_id' => $bank->id, 'cash_account_key' => 'bank']);
        $journal = $this->postEntry($c, $sale);
        $this->assertSame('USD', $journal->currency);
        $state = app(AssetRegister::class)->state($asset);
        $this->assertTrue($state['disposed']);
        $this->assertTrue($state['book']->isZero());
        $gain = $journal->lines()->where('account_id', app(FinanceSetup::class)->account($c['business']->id, 'asset_disposal_gain')->id)->firstOrFail();
        $this->assertTrue(BigDecimal::of((string) $gain->base_credit)->isEqualTo('1230000'));
        $this->get(route('finance.assets.show', $asset->id))->assertOk();
    }

    public function test_partial_loan_repayment_return_restores_schedule_components_without_new_interest_expense(): void
    {
        $c = $this->company();
        $loan = LoanFacility::factory()->create(['business_id' => $c['business']->id, 'currency' => 'UGX', 'limit_amount' => '2000', 'starts_on' => '2026-10-01', 'matures_on' => '2026-12-31', 'created_by' => $c['preparer']->id]);
        $draw = $this->prepare($c, 'loan_drawdown', ['loan_id' => $loan->id, 'amount' => '1000']);
        $this->postEntry($c, $draw);
        $inst = app(LoanRegister::class)->schedule($c['business'], $loan, ['reference' => 'INST-1', 'due_on' => '2026-10-30', 'principal' => '100', 'interest' => '10', 'fees' => '0'], $c['preparer']->id);
        $payment = $this->prepare($c, 'loan_repayment', ['loan_id' => $loan->id, 'principal' => '100', 'interest' => '10', 'fees' => '0', 'interest_treatment' => 'paid', 'instalment_id' => $inst->id]);
        $this->postEntry($c, $payment);
        $returned = $this->prepare($c, 'register_partial_return', ['loan_id' => $loan->id, 'original_entry_id' => $payment->id, 'amount' => '55', 'cash_witness' => 'Independent custodian verified signed cash return from the lender.']);
        $this->postEntry($c, $returned);
        $state = app(LoanRegister::class)->state($loan);
        $this->assertTrue($state['principal']->isEqualTo('950'));
        $parts = app(LoanRegister::class)->instalmentBalances($inst);
        $this->assertTrue($parts['principal']->isEqualTo('50'));
        $this->assertTrue($parts['interest']->isEqualTo('5'));
    }
}
