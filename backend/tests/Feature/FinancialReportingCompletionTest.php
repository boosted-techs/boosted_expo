<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\FinanceJournal;
use App\Models\FinancePeriod;
use App\Models\FinancePostingRequest;
use App\Models\FinanceWorkflow;
use App\Models\StaffPerson;
use App\Models\User;
use App\Services\FinanceLedger;
use App\Services\FinanceManagementReports;
use App\Services\FinanceOwnerPosition;
use App\Services\FinanceSetup;
use App\Services\FinanceTreasury;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialReportingCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(string $code = 'RPT'): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $b = Business::create(['name' => 'Export reporting '.$code, 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $a = User::factory()->create();
        $r = User::factory()->create();
        foreach ([$a, $r] as $u) {
            BusinessMembership::create(['business_id' => $b->id, 'user_id' => $u->id, 'role' => 'owner', 'active' => true]);
        }app(FinanceSetup::class)->activate($b);
        $this->actingAs($a)->withSession(['active_business_id' => $b->id]);

        return compact('b', 'a', 'r');
    }

    private function record(array $c, int $id, string $date, string $currency, string $rate, string $debit, string $credit, string $amount, string $base, string $description = 'Verified report fixture'): FinanceJournal
    {
        $l = app(FinanceLedger::class);

        return $l->record($c['b'], 'report_fixture', $id, $currency, $rate, $date, $description, $c['a']->id, $c['r']->id, [$l->line($debit, $amount, '0', $base, '0'), $l->line($credit, '0', $amount, '0', $base)]);
    }

    public function test_dated_cash_and_expenses_keep_currency_units_and_export_the_filtered_evidence_safely(): void
    {
        $c = $this->context();
        $this->record($c, 1, '2026-10-25', 'UGX', '1', 'cash', 'opening_equity', '1000', '1000');
        $this->record($c, 2, '2026-10-26', 'USD', '3700', 'bank', 'opening_equity', '200', '740000');
        $this->record($c, 3, '2026-10-26', 'UGX', '1', 'general_expense', 'cash', '100', '100', 'Actual paid rent for packing premises');
        $this->record($c, 4, '2026-10-27', 'UGX', '1', 'owner_drawings', 'cash', '200', '200', '=HYPERLINK("https://example.invalid","withdrawal")');
        $r = app(FinanceManagementReports::class)->activity($c['b'], '2026-10-26', '2026-10-27');
        $this->assertTrue($r['expenses']->isEqualTo('100'));
        $cash = collect($r['cash'])->firstWhere('currency', 'UGX');
        $usd = collect($r['cash'])->firstWhere('currency', 'USD');
        $this->assertTrue($cash['opening']->isEqualTo('1000'));
        $this->assertTrue($cash['closing']->isEqualTo('700'));
        $this->assertTrue($usd['closing']->isEqualTo('200'));
        $this->assertTrue($usd['closing_base']->isEqualTo('740000'));
        $this->get(route('finance.reports.activity', ['from' => '2026-10-26', 'to' => '2026-10-27']))->assertOk()->assertSee('Spending and money movements');
        $csv = $this->get(route('finance.reports.activity', ['from' => '2026-10-27', 'to' => '2026-10-27', 'currency' => 'UGX', 'export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Actual paid rent', $csv);
        $this->assertStringNotContainsString('740000', $csv);
        $account = app(FinanceSetup::class)->account($c['b']->id, 'bank');
        $ledger = $this->get(route('finance.reports.ledger', ['account' => $account->id, 'from' => '2026-10-26', 'to' => '2026-10-27', 'export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('USD,200', $ledger);
        $this->assertStringContainsString('740000', $ledger);
        $other = $this->context('OTHER');
        $otherAccount = app(FinanceSetup::class)->account($other['b']->id, 'cash');
        $this->actingAs($c['a'])->withSession(['active_business_id' => $c['b']->id])->get(route('finance.reports.ledger', ['account' => $otherAccount->id, 'from' => '2026-10-26', 'to' => '2026-10-27']))->assertNotFound();
    }

    public function test_changed_posted_evidence_blocks_period_closing_even_if_amounts_still_balance(): void
    {
        $c = $this->context();
        $journal = $this->record($c, 1, '2026-10-27', 'UGX', '1', 'cash', 'opening_equity', '1000', '1000');
        $journal->update(['description' => 'Changed without a reviewed correction after original posting.']);
        $report = app(FinanceManagementReports::class)->reconciliation($c['b'], '2026-10-27');
        $this->assertFalse($report['matches']);
        $this->assertSame('changed_posting', $report['issues'][0]['kind']);
        $this->post(route('finance.controls.periods.store'), ['period_from' => '2026-10-27', 'period_to' => '2026-10-27'])->assertRedirect()->assertSessionHasNoErrors();
        $period = FinancePeriod::firstOrFail();
        $this->post(route('finance.controls.periods.submit', $period->id), ['reason' => 'Attempted close must detect changed original posting evidence.'])->assertSessionHasErrors('period');
    }

    public function test_cash_surplus_is_not_a_money_account_and_signed_count_remains_verifiable_after_later_days(): void
    {
        $c = $this->context();
        $this->record($c, 1, '2026-10-27', 'UGX', '1', 'cash', 'opening_equity', '1000', '1000');
        $person = StaffPerson::create(['business_id' => $c['b']->id, 'staff_code' => 'CASHIER', 'name' => 'Cash custodian', 'kind' => 'employee', 'active' => true]);
        $custody = app(FinanceTreasury::class)->createCustody($c['b'], ['name' => 'General physical cash', 'currency' => 'UGX', 'staff_person_id' => $person->id, 'authorised_float' => '2000', 'use_general_cash' => true], $c['a']->id);
        $count = app(FinanceTreasury::class)->count($c['b'], $custody, ['counted' => '1100', 'denominations' => [['value' => '100', 'count' => 11]], 'reason' => 'Actual cash independently counted with original signed count sheet.'], $c['a']->id);
        app(FinanceTreasury::class)->reviewCount($c['b'], $count, true, 'Independent custodian recount confirmed the actual notes and genuine surplus.', $c['r']->id);
        $flow = FinanceWorkflow::findOrFail($count->fresh()->workflow_id);
        $this->post(route('finance.reviews.request', ['type' => 'finance_workflow', 'source' => $flow->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Signed cash count and genuine surplus independently reviewed.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::where('source_type', 'finance_workflow')->where('source_id', $flow->id)->firstOrFail();
        $this->actingAs($c['r'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Verified original count, unchanged cash ledger and actual surplus.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['a'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(FinanceTreasury::class)->countIsCurrent($count->fresh()));
        $position = app(FinanceOwnerPosition::class)->report($c['b'], '2026-10-27');
        $this->assertTrue($position['money']->isEqualTo('1100'));
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
        $this->record($c, 2, '2026-10-28', 'UGX', '1', 'cash', 'owner_capital', '100', '100');
        $this->assertTrue(app(FinanceTreasury::class)->countIsCurrent($count->fresh()));
        DB::table('cash_closes')->where('id', $count->id)->update(['counted' => '1101']);
        $this->assertFalse(app(FinanceTreasury::class)->countIsCurrent($count->fresh()));
    }
}
