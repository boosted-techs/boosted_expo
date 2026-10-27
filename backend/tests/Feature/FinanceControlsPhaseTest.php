<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceAccount;
use App\Models\FinanceBankLedgerCheck;
use App\Models\FinanceBankProfile;
use App\Models\FinanceBankStatement;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceJournal;
use App\Models\FinanceOpeningBalance;
use App\Models\FinancePayrollReversal;
use App\Models\FinancePayrollRun;
use App\Models\FinancePayrollSettlement;
use App\Models\FinancePeriod;
use App\Models\FinancePostingRequest;
use App\Models\FinanceShipmentCost;
use App\Models\Product;
use App\Models\StaffPerson;
use App\Models\User;
use App\Services\FinanceLedger;
use App\Services\FinancePayrollSettlements;
use App\Services\FinanceSetup;
use App\Services\FinanceStatements;
use App\Services\FinanceTreasury;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceControlsPhaseTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code = 'EXP'): array
    {
        $business = Business::create([
            'name' => $code.' Exports', 'code' => $code, 'country_code' => 'UG',
            'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala',
            'default_qr_level' => 'carton',
        ]);
        $owner = User::factory()->create();
        $accountant = User::factory()->create();
        $hr = User::factory()->create();
        foreach ([[$owner, 'owner'], [$accountant, 'accountant'], [$hr, 'hr']] as [$user,$role]) {
            BusinessMembership::create([
                'business_id' => $business->id, 'user_id' => $user->id,
                'role' => $role, 'active' => true,
            ]);
        }
        $this->actingAs($owner)->withSession(['active_business_id' => $business->id])
            ->post(route('finance.setup'))->assertRedirect();
        $customer = Customer::create([
            'business_id' => $business->id, 'customer_code' => 'BUY-1', 'name' => 'Gulf Foods',
            'country_code' => 'AE', 'currency' => 'USD', 'active' => true,
        ]);
        $product = Product::create([
            'business_id' => $business->id, 'sku' => 'MANGO', 'name' => 'Mangoes',
            'base_unit' => 'kg', 'active' => true,
        ]);

        return compact('business', 'owner', 'accountant', 'hr', 'customer', 'product');
    }

    private function login(array $ctx, string $role = 'accountant'): self
    {
        return $this->actingAs($ctx[$role])->withSession([
            'active_business_id' => $ctx['business']->id,
        ]);
    }

    private function account(array $ctx, string $key): FinanceAccount
    {
        return app(FinanceSetup::class)->account($ctx['business']->id, $key);
    }

    private function reviewPost(array $ctx, string $type, int $id, ?int $fx = null,
        ?string $date = null): FinanceJournal
    {
        $data = [
            'posting_on' => $date ?? now()->toDateString(),
            'basis_notes' => 'Supporting records verified and independently reviewed for this company journal.',
        ];
        if ($fx) {
            $data['exchange_rate_id'] = $fx;
        }
        $this->login($ctx)->post(route('finance.reviews.request', [
            'type' => $type, 'source' => $id,
        ]), $data)->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('business_id', $ctx['business']->id)
            ->where('source_type', $type)->where('source_id', $id)->firstOrFail();
        $this->login($ctx, 'owner')->post(route('finance.reviews.approve', $review->id), [
            'notes' => 'I independently checked and approve the journal source evidence.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->login($ctx)->post(route('finance.reviews.post', $review->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        return FinanceJournal::query()->where('business_id', $ctx['business']->id)
            ->where('source_type', $type)->where('source_id', $id)->firstOrFail();
    }

    public function test_reviewed_opening_balances_record_equity_and_closed_period_blocks_backdating(): void
    {
        $ctx = $this->company();
        $this->login($ctx)->get(route('finance.controls'))->assertOk()
            ->assertSee('Company opening balances');
        $effective = now()->startOfMonth()->toDateString();
        $this->post(route('finance.controls.opening.store'), [
            'effective_on' => $effective, 'lines' => [
                ['account_id' => $this->account($ctx, 'bank')->id, 'debit' => '500000', 'credit' => '0'],
                ['account_id' => $this->account($ctx, 'opening_equity')->id, 'debit' => '0', 'credit' => '500000'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $opening = FinanceOpeningBalance::firstOrFail();
        $this->assertSame('not_posted', $opening->posting_status);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->reviewPost($ctx, 'opening_balance', $opening->id, null, $effective);
        $result = app(FinanceStatements::class)->report($ctx['business'],
            $effective, now()->toDateString());
        $this->assertTrue(BigDecimal::of($result['assets'])->isEqualTo('500000'));
        $this->assertTrue(BigDecimal::of($result['equity'])->isEqualTo('500000'));
        $this->assertTrue(BigDecimal::of($result['balance_variance'])->isZero());
        $this->assertTrue($result['has_opening_balances']);
        $this->post(route('finance.controls.opening.store'), [
            'effective_on' => $effective, 'lines' => [
                ['account_id' => $this->account($ctx, 'cash')->id, 'debit' => '1000'],
                ['account_id' => $this->account($ctx, 'opening_equity')->id, 'credit' => '1000'],
            ],
        ])->assertSessionHasErrors('opening');
        $this->verifyHistoricalBank($ctx, $effective, now()->toDateString(), '500000');
        $this->login($ctx)->post(route('finance.controls.periods.store'), [
            'period_from' => $effective, 'period_to' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $period = FinancePeriod::firstOrFail();
        $this->post(route('finance.controls.periods.submit', $period->id), [
            'reason' => 'Opening and posted journals reviewed with no unreconciled statements.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', $period->fresh()->status);
        $this->post(route('finance.controls.periods.review', [
            'period' => $period->id, 'action' => 'approve',
        ]), ['reason' => 'Self-approval must be rejected'])->assertForbidden();
        $this->login($ctx, 'owner')->post(route('finance.controls.periods.review', [
            'period' => $period->id, 'action' => 'approve',
        ]), ['reason' => 'Independent journal review and balanced totals verified.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $period->fresh()->status);
        $this->assertDatabaseCount('finance_period_events', 2);
        $this->login($ctx)->get(route('finance.controls'))->assertOk()->assertSee('Closed');
        $this->expectException(ValidationException::class);
        app(FinanceLedger::class)->record($ctx['business'], 'manual_fixture', 99,
            'UGX', '1', now()->toDateString(), 'Backdated posting should fail',
            $ctx['accountant']->id, $ctx['owner']->id, [
                app(FinanceLedger::class)->line('cash', '100', '0', '100', '0'),
                app(FinanceLedger::class)->line('opening_equity', '0', '100', '0', '100'),
            ]);
    }

    public function test_unbalanced_opening_is_rejected_without_journal(): void
    {
        $ctx = $this->company();
        $this->login($ctx)->post(route('finance.controls.opening.store'), [
            'effective_on' => now()->toDateString(), 'lines' => [
                ['account_id' => $this->account($ctx, 'cash')->id, 'debit' => '100', 'credit' => '0'],
                ['account_id' => $this->account($ctx, 'opening_equity')->id, 'debit' => '0', 'credit' => '99'],
            ],
        ])->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('finance_opening_balances', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_named_bank_accounts_are_segregated_and_currency_strict(): void
    {
        $ctx = $this->company();
        $this->login($ctx, 'owner')->post(route('finance.controls.banks.store'), [
            'label' => 'Export settlement USD', 'institution' => 'Bank Uganda', 'currency' => 'USD',
            'masked_reference' => '****1234',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $bank = FinanceBankProfile::firstOrFail();
        $this->assertSame('USD', $bank->currency);
        $this->assertNotSame($this->account($ctx, 'bank')->id, $bank->account_id);
        $this->assertSame('asset', $bank->account->type);
        $this->login($ctx)->get(route('finance.bank.index'))->assertOk()
            ->assertSee('Export settlement USD');
        $this->post(route('finance.controls.banks.store'), [
            'label' => 'Duplicate attempt', 'institution' => 'Bank Uganda', 'currency' => 'USD',
        ])->assertForbidden();
        $shipment = ExportShipment::create([
            'business_id' => $ctx['business']->id, 'reference' => 'SHIP-'.Str::ulid(),
            'customer_id' => $ctx['customer']->id, 'status' => 'dispatched',
            'transport_mode' => 'air', 'origin_country' => 'UG',
            'destination_country' => 'AE', 'created_by' => $ctx['owner']->id,
        ]);
        $this->post(route('finance.costs.store'), [
            'shipment_id' => $shipment->id, 'category' => 'handling',
            'description' => 'Foreign airline handling', 'currency' => 'UGX',
            'amount' => '2000', 'cash_account_key' => 'bank', 'bank_profile_id' => $bank->id,
            'paid_on' => now()->toDateString(), 'external_reference' => 'FEE-1',
        ])->assertSessionHasErrors('bank_profile_id');
        $this->post(route('finance.costs.store'), [
            'shipment_id' => $shipment->id, 'category' => 'handling',
            'description' => 'Foreign airline handling', 'currency' => 'USD',
            'amount' => '20', 'cash_account_key' => 'bank', 'bank_profile_id' => $bank->id,
            'paid_on' => now()->toDateString(), 'external_reference' => 'FEE-2',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $cost = FinanceShipmentCost::firstOrFail();
        $this->assertSame($bank->account_id, $cost->cash_account_id);
        $fx = $this->login($ctx)->post(route('finance.rates.propose'), [
            'currency' => 'USD', 'rate_to_base' => '3700',
            'effective_on' => now()->toDateString(),
            'source_note' => 'Independent commercial bank rate',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $fx = FinanceExchangeRate::firstOrFail();
        $this->login($ctx, 'owner')->post(route('finance.rates.approve', $fx->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->reviewPost($ctx, 'shipment_cost', $cost->id, $fx->id);
        $csv = "date,direction,amount,reference,description\n".
            now()->toDateString().",debit,20.00,FEE-2,Airline handling cost\n";
        $this->login($ctx)->post(route('finance.bank.import'), [
            'currency' => 'USD', 'bank_profile_id' => $bank->id,
            'period_from' => now()->toDateString(), 'period_to' => now()->toDateString(),
            'opening_balance' => '100', 'closing_balance' => '80',
            'file' => UploadedFile::fake()->createWithContent('bank.csv', $csv),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $statement = FinanceBankStatement::firstOrFail();
        $this->assertSame($bank->account_id, $statement->account_id);
        $line = $statement->lines()->firstOrFail();
        $this->post(route('finance.bank.match', [
            'statement' => $statement->id, 'line' => $line->id,
        ]), ['candidate' => 'shipment_cost:'.$cost->id,
            'note' => 'Posted expense matches imported bank debit entry'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $match = $line->match()->firstOrFail();
        $this->login($ctx, 'owner')->post(route('finance.bank.confirm', $match->id), [
            'note' => 'Independently matched amount currency and bank ledger',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->login($ctx, 'owner')->post(route('finance.bank.complete', $statement->id))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_hr_payroll_is_accrued_through_separate_finance_approval(): void
    {
        $ctx = $this->company();
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $this->login($ctx, 'hr')->get(route('finance.payroll.index'))->assertOk()
            ->assertSee('Payroll accruals');
        $this->post(route('finance.payroll.prepare'), [
            'period_from' => $from, 'period_to' => $to,
            'notes' => 'Manual staff payroll calculation approved for review.',
            'entries' => [
                $ctx['hr']->id => ['gross' => '250000', 'deductions' => '25000'],
                $ctx['accountant']->id => ['gross' => '200000', 'deductions' => '0'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $run = FinancePayrollRun::firstOrFail();
        $this->assertTrue(BigDecimal::of((string) $run->gross_total)->isEqualTo('450000.00'));
        $this->assertDatabaseCount('finance_payroll_lines', 2);
        $this->login($ctx)->post(route('finance.controls.periods.store'), [
            'period_from' => $from, 'period_to' => $to,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $period = FinancePeriod::firstOrFail();
        $this->post(route('finance.controls.periods.submit', $period->id), [
            'reason' => 'Cannot close the month while payroll has not posted.',
        ])->assertSessionHasErrors('period');
        $this->reviewPost($ctx, 'payroll_run', $run->id);
        $this->assertSame('posted', $run->fresh()->posting_status);
        $result = app(FinanceStatements::class)->report($ctx['business'], $from, $to);
        $this->assertTrue(BigDecimal::of($result['expense'])->isEqualTo('450000'));
        $this->assertTrue(BigDecimal::of($result['liabilities'])->isEqualTo('450000'));
        $this->assertTrue(BigDecimal::of($result['balance_variance'])->isZero());
        $this->login($ctx, 'hr')->get(route('finance.reviews'))->assertForbidden();
    }

    public function test_foreign_bank_opening_is_rejected_and_old_bank_history_remains_separate(): void
    {
        $ctx = $this->company();
        $this->login($ctx, 'owner')->post(route('finance.controls.banks.store'), [
            'label' => 'Foreign USD account', 'institution' => 'Test Bank', 'currency' => 'USD',
            'masked_reference' => '****4321',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $foreign = FinanceBankProfile::firstOrFail();
        $this->login($ctx)->post(route('finance.controls.opening.store'), [
            'effective_on' => now()->startOfMonth()->toDateString(),
            'lines' => [
                ['account_id' => $foreign->account_id, 'debit' => '1000'],
                ['account_id' => $this->account($ctx, 'opening_equity')->id, 'credit' => '1000'],
            ],
        ])->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('finance_opening_balances', 0);

        $csv = "date,direction,amount,reference,description\n".
            now()->toDateString().",credit,75.00,OLD-BANK-1,Historical bank deposit\n";
        $this->post(route('finance.bank.import'), [
            'currency' => 'UGX', 'bank_profile_id' => 'legacy',
            'period_from' => now()->toDateString(), 'period_to' => now()->toDateString(),
            'opening_balance' => '0', 'closing_balance' => '75',
            'file' => UploadedFile::fake()->createWithContent('legacy.csv', $csv),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $statement = FinanceBankStatement::firstOrFail();
        $this->assertSame($this->account($ctx, 'bank')->id, $statement->account_id);
        $this->assertNotSame($foreign->account_id, $statement->account_id);
        $this->post(route('finance.bank.import'), [
            'currency' => 'UGX',
            'period_from' => now()->toDateString(), 'period_to' => now()->toDateString(),
            'opening_balance' => '0', 'closing_balance' => '75',
            'file' => UploadedFile::fake()->createWithContent('no-account.csv', $csv),
        ])->assertSessionHasErrors('bank_profile_id');
        $other = $this->company('OTHER');
        $this->login($other)->post(route('finance.bank.import'), [
            'currency' => 'USD', 'bank_profile_id' => $foreign->id,
            'period_from' => now()->toDateString(), 'period_to' => now()->toDateString(),
            'opening_balance' => '0', 'closing_balance' => '75',
            'file' => UploadedFile::fake()->createWithContent('other.csv', $csv),
        ])->assertNotFound();
    }

    public function test_independent_close_rejection_reopens_period_and_later_closure_prevents_past_period_creation(): void
    {
        $ctx = $this->company();
        $first = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();
        $this->login($ctx)->post(route('finance.controls.periods.store'), [
            'period_from' => $first, 'period_to' => $today,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $period = FinancePeriod::firstOrFail();
        $this->post(route('finance.controls.periods.submit', $period->id), [
            'reason' => 'Trial balance is balanced and no source documents remain outstanding.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->login($ctx, 'owner')->post(route('finance.controls.periods.review', [
            'period' => $period->id, 'action' => 'reject',
        ]), ['reason' => 'Further checking of the original records required.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('open', $period->fresh()->status);
        $this->assertDatabaseCount('finance_period_events', 2);
        $ledger = app(FinanceLedger::class);
        $ledger->record($ctx['business'], 'test_adjustment', 91, 'UGX', '1', $today,
            'Permitted after close rejection', $ctx['accountant']->id, $ctx['owner']->id, [
                $ledger->line('cash', '1000', '0', '1000', '0'),
                $ledger->line('opening_equity', '0', '1000', '0', '1000'),
            ]);
        $person = StaffPerson::create(['business_id' => $ctx['business']->id, 'staff_code' => 'CLOSE-CASHIER', 'name' => 'Independent cash custodian', 'kind' => 'employee', 'active' => true]);
        $cash = app(FinanceTreasury::class)->createCustody($ctx['business'], ['name' => 'General physical cash', 'currency' => 'UGX', 'staff_person_id' => $person->id, 'authorised_float' => '2000', 'use_general_cash' => true], $ctx['owner']->id);
        $count = app(FinanceTreasury::class)->count($ctx['business'], $cash, ['counted' => '1000', 'reason' => 'Actual notes counted and supported by the signed closing cash sheet.'], $ctx['accountant']->id);
        app(FinanceTreasury::class)->reviewCount($ctx['business'], $count, true, 'Independent owner checked the physical closing count and original ledger.', $ctx['owner']->id);
        $this->login($ctx)->post(route('finance.controls.periods.submit', $period->id), [
            'reason' => 'Reconciled the account activity after the prior rejection.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->login($ctx, 'owner')->post(route('finance.controls.periods.review', [
            'period' => $period->id, 'action' => 'approve',
        ]), ['reason' => 'Independently rechecked the complete balances.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $period->fresh()->status);
        $this->login($ctx)->post(route('finance.controls.periods.store'), [
            'period_from' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_to' => now()->subMonth()->endOfMonth()->toDateString(),
        ])->assertSessionHasErrors('period_from');
    }

    private function verifyHistoricalBank(array $ctx, string $from, string $to, string $balance): void
    {
        $this->login($ctx, 'owner')->post(route('finance.controls.banks.store'), ['label' => 'Verified actual existing bank', 'institution' => 'Exporter bank', 'currency' => 'UGX', 'use_legacy_bank' => true])->assertRedirect()->assertSessionHasNoErrors();
        $bank = FinanceBankProfile::firstOrFail();
        $this->login($ctx)->post(route('finance.bank.import'), ['currency' => 'UGX', 'bank_profile_id' => $bank->id, 'period_from' => $from, 'period_to' => $to, 'opening_balance' => $balance, 'closing_balance' => $balance, 'file' => UploadedFile::fake()->createWithContent('no-transaction-statement.csv', "date,direction,amount,reference,description\n")])->assertRedirect()->assertSessionHasNoErrors();
        $statement = FinanceBankStatement::firstOrFail();
        $this->login($ctx, 'owner')->post(route('finance.bank.complete', $statement->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->login($ctx)->post(route('finance.bank.proof.submit', $statement->id), ['notes' => 'Verified the original statement, unchanged opening bank amount and absence of actual transactions.'])->assertRedirect()->assertSessionHasNoErrors();
        $check = FinanceBankLedgerCheck::firstOrFail();
        $this->login($ctx, 'owner')->post(route('finance.bank.proof.review', [$check->id, 'approve']), ['notes' => 'Independently confirmed the uploaded statement against its real opening and closing ledger balances.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->login($ctx);
    }

    private function postedPayroll(array $ctx): FinancePayrollRun
    {
        $this->login($ctx, 'hr')->post(route('finance.payroll.prepare'), [
            'period_from' => now()->startOfMonth()->toDateString(),
            'period_to' => now()->toDateString(),
            'notes' => 'Verified manual salary amounts, with approval documents for deductions.',
            'entries' => [
                $ctx['hr']->id => ['gross' => '250000', 'deductions' => '25000'],
                $ctx['accountant']->id => ['gross' => '200000', 'deductions' => '0'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $run = FinancePayrollRun::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
        $this->reviewPost($ctx, 'payroll_run', $run->id);

        return $run->fresh();
    }

    private function settle(array $ctx, FinancePayrollRun $run,
        string $kind, string $amount, ?int $lineId = null, string $method = 'bank'): FinancePayrollSettlement
    {
        $this->login($ctx)->post(route('finance.payroll.settlements.store', $run->id), [
            'kind' => $kind, 'payroll_line_id' => $lineId,
            'recipient_name' => $kind === 'withholding' ? 'Actual withholding receiving agency' : null,
            'amount' => $amount, 'cash_account_key' => $method,
            'paid_on' => now()->toDateString(), 'external_reference' => 'PAYREF-'.Str::ulid(),
            'evidence_note' => 'Bank or cash voucher and employee payee verified independently.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        return FinancePayrollSettlement::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
    }

    public function test_salary_and_withholding_settlement_are_approved_and_bank_reconcilable(): void
    {
        $ctx = $this->company();
        $run = $this->postedPayroll($ctx);
        $line = $run->lines()->where('user_id', $ctx['hr']->id)->firstOrFail();
        $other = $run->lines()->where('user_id', $ctx['accountant']->id)->firstOrFail();
        $this->login($ctx)->get(route('finance.payroll.show', $run->id))
            ->assertOk()->assertSee('Employee wage settlement register');

        $first = $this->settle($ctx, $run, 'salary', '100000', $line->id, 'bank');
        $this->assertSame('pending', $first->status);
        $this->get(route('finance.reviews'))->assertOk()
            ->assertSee('Recorded salary payments');
        $this->assertTrue(app(FinancePayrollSettlements::class)
            ->remaining($run, 'salary', $line->id)->isEqualTo('125000'));
        // Merely recording external payment evidence does not create a journal.
        $this->assertDatabaseCount('finance_journals', 1);
        $this->reviewPost($ctx, 'payroll_settlement', $first->id);
        $this->assertSame('posted', $first->fresh()->status);

        $second = $this->settle($ctx, $run, 'salary', '125000', $line->id, 'cash');
        $this->reviewPost($ctx, 'payroll_settlement', $second->id);
        $third = $this->settle($ctx, $run, 'salary', '200000', $other->id, 'bank');
        $this->reviewPost($ctx, 'payroll_settlement', $third->id);
        $remit = $this->settle($ctx, $run, 'withholding', '25000', null, 'cash');
        $this->reviewPost($ctx, 'payroll_settlement', $remit->id);
        $service = app(FinancePayrollSettlements::class);
        $this->assertTrue($service->remaining($run, 'salary', $line->id)->isZero());
        $this->assertTrue($service->remaining($run, 'salary', $other->id)->isZero());
        $this->assertTrue($service->remaining($run, 'withholding')->isZero());
        $report = app(FinanceStatements::class)->report($ctx['business'],
            now()->startOfMonth()->toDateString(), now()->toDateString());
        $this->assertTrue(BigDecimal::of($report['expense'])->isEqualTo('450000'));
        $this->assertTrue(BigDecimal::of($report['liabilities'])->isZero());
        $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo('-450000'));
        $this->assertTrue(BigDecimal::of($report['balance_variance'])->isZero());

        $csv = "date,direction,amount,reference,description\n".
            now()->toDateString().',debit,100000.00,'.$first->external_reference.",Verified staff bank transfer\n";
        $this->login($ctx)->post(route('finance.bank.import'), [
            'currency' => 'UGX', 'period_from' => now()->toDateString(),
            'period_to' => now()->toDateString(),
            'opening_balance' => '500000', 'closing_balance' => '400000',
            'file' => UploadedFile::fake()->createWithContent('wages.csv', $csv),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $statement = FinanceBankStatement::firstOrFail();
        $statementLine = $statement->lines()->firstOrFail();
        $this->get(route('finance.bank.show', $statement->id))->assertOk()
            ->assertSee('payroll_settlement:'.$first->id);
        $this->post(route('finance.bank.match', [
            'statement' => $statement->id, 'line' => $statementLine->id,
        ]), [
            'candidate' => 'payroll_settlement:'.$first->id,
            'note' => 'Same bank account and amount with the staff transfer voucher reference.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $match = $statementLine->match()->firstOrFail();
        $this->login($ctx, 'owner')->post(route('finance.bank.confirm', $match->id), [
            'note' => 'Independently verified payroll bank amount and transaction reference.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.bank.complete', $statement->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reconciled', $statement->fresh()->status);
        $this->assertDatabaseCount('finance_journals', 5);
    }

    public function test_payroll_payment_excess_duplicates_permissions_and_reversal_conflicts_are_blocked(): void
    {
        $ctx = $this->company();
        $run = $this->postedPayroll($ctx);
        $line = $run->lines()->where('user_id', $ctx['hr']->id)->firstOrFail();
        $key = (string) Str::uuid();
        $request = [
            'kind' => 'salary', 'payroll_line_id' => $line->id, 'amount' => '225000',
            'cash_account_key' => 'cash', 'paid_on' => now()->toDateString(),
            'external_reference' => 'PAID-1', 'evidence_note' => 'Manual salary receipt and staff bank/cash voucher independently available.',
            'idempotency_key' => $key,
        ];
        $this->login($ctx, 'hr')->post(route('finance.payroll.settlements.store', $run->id), $request)
            ->assertForbidden();
        $this->login($ctx)->post(route('finance.payroll.settlements.store', $run->id), $request)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.payroll.settlements.store', $run->id), $request)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('finance_payroll_settlements', 1);
        $request['idempotency_key'] = (string) Str::uuid();
        $request['amount'] = '1';
        $this->post(route('finance.payroll.settlements.store', $run->id), $request)
            ->assertSessionHasErrors('amount');
        $this->post(route('finance.payroll.correction.store', $run->id), [
            'reason' => 'Cannot reverse the payroll while an employee payment remains pending.',
        ])->assertSessionHasErrors('correction');
        $this->assertDatabaseCount('finance_payroll_reversals', 0);
        $this->reviewPost($ctx, 'payroll_settlement', FinancePayrollSettlement::firstOrFail()->id);
        $this->post(route('finance.payroll.correction.store', $run->id), [
            'reason' => 'Cannot reverse the payroll after a salary payment was posted.',
        ])->assertSessionHasErrors('correction');

        $other = $this->company('OTHER');
        $this->login($other)->post(route('finance.payroll.settlements.store', $run->id), [
            ...$request, 'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->get(route('finance.payroll.show', $run->id))->assertNotFound();
    }

    public function test_reversed_payroll_preserves_old_journal_and_permits_reviewed_revision(): void
    {
        $ctx = $this->company();
        $run = $this->postedPayroll($ctx);
        $original = FinanceJournal::query()->where('business_id', $ctx['business']->id)
            ->where('source_type', 'payroll_run')->where('source_id', $run->id)->firstOrFail();
        $this->login($ctx, 'hr')->post(route('finance.payroll.correction.store', $run->id), [
            'reason' => 'HR attempted to create finance correction without accountant approval.',
        ])->assertForbidden();
        $this->login($ctx)->post(route('finance.payroll.correction.store', $run->id), [
            'reason' => 'The original staff pay calculation included an incorrect manual deduction.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $correction = FinancePayrollReversal::firstOrFail();
        $this->assertSame('not_posted', $correction->posting_status);
        $this->assertSame('posted', $run->fresh()->posting_status);
        $this->post(route('finance.payroll.correction.store', $run->id), [
            'reason' => 'A second full reversal for this payroll must not be created.',
        ])->assertSessionHasErrors('correction');

        $line = $run->lines()->firstOrFail();
        $this->post(route('finance.payroll.settlements.store', $run->id), [
            'kind' => 'salary', 'payroll_line_id' => $line->id, 'amount' => '1000',
            'cash_account_key' => 'cash', 'paid_on' => now()->toDateString(),
            'external_reference' => 'UNPAID', 'evidence_note' => 'Not an actual payment after reversal review requested.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('settlement');
        $reversing = $this->reviewPost($ctx, 'payroll_reversal', $correction->id);
        $this->assertSame('posted', $correction->fresh()->posting_status);
        $this->assertSame('reversed', $run->fresh()->posting_status);
        $this->assertTrue($original->fresh()->lines()->exists());
        $this->assertNotSame($original->id, $reversing->id);
        $report = app(FinanceStatements::class)->report($ctx['business'],
            now()->startOfMonth()->toDateString(), now()->toDateString());
        $this->assertTrue(BigDecimal::of($report['expense'])->isZero());
        $this->assertTrue(BigDecimal::of($report['liabilities'])->isZero());
        $this->assertTrue(BigDecimal::of($report['balance_variance'])->isZero());

        $this->login($ctx, 'hr')->post(route('finance.payroll.prepare'), [
            'period_from' => now()->startOfMonth()->toDateString(),
            'period_to' => now()->toDateString(),
            'notes' => 'Revised pay amounts after independent reversal of original accrual.',
            'entries' => [
                $ctx['hr']->id => ['gross' => '250000', 'deductions' => '10000'],
                $ctx['accountant']->id => ['gross' => '200000', 'deductions' => '0'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $revision = FinancePayrollRun::query()->where('business_id', $ctx['business']->id)
            ->where('id', '!=', $run->id)->firstOrFail();
        $this->assertSame(2, $revision->revision);
        $this->assertSame('not_posted', $revision->posting_status);
        $this->reviewPost($ctx, 'payroll_run', $revision->id);
        $this->assertSame('posted', $revision->fresh()->posting_status);
        $this->assertSame('reversed', $run->fresh()->posting_status);
        $this->assertDatabaseCount('finance_journals', 3);
    }

    public function test_correction_withdrawal_requires_review_rejection_and_can_be_resumed(): void
    {
        $ctx = $this->company();
        $run = $this->postedPayroll($ctx);
        $this->login($ctx)->post(route('finance.payroll.correction.store', $run->id), [
            'reason' => 'Initial review identified an incorrect salary deduction needing reversal.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $correction = FinancePayrollReversal::firstOrFail();
        $this->post(route('finance.reviews.request', [
            'type' => 'payroll_reversal', 'source' => $correction->id,
        ]), [
            'posting_on' => now()->toDateString(),
            'basis_notes' => 'Signed manual payroll amendment with supporting salary figures.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('business_id', $ctx['business']->id)
            ->where('source_type', 'payroll_reversal')->firstOrFail();
        $this->post(route('finance.payroll.correction.withdraw', $run->id), [
            'reason' => 'Cannot withdraw while review is pending independently.',
        ])->assertStatus(422);
        $this->login($ctx, 'owner')->post(route('finance.reviews.reject', $review->id), [
            'notes' => 'Original salary voucher evidence requires clarification.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->login($ctx)->post(route('finance.payroll.correction.withdraw', $run->id), [
            'reason' => 'Payroll document correction withdrawn pending stronger supporting evidence.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $correction->fresh()->posting_status);
        $this->assertDatabaseCount('finance_payroll_correction_events', 2);
        $this->get(route('finance.payroll.show', $run->id))->assertOk()
            ->assertSee('Resume the correction approval workflow')
            ->assertSee('Record salary settlement for finance review');
        $this->post(route('finance.payroll.correction.store', $run->id), [
            'reason' => 'Updated evidence confirms the original payroll accrual needs full correction.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('not_posted', $correction->fresh()->posting_status);
        $this->reviewPost($ctx, 'payroll_reversal', $correction->id);
        $this->assertSame('reversed', $run->fresh()->posting_status);
        $this->assertDatabaseCount('finance_payroll_correction_events', 4);
    }

    public function test_withdrawn_correction_releases_settlements_but_pending_payments_block_resumption(): void
    {
        $ctx = $this->company();
        $run = $this->postedPayroll($ctx);
        $this->login($ctx)->post(route('finance.payroll.correction.store', $run->id), [
            'reason' => 'Review of the original payroll totals revealed a possible error.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.payroll.correction.withdraw', $run->id), [
            'reason' => 'Payroll source document rechecked and correction is not required.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $line = $run->lines()->firstOrFail();
        $entry = $this->settle($ctx, $run, 'salary', '1000', $line->id, 'cash');
        $this->assertSame('pending', $entry->status);
        $this->post(route('finance.payroll.correction.store', $run->id), [
            'reason' => 'This correction cannot proceed while a staff payment is reserved.',
        ])->assertSessionHasErrors('correction');
        $this->assertDatabaseCount('finance_payroll_reversals', 1);
        $this->assertSame('withdrawn', FinancePayrollReversal::firstOrFail()->posting_status);
        $this->login($ctx)->get(route('finance.payroll.show', $run->id))->assertOk()
            ->assertSee('Record salary settlement for finance review');
    }

    public function test_idempotency_request_cannot_be_reused_for_different_salary_or_bank_details(): void
    {
        $ctx = $this->company();
        $run = $this->postedPayroll($ctx);
        $line = $run->lines()->firstOrFail();
        $key = (string) Str::uuid();
        $payload = [
            'kind' => 'salary', 'payroll_line_id' => $line->id, 'amount' => '1000',
            'cash_account_key' => 'cash', 'paid_on' => now()->toDateString(),
            'external_reference' => 'VOUCHER-100', 'evidence_note' => 'The finance officer received cash payment evidence with a dated voucher.',
            'idempotency_key' => $key,
        ];
        $this->login($ctx)->post(route('finance.payroll.settlements.store', $run->id), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.payroll.settlements.store', $run->id), [
            ...$payload, 'amount' => '2000',
        ])->assertSessionHasErrors('idempotency_key');
        $this->assertDatabaseCount('finance_payroll_settlements',1);
    }
}
