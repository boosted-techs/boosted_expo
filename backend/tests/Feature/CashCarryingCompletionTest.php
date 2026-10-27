<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\FinanceBankProfile;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\FinanceWorkflow;
use App\Models\User;
use App\Services\FinanceBankProfiles;
use App\Services\FinanceFxRevaluations;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\FinanceWorkflowCorrections;
use App\Services\FinanceWorkflows;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashCarryingCompletionTest extends TestCase
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

    private function rate(array $c, string $value): FinanceExchangeRate
    {
        $rate = app(FinanceRates::class)->propose($c['business'], ['currency' => 'USD', 'rate_to_base' => $value, 'effective_on' => '2026-10-27', 'source_note' => 'Verified manual money-account currency basis.'], $c['owner']->id);
        app(FinanceRates::class)->approve($rate, $c['reviewer']->id);

        return $rate;
    }

    private function bank(array $c, string $label = 'USD cash account'): FinanceBankProfile
    {
        return app(FinanceBankProfiles::class)->create($c['business'], ['label' => $label, 'institution' => 'Test bank', 'currency' => 'USD'], $c['owner']->id);
    }

    private function postSource(array $c, string $type, int $id, ?int $fx = null): void
    {
        $this->actingAs($c['owner'])->post(route('finance.reviews.request', ['type' => $type, 'source' => $id]), ['posting_on' => '2026-10-27', 'exchange_rate_id' => $fx, 'basis_notes' => 'Verified source event and independent account valuation evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $r = FinancePostingRequest::where('business_id', $c['business']->id)->where('source_type', $type)->where('source_id', $id)->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $r->id), ['notes' => 'Checked original money source and current posted carrying basis.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $r->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    private function fund(array $c, FinanceBankProfile $bank, FinanceExchangeRate $rate, string $amount = '1000'): void
    {
        $flow = $this->prepare($c, $this->data(['currency' => 'USD', 'amount' => $amount, 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $rate->id]));
        $this->postFlow($c, $flow);
    }

    private function revalue(array $c, FinanceBankProfile $bank, FinanceExchangeRate $rate): void
    {
        $source = app(FinanceFxRevaluations::class)->prepare($c['business'], ['bank_profile_id' => $bank->id, 'exchange_rate_id' => $rate->id, 'valued_on' => '2026-10-27', 'valuation_evidence' => 'Verified independent reporting rate and actual foreign balance.'], $c['owner']->id);
        $this->postSource($c, 'fx_revaluation', $source->id, $rate->id);
    }

    private function money(array $c, array $data): FinanceOperation
    {
        $this->actingAs($c['owner'])->post(route('finance.money.store'), [...['event_on' => '2026-10-27', 'currency' => 'USD', 'description' => 'Verified actual foreign money event', 'external_reference' => 'USD-VOUCHER', 'idempotency_key' => (string) Str::uuid()], ...$data])->assertRedirect()->assertSessionHasNoErrors();

        return FinanceOperation::latest('id')->firstOrFail();
    }

    public function test_complete_owner_funding_correction_requires_actual_evidence_and_keeps_the_original(): void
    {
        $c = $this->context();
        $flow = $this->prepare($c, $this->data());
        $this->postFlow($c, $flow);
        $data = ['event_on' => '2026-10-27', 'reason' => 'Documented complete return of an incorrectly classified owner funding receipt.', 'external_reference' => 'OWNER-RETURN-001', 'request_key' => (string) Str::uuid()];
        $this->post(route('finance.treasury.correction', $flow->id), $data)->assertStatus(422);
        $data['cash_witness'] = 'Cash custodian and independent witness signed the complete return voucher.';
        $this->post(route('finance.treasury.correction', $flow->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $correction = FinanceWorkflow::query()->where('kind', 'workflow_correction')->firstOrFail();
        $this->postFlow($c, $correction);
        $this->assertSame('0.00', $this->balance($c, 'cash'));
        $this->assertSame('0.00', $this->balance($c, 'owner_capital'));
        $this->assertSame('posted', $flow->fresh()->posting_status);
        $this->assertTrue(app(FinanceWorkflowCorrections::class)->corrected($c['business']->id, $flow->id));
        $this->actingAs($c['owner'])->post(route('finance.treasury.correction', $flow->id), [...$data, 'request_key' => (string) Str::uuid()])->assertStatus(422);
    }

    public function test_exchange_of_revalued_currency_clears_units_book_value_and_unrealised_gain(): void
    {
        $c = $this->context();
        $bank = $this->bank($c);
        $old = $this->rate($c, '3700');
        $new = $this->rate($c, '3800');
        $this->fund($c, $bank, $old);
        $this->revalue($c, $bank, $new);
        $exchange = $this->prepare($c, $this->data(['kind' => 'exchange', 'currency' => 'USD', 'amount' => '1000', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $new->id, 'destination_currency' => 'UGX', 'destination_account_key' => 'cash', 'received_amount' => '3900000', 'fee_amount' => '0']));
        $this->postFlow($c, $exchange);
        $state = app(FinanceWorkflows::class)->accountBasis($c['business']->id, $bank->account_id, '2026-10-27');
        $this->assertTrue($state['units']->isZero());
        $this->assertTrue($state['base']->isZero());
        $this->assertSame('0.00', $this->balance($c, 'unrealized_fx_gain'));
        $this->assertSame('-200000.00', $this->balance($c, 'fx_gain'));
        $this->get(route('finance.reviews'))->assertOk()->assertSee('Approved money-account values');
    }

    public function test_owner_foreign_drawing_uses_historical_money_carrying_without_a_residual_balance(): void
    {
        $c = $this->context();
        $bank = $this->bank($c);
        $old = $this->rate($c, '3700');
        $new = $this->rate($c, '3800');
        $this->fund($c, $bank, $old);
        $draw = $this->prepare($c, $this->data(['kind' => 'owner_drawing', 'currency' => 'USD', 'amount' => '1000', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $new->id]));
        $this->postFlow($c, $draw);
        $basis = app(FinanceWorkflows::class)->accountBasis($c['business']->id, $bank->account_id, '2026-10-27');
        $this->assertTrue($basis['units']->isZero());
        $this->assertTrue($basis['base']->isZero());
        $this->assertSame('3800000.00', $this->balance($c, 'owner_drawings'));
        $this->assertSame('-100000.00', $this->balance($c, 'fx_gain'));
    }

    public function test_same_currency_transfer_moves_book_value_and_unrealised_basis_to_the_receiving_account(): void
    {
        $c = $this->context();
        $from = $this->bank($c, 'USD source');
        $to = $this->bank($c, 'USD destination');
        $old = $this->rate($c, '3700');
        $new = $this->rate($c, '3800');
        $this->fund($c, $from, $old);
        $this->revalue($c, $from, $new);
        $transfer = $this->money($c, ['kind' => 'transfer', 'amount' => '400', 'bank_profile_id' => $from->id, 'destination_bank_profile_id' => $to->id, 'exchange_rate_id' => $new->id]);
        $this->postSource($c, 'money_operation', $transfer->id, $new->id);
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['business']->id, $to->account_id, '2026-10-27')['base']->isEqualTo('1520000'));
        $this->assertSame('0.00', $this->balance($c, 'fx_gain'));
        $this->assertSame('-100000.00', $this->balance($c, 'unrealized_fx_gain'));
        $exchange = $this->prepare($c, $this->data(['kind' => 'exchange', 'currency' => 'USD', 'amount' => '400', 'bank_profile_id' => $to->id, 'exchange_rate_id' => $new->id, 'destination_currency' => 'UGX', 'destination_account_key' => 'cash', 'received_amount' => '1560000', 'fee_amount' => '0']));
        $this->postFlow($c, $exchange);
        $this->assertSame('-60000.00', $this->balance($c, 'unrealized_fx_gain'));
        $this->assertSame('-80000.00', $this->balance($c, 'fx_gain'));
    }

    public function test_changed_foreign_account_after_approval_requires_the_approver_to_review_again(): void
    {
        $c = $this->context();
        $bank = $this->bank($c);
        $rate = $this->rate($c, '3700');
        $this->fund($c, $bank, $rate);
        $expense = $this->money($c, ['kind' => 'paid_expense', 'amount' => '100', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $rate->id, 'category' => 'rent']);
        $this->post(route('finance.reviews.request', ['type' => 'money_operation', 'source' => $expense->id]), ['posting_on' => '2026-10-27', 'exchange_rate_id' => $rate->id, 'basis_notes' => 'Verified actual paid rent and bank account evidence.'])->assertRedirect();
        $review = FinancePostingRequest::where('source_type', 'money_operation')->where('source_id', $expense->id)->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Verified source and current bank carrying balance.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->fund($c, $bank, $rate, '1');
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertStatus(422);
        $this->post(route('finance.reviews.refresh-cash', $review->id), ['reason' => 'Requester cannot discard another person’s financial approval.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.refresh-cash', $review->id), ['reason' => 'Bank received new funding after approval; current cash basis needs review.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independently reviewed updated bank balance and unchanged expense voucher.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['business']->id, $bank->account_id, '2026-10-27')['units']->isEqualTo('901'));
    }

    public function test_actual_foreign_overdraft_and_its_repayment_close_at_zero_units_and_zero_book_value(): void
    {
        $c = $this->context();
        $bank = $this->bank($c);
        $old = $this->rate($c, '3700');
        $new = $this->rate($c, '3800');
        $expense = $this->money($c, ['kind' => 'paid_expense', 'amount' => '10', 'bank_profile_id' => $bank->id, 'exchange_rate_id' => $old->id, 'category' => 'utilities']);
        $this->postSource($c, 'money_operation', $expense->id, $old->id);
        $this->fund($c, $bank, $new, '10');
        $state = app(FinanceWorkflows::class)->accountBasis($c['business']->id, $bank->account_id, '2026-10-27');
        $this->assertTrue($state['units']->isZero());
        $this->assertTrue($state['base']->isZero());
        $this->assertSame('1000.00', $this->balance($c, 'fx_loss'));
    }
}
