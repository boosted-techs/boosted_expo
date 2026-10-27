<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceAccount;
use App\Models\FinanceBankLedgerCheck;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankProfile;
use App\Models\FinanceBankStatement;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceForeignBankOpening;
use App\Models\FinanceFxRevaluation;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinanceMonetaryRevaluation;
use App\Models\FinanceOpeningBalance;
use App\Models\FinancePartialBankReturn;
use App\Models\FinancePayment;
use App\Models\FinancePeriod;
use App\Models\FinancePeriodReopenRequest;
use App\Models\FinancePostingRequest;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceTransactionReversal;
use App\Models\InvoiceAccountingHandoff;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesRequest;
use App\Models\User;
use App\Services\FinanceBankAuthenticity;
use App\Services\FinanceBankLedgerProofs;
use App\Services\FinanceDocuments;
use App\Services\FinanceLedger;
use App\Services\FinanceMonetaryRevaluations;
use App\Services\FinanceSetup;
use App\Services\FinanceStatements;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceReversalsForeignBankProofTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $code = 'EXPO'): array
    {
        $business = Business::create([
            'name' => $code.' Exporters', 'code' => $code, 'country_code' => 'UG',
            'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala',
            'default_qr_level' => 'carton',
            'billing_mode' => 'exempt', 'billing_exempt_until' => today()->addYears(5),
        ]);
        $owner = User::factory()->create();
        $accountant = User::factory()->create();
        $viewer = User::factory()->create();
        foreach ([[$owner, 'owner'], [$accountant, 'accountant'], [$viewer, 'viewer']] as [$user,$role]) {
            BusinessMembership::create([
                'business_id' => $business->id, 'user_id' => $user->id,
                'role' => $role, 'active' => true,
            ]);
        }
        $this->actingAs($owner)->withSession(['active_business_id' => $business->id])
            ->post(route('finance.setup'))->assertRedirect()->assertSessionHasNoErrors();
        $customer = Customer::create([
            'business_id' => $business->id, 'customer_code' => 'BUY-01', 'name' => 'Export Buyer',
            'country_code' => 'AE', 'currency' => 'USD', 'active' => true,
        ]);
        $shipment = ExportShipment::create([
            'business_id' => $business->id, 'reference' => 'SHIP-'.Str::ulid(),
            'customer_id' => $customer->id, 'status' => 'dispatched', 'transport_mode' => 'air',
            'origin_country' => 'UG', 'destination_country' => 'AE',
            'created_by' => $owner->id,
        ]);

        return compact('business', 'owner', 'accountant', 'viewer', 'customer', 'shipment');
    }

    private function as(array $ctx, string $role = 'accountant'): self
    {
        return $this->actingAs($ctx[$role])->withSession([
            'active_business_id' => $ctx['business']->id,
        ]);
    }

    private function account(array $ctx, string $key): FinanceAccount
    {
        return app(FinanceSetup::class)->account($ctx['business']->id, $key);
    }

    private function bank(array $ctx, string $currency = 'USD'): FinanceBankProfile
    {
        $this->as($ctx, 'owner')->post(route('finance.controls.banks.store'), [
            'label' => 'Settlements '.$currency, 'institution' => 'Demo Bank Ltd',
            'currency' => $currency, 'masked_reference' => '****9876',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return FinanceBankProfile::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
    }

    private function fx(array $ctx, string $currency = 'USD', string $rate = '3700'): FinanceExchangeRate
    {
        $this->as($ctx)->post(route('finance.rates.propose'), [
            'currency' => $currency, 'rate_to_base' => $rate,
            'effective_on' => now()->subDays(2)->toDateString(),
            'source_note' => 'Bank-supported historical rate evidence',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $record = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
        $this->as($ctx, 'owner')->post(route('finance.rates.approve', $record->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        return $record->fresh();
    }

    private function reviewPost(array $ctx, string $type, int $id, ?int $rateId = null,
        ?string $postingDate = null): FinanceJournal
    {
        $this->as($ctx)->post(route('finance.reviews.request', [
            'type' => $type, 'source' => $id,
        ]), array_filter([
            'posting_on' => $postingDate ?? now()->toDateString(),
            'exchange_rate_id' => $rateId,
            'basis_notes' => 'Verifiable journal source vouchers, rates and approvals checked against company records.',
        ], fn ($v) => $v !== null))->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('business_id', $ctx['business']->id)
            ->where('source_type', $type)->where('source_id', $id)->firstOrFail();
        $this->as($ctx, 'owner')->post(route('finance.reviews.approve', $review->id), [
            'notes' => 'Independently reviewed original commercial source and rate documents.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->as($ctx)->post(route('finance.reviews.post', $review->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        return FinanceJournal::query()->where('business_id', $ctx['business']->id)
            ->where('source_type', $type)->where('source_id', $id)->firstOrFail();
    }

    private function foreignOpening(array $ctx, FinanceBankProfile $bank,
        FinanceExchangeRate $rate, string $amount = '100'): FinanceForeignBankOpening
    {
        $date = now()->subDay()->toDateString();
        $this->as($ctx)->post(route('finance.controls.foreign-opening.store'), [
            'bank_profile_id' => $bank->id, 'currency' => $bank->currency,
            'foreign_amount' => $amount, 'exchange_rate_id' => $rate->id,
            'effective_on' => $date,
            'bank_evidence' => 'Verified original foreign bank statement opening and independently approved rate.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $opening = FinanceForeignBankOpening::query()->where('business_id', $ctx['business']->id)
            ->firstOrFail();
        $this->reviewPost($ctx, 'foreign_bank_opening', $opening->id, $rate->id, $date);

        return $opening->fresh();
    }

    private function localOpening(array $ctx, FinanceBankProfile $bank, string $amount = '1000'): void
    {
        $date = now()->subDay()->toDateString();
        $this->as($ctx)->post(route('finance.controls.opening.store'), [
            'effective_on' => $date, 'lines' => [
                ['account_id' => $bank->account_id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $this->account($ctx, 'opening_equity')->id,
                    'debit' => '0', 'credit' => $amount],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $opening = FinanceOpeningBalance::query()
            ->where('business_id', $ctx['business']->id)->firstOrFail();
        $this->reviewPost($ctx, 'opening_balance', $opening->id, null, $date);
    }

    private function postedCost(array $ctx, FinanceBankProfile $bank,
        string $amount = '20', ?FinanceExchangeRate $rate = null): FinanceShipmentCost
    {
        $cost = FinanceShipmentCost::create([
            'business_id' => $ctx['business']->id,
            'shipment_id' => $ctx['shipment']->id, 'reference' => 'COST-'.Str::ulid(),
            'category' => 'handling', 'description' => 'Airport handling transfer',
            'currency' => $bank->currency, 'amount' => $amount,
            'cash_account_id' => $bank->account_id, 'paid_on' => now()->toDateString(),
            'external_reference' => 'BANK-COST-'.Str::ulid(),
            'status' => 'pending', 'created_by' => $ctx['accountant']->id,
        ]);
        $this->reviewPost($ctx, 'shipment_cost', $cost->id, $rate?->id);

        return $cost->fresh();
    }

    private function reconciledStatement(array $ctx, FinanceBankProfile $bank,
        string $opening, string $closing, array $matches): FinanceBankStatement
    {
        $today = now()->toDateString();
        $csv = "date,direction,amount,reference,description\n";
        foreach ($matches as $n => $m) {
            $csv .= $today.','.$m['direction'].','.$m['amount'].',BANK-'.($n + 1).
                ',Independent transaction reference '.($n + 1)."\n";
        }
        $this->as($ctx)->post(route('finance.bank.import'), [
            'currency' => $bank->currency, 'bank_profile_id' => $bank->id,
            'period_from' => $today, 'period_to' => $today,
            'opening_balance' => $opening, 'closing_balance' => $closing,
            'file' => UploadedFile::fake()->createWithContent('ledger-bank.csv', $csv),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $statement = FinanceBankStatement::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
        $lines = $statement->lines()->orderBy('line_number')->get();
        foreach ($matches as $n => $m) {
            $line = $lines[$n];
            $this->as($ctx)->post(route('finance.bank.match', [
                'statement' => $statement->id, 'line' => $line->id,
            ]), [
                'candidate' => $m['type'].':'.$m['id'],
                'note' => 'Manually matched the bank reference, amount and company ledger account.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $match = FinanceBankMatch::query()->where('business_id', $ctx['business']->id)
                ->where('statement_line_id', $line->id)->firstOrFail();
            $this->as($ctx, 'owner')->post(route('finance.bank.confirm', $match->id), [
                'note' => 'Independently checked transaction, bank reference, currency and amount.',
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->as($ctx, 'owner')->post(route('finance.bank.complete', $statement->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reconciled', $statement->fresh()->status);

        return $statement->fresh();
    }

    private function proof(array $ctx, FinanceBankStatement $statement): FinanceBankLedgerCheck
    {
        $this->as($ctx)->post(route('finance.bank.proof.submit', $statement->id), [
            'notes' => 'Source account history and statement opening, movements and closing were matched.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $check = FinanceBankLedgerCheck::query()->where('business_id', $ctx['business']->id)
            ->where('statement_id', $statement->id)->firstOrFail();
        $this->as($ctx, 'owner')->post(route('finance.bank.proof.review', [
            'check' => $check->id, 'decision' => 'approve',
        ]), ['notes' => 'Confirmed independent bank statement and original-currency ledger agreement.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        return $check->fresh();
    }

    public function test_historical_foreign_bank_opening_and_independent_statement_book_proof(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'USD');
        $rate = $this->fx($ctx);
        $opening = $this->foreignOpening($ctx, $bank, $rate, '100');
        $this->assertSame('posted', $opening->posting_status);
        $this->assertTrue(BigDecimal::of((string) $opening->base_amount)->isEqualTo('370000'));
        $openingJournal = FinanceJournal::query()->where('business_id', $ctx['business']->id)
            ->where('source_type', 'foreign_bank_opening')->firstOrFail();
        $this->assertTrue(BigDecimal::of((string) $openingJournal->rate_to_base)->isEqualTo('3700'));

        $cost = $this->postedCost($ctx, $bank, '20', $rate);
        $statement = $this->reconciledStatement($ctx, $bank, '100', '80', [
            ['type' => 'shipment_cost', 'id' => $cost->id, 'amount' => '20.00', 'direction' => 'debit'],
        ]);
        $preview = app(FinanceBankLedgerProofs::class)->preview($statement);
        $this->assertTrue($preview['opening_matches'] && $preview['closing_matches']
            && $preview['movement_matches']);
        $this->assertSame('80.00', $preview['book_closing']);
        $this->assertSame('296000.00', $preview['book_base_closing']);
        $this->as($ctx)->get(route('finance.bank.show', $statement->id))
            ->assertOk()->assertSee('Independent bank-to-ledger balance proof');
        $check = $this->proof($ctx, $statement);
        $this->assertSame('verified', $check->status);
        $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent($check));
        $this->assertDatabaseCount('finance_bank_ledger_check_events', 2);
        $this->as($ctx)->post(route('finance.controls.periods.store'), [
            'period_from' => now()->toDateString(), 'period_to' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $period = FinancePeriod::firstOrFail();
        $this->post(route('finance.controls.periods.submit', $period->id), [
            'reason' => 'Journal and verified bank-to-ledger figures agree as at close.',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_matched_statement_without_opening_fails_book_proof_and_is_not_bank_verified(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'USD');
        $rate = $this->fx($ctx);
        $cost = $this->postedCost($ctx, $bank, '20', $rate);
        $statement = $this->reconciledStatement($ctx, $bank, '100', '80', [
            ['type' => 'shipment_cost', 'id' => $cost->id, 'amount' => '20', 'direction' => 'debit'],
        ]);
        $preview = app(FinanceBankLedgerProofs::class)->preview($statement);
        $this->assertSame('0.00', $preview['book_opening']);
        $this->assertSame('-20.00', $preview['book_closing']);
        $this->assertFalse($preview['opening_matches']);
        $this->as($ctx)->post(route('finance.bank.proof.submit', $statement->id), [
            'notes' => 'Unable to substantiate this bank opening and closing with posted balances.',
        ])->assertSessionHasErrors('statement');
        $this->assertDatabaseCount('finance_bank_ledger_checks', 0);
    }

    public function test_payment_reversal_preserves_history_and_later_bank_matching_is_possible(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'UGX');
        $this->localOpening($ctx, $bank, '1000');
        $cost = $this->postedCost($ctx, $bank, '100');
        $original = FinanceJournal::query()->where('business_id', $ctx['business']->id)
            ->where('source_type', 'shipment_cost')->where('source_id', $cost->id)->firstOrFail();
        $this->as($ctx)->post(route('finance.transaction-reversals.prepare', [
            'type' => 'shipment_cost', 'id' => $cost->id,
        ]), [
            'reason' => 'The posted shipment handling payment was corrected by a documented counter-transaction.',
            'external_evidence' => 'Original transfer and incoming counter-entry are in the attached verified cash record.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $reversal = FinanceTransactionReversal::query()->where('business_id', $ctx['business']->id)
            ->firstOrFail();
        $this->assertSame('not_posted', $reversal->posting_status);
        $this->assertSame('posted', $cost->fresh()->status);
        $this->reviewPost($ctx, 'transaction_reversal', $reversal->id);
        $this->assertSame('reversed', $cost->fresh()->status);
        $this->assertSame('posted', $reversal->fresh()->posting_status);
        $this->assertSame(2, $original->lines()->count());
        $report = app(FinanceStatements::class)->report($ctx['business'],
            now()->subDay()->toDateString(), now()->toDateString());
        $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo('1000'));
        $this->assertTrue(BigDecimal::of($report['expense'])->isZero());
        $this->assertTrue(BigDecimal::of($report['balance_variance'])->isZero());
        $statement = $this->reconciledStatement($ctx, $bank, '1000', '1000', [
            ['type' => 'shipment_cost', 'id' => $cost->id, 'amount' => '100', 'direction' => 'debit'],
            ['type' => 'transaction_reversal', 'id' => $reversal->id, 'amount' => '100', 'direction' => 'credit'],
        ]);
        $this->assertTrue(app(FinanceBankLedgerProofs::class)->preview($statement)['movement_matches']);
        $this->proof($ctx, $statement);
    }

    public function test_active_bank_match_blocks_reversal_and_cross_company_references(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'UGX');
        $this->localOpening($ctx, $bank, '1000');
        $cost = $this->postedCost($ctx, $bank, '100');
        $today = now()->toDateString();
        $this->as($ctx)->post(route('finance.bank.import'), [
            'currency' => 'UGX', 'bank_profile_id' => $bank->id,
            'period_from' => $today, 'period_to' => $today, 'opening_balance' => '1000',
            'closing_balance' => '900', 'file' => UploadedFile::fake()->createWithContent(
                'matched.csv', "date,direction,amount,reference,description\n{$today},debit,100,BANK-100,Handling payment\n"
            ),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $statement = FinanceBankStatement::firstOrFail();
        $line = $statement->lines()->firstOrFail();
        $this->post(route('finance.bank.match', [
            'statement' => $statement->id, 'line' => $line->id,
        ]), [
            'candidate' => 'shipment_cost:'.$cost->id,
            'note' => 'Original transaction is already awaiting bank match confirmation',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $payload = [
            'reason' => 'An erroneous cost payment requires a separate formal reversing journal.',
            'external_evidence' => 'The original recorded transfer is now already covered by an active bank match.',
        ];
        $this->post(route('finance.transaction-reversals.prepare', [
            'type' => 'shipment_cost', 'id' => $cost->id,
        ]), $payload)->assertSessionHasErrors('reversal');
        $this->assertDatabaseCount('finance_transaction_reversals', 0);
        $this->as($ctx, 'owner')->post(route('finance.bank.confirm',
            FinanceBankMatch::firstOrFail()->id), [
                'note' => 'Original bank transfer independently matched and confirmed.',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->as($ctx)->post(route('finance.transaction-reversals.prepare', [
            'type' => 'shipment_cost', 'id' => $cost->id,
        ]), $payload)->assertSessionHasErrors('reversal');
        $other = $this->context('OTHER');
        $this->as($other)->post(route('finance.transaction-reversals.prepare', [
            'type' => 'shipment_cost', 'id' => $cost->id,
        ]), $payload)->assertNotFound();
        $this->as($other)->get(route('finance.bank.show', $statement->id))->assertNotFound();
    }

    public function test_new_bank_journal_stales_verified_statement_and_blocks_period_close(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'USD');
        $rate = $this->fx($ctx);
        $this->foreignOpening($ctx, $bank, $rate, '100');
        $cost = $this->postedCost($ctx, $bank, '20', $rate);
        $statement = $this->reconciledStatement($ctx, $bank, '100', '80', [
            ['type' => 'shipment_cost', 'id' => $cost->id, 'amount' => '20', 'direction' => 'debit'],
        ]);
        $check = $this->proof($ctx, $statement);
        $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent($check));
        $this->postedCost($ctx, $bank, '5', $rate);
        $this->assertFalse(app(FinanceBankLedgerProofs::class)->isCurrent($check->fresh()));
        $this->as($ctx)->get(route('finance.bank.show', $statement->id))
            ->assertOk()->assertSee('OUTDATED PROOF');
        $this->post(route('finance.bank.proof.submit', $statement->id), [
            'notes' => 'The new posted bank expense makes the historic statement proof stale.',
        ])->assertSessionHasErrors('statement');
        $this->post(route('finance.controls.periods.store'), [
            'period_from' => now()->toDateString(), 'period_to' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $period = FinancePeriod::firstOrFail();
        $this->post(route('finance.controls.periods.submit', $period->id), [
            'reason' => 'Must reject closing because a new bank movement is not verified.',
        ])->assertSessionHasErrors('period');
        $this->assertSame('open', $period->fresh()->status);
    }

    public function test_customer_payment_correction_restores_receivable_and_reverses_fx_gain(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'USD');
        $rfq = SalesRequest::create([
            'business_id' => $ctx['business']->id, 'customer_id' => $ctx['customer']->id,
            'reference' => 'RFQ-'.Str::ulid(), 'status' => 'confirmed', 'created_by' => $ctx['owner']->id,
        ]);
        $quote = SalesQuotation::create([
            'business_id' => $ctx['business']->id, 'sales_request_id' => $rfq->id,
            'reference' => 'QUOTE-'.Str::ulid(), 'revision' => 1, 'status' => 'accepted',
            'currency' => 'USD', 'subtotal' => '100', 'discount_amount' => '0',
            'tax_amount' => '0', 'grand_total' => '100', 'created_by' => $ctx['owner']->id,
        ]);
        $order = SalesOrder::create([
            'business_id' => $ctx['business']->id, 'reference' => 'ORDER-'.Str::ulid(),
            'sales_request_id' => $rfq->id, 'sales_quotation_id' => $quote->id,
            'customer_id' => $ctx['customer']->id, 'status' => 'confirmed', 'currency' => 'USD',
            'subtotal' => '100', 'discount_amount' => '0', 'tax_amount' => '0', 'grand_total' => '100',
            'buyer_confirmation' => 'Signed original commercial quotation',
            'confirmed_by' => $ctx['owner']->id, 'confirmed_at' => now(),
        ]);
        $ctx['shipment']->update(['sales_order_id' => $order->id]);
        $invoice = CommercialInvoice::create([
            'business_id' => $ctx['business']->id, 'reference' => 'CI-'.Str::ulid(),
            'shipment_id' => $ctx['shipment']->id, 'sales_order_id' => $order->id,
            'customer_id' => $ctx['customer']->id,
            'currency' => 'USD', 'subtotal' => '100', 'discount_amount' => '0',
            'tax_amount' => '0', 'freight_amount' => '0', 'insurance_amount' => '0',
            'grand_total' => '100', 'posting_status' => 'posted',
            'data_snapshot' => [], 'html_snapshot' => '<h1>Original buyer invoice</h1>',
            'sha256' => hash('sha256', '<h1>Original buyer invoice</h1>'),
            'issued_by' => $ctx['owner']->id, 'issued_at' => now(),
        ]);
        InvoiceAccountingHandoff::create([
            'business_id' => $ctx['business']->id, 'commercial_invoice_id' => $invoice->id,
            'currency' => 'USD', 'commercial_amount' => '100', 'posting_status' => 'posted',
            'prepared_at' => now(), 'details' => [],
        ]);
        $ledger = app(FinanceLedger::class);
        $ledger->record($ctx['business'], 'commercial_invoice', $invoice->id,
            'USD', '3600', now()->toDateString(), 'Original customer receivable',
            $ctx['accountant']->id, $ctx['owner']->id, [
                $ledger->line('receivable', '100', '0', '360000', '0'),
                $ledger->line('export_sales', '0', '100', '0', '360000'),
            ]);
        $payment = FinancePayment::create([
            'business_id' => $ctx['business']->id, 'reference' => 'PAY-'.Str::ulid(),
            'direction' => 'in', 'commercial_invoice_id' => $invoice->id,
            'cash_account_id' => $bank->account_id, 'currency' => 'USD', 'amount' => '40',
            'settled_base_amount' => '144000',
            'payment_on' => now()->toDateString(),
            'external_reference' => 'BANK-PAY-BUYER-100', 'status' => 'posted',
            'idempotency_key' => (string) Str::uuid(), 'posted_at' => now(),
            'created_by' => $ctx['accountant']->id,
        ]);
        $ledger->record($ctx['business'], 'payment', $payment->id, 'USD', '3700',
            now()->toDateString(), 'Original 40 USD customer settlement',
            $ctx['accountant']->id, $ctx['owner']->id, [
                $ledger->line($bank->account->system_key, '40', '0', '148000', '0'),
                $ledger->line('receivable', '0', '40', '0', '144000'),
                $ledger->line('fx_gain', '0', '0', '0', '4000'),
            ]);
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice)->isEqualTo('60'));
        $this->as($ctx)->post(route('finance.transaction-reversals.prepare', [
            'type' => 'payment', 'id' => $payment->id,
        ]), [
            'reason' => 'The buyer receipt was recorded in error and the original transfer never actually settled.',
            'external_evidence' => 'Independent bank evidence shows no settled transfer for this buyer receipt reference.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $reversal = FinanceTransactionReversal::firstOrFail();
        $this->reviewPost($ctx, 'transaction_reversal', $reversal->id);
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice)->isEqualTo('100'));
        $this->assertDatabaseCount('finance_journals', 3);
        $report = app(FinanceStatements::class)->report($ctx['business'],
            now()->startOfMonth()->toDateString(), now()->toDateString());
        $this->assertTrue(BigDecimal::of($report['income'])->isEqualTo('360000'));
        $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo('360000'));
        $this->assertTrue(BigDecimal::of($report['balance_variance'])->isZero());
        $this->assertTrue($invoice->fresh()->posting_status === 'posted');
    }

    public function test_reversal_evidence_tampering_is_rejected_without_journal(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'UGX');
        $this->localOpening($ctx, $bank, '1000');
        $cost = $this->postedCost($ctx, $bank, '50');
        $this->as($ctx)->post(route('finance.transaction-reversals.prepare', [
            'type' => 'shipment_cost', 'id' => $cost->id,
        ]), [
            'reason' => 'The paid shipment fee needs an evidence-backed accounting correction.',
            'external_evidence' => 'Original bank receipt was voided and a replacement entry was approved.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $correction = FinanceTransactionReversal::firstOrFail();
        $correction->update(['external_evidence' => 'Unapproved altered evidence after preparation']);
        $this->post(route('finance.reviews.request', [
            'type' => 'transaction_reversal', 'source' => $correction->id,
        ]), [
            'posting_on' => now()->toDateString(),
            'basis_notes' => 'Financial evidence must be identical to the prepared correction source.',
        ])->assertStatus(422);
        $this->assertSame('posted', $cost->fresh()->status);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_foreign_opening_cannot_overlap_existing_bank_movements(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'USD');
        $rate = $this->fx($ctx);
        $this->postedCost($ctx, $bank, '20', $rate);
        $this->as($ctx)->post(route('finance.controls.foreign-opening.store'), [
            'bank_profile_id' => $bank->id, 'currency' => 'USD',
            'foreign_amount' => '100', 'exchange_rate_id' => $rate->id,
            'effective_on' => now()->subDay()->toDateString(),
            'bank_evidence' => 'Source bank opening documented only after an existing posted bank debit.',
        ])->assertSessionHasErrors('bank_profile_id');
        $this->assertDatabaseCount('finance_foreign_bank_openings', 0);
        $this->as($ctx, 'viewer')->post(route('finance.controls.foreign-opening.store'), [
            'bank_profile_id' => $bank->id, 'currency' => 'USD',
            'foreign_amount' => '100', 'exchange_rate_id' => $rate->id,
            'effective_on' => now()->subDay()->toDateString(),
            'bank_evidence' => 'A general viewer must not create opening financial balances.',
        ])->assertForbidden();
    }

    public function test_bank_matched_payment_correction_requires_exact_imported_return_and_keeps_both_bank_events(): void
    {
        $originalNow = now();
        try {
            // A bank statement from yesterday is already reconciled and
            // balance-verified before a real counter-entry appears today.
            Carbon::setTestNow($originalNow->copy()->subDay());
            $ctx = $this->context();
            $bank = $this->bank($ctx, 'UGX');
            $this->localOpening($ctx, $bank, '1000');
            $cost = $this->postedCost($ctx, $bank, '100');
            $originalStatement = $this->reconciledStatement($ctx, $bank, '1000', '900', [
                ['type' => 'shipment_cost', 'id' => $cost->id, 'amount' => '100', 'direction' => 'debit'],
            ]);
            $originalProof = $this->proof($ctx, $originalStatement);
            $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent($originalProof));

            Carbon::setTestNow($originalNow);
            $this->as($ctx)->post(route('finance.transaction-reversals.prepare', [
                'type' => 'shipment_cost', 'id' => $cost->id,
            ]), [
                'reason' => 'The customer confirmed a bank return against a previously paid cost.',
                'external_evidence' => 'Original bank debit and returned credit are supported by separately imported statements.',
            ])->assertSessionHasErrors('return_bank_line_id');

            $today = now()->toDateString();
            $this->as($ctx)->post(route('finance.bank.import'), [
                'bank_profile_id' => $bank->id, 'currency' => 'UGX',
                'period_from' => $today, 'period_to' => $today,
                'opening_balance' => '900', 'closing_balance' => '1000',
                'file' => UploadedFile::fake()->createWithContent(
                    'actual-return.csv', "date,direction,amount,reference,description\n".
                        "{$today},credit,100,BANK-RETURN-100,Externally evidenced counter transfer\n"
                ),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $returnStatement = FinanceBankStatement::query()
                ->where('business_id', $ctx['business']->id)->latest('id')->firstOrFail();
            $returnLine = $returnStatement->lines()->firstOrFail();
            $this->as($ctx)->get(route('finance.transaction-reversals.index'))
                ->assertOk()->assertSee('BANK-RETURN-100');
            $this->post(route('finance.transaction-reversals.prepare', [
                'type' => 'shipment_cost', 'id' => $cost->id,
            ]), [
                'return_bank_line_id' => $returnLine->id,
                'reason' => 'The original cost was reimbursed through a verified separate return.',
                'external_evidence' => 'An actual incoming counter-transfer now appears in the second bank statement.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $correction = FinanceTransactionReversal::query()->where('business_id', $ctx['business']->id)
                ->firstOrFail();
            $this->assertSame($returnLine->id, $correction->return_bank_line_id);
            $this->assertNotNull($correction->original_bank_match_id);

            // Return line cannot be falsely matched to another source.
            $other = FinancePayment::create([
                'business_id' => $ctx['business']->id, 'reference' => 'PAY-'.Str::ulid(),
                'direction' => 'in', 'cash_account_id' => $bank->account_id,
                'currency' => 'UGX', 'amount' => '100', 'payment_on' => $today,
                'external_reference' => 'OTHER-TRANSFER', 'status' => 'posted',
                'idempotency_key' => (string) Str::uuid(),
                'created_by' => $ctx['accountant']->id,
            ]);
            $this->post(route('finance.bank.match', [
                'statement' => $returnStatement->id, 'line' => $returnLine->id,
            ]), [
                'candidate' => 'payment:'.$other->id,
                'note' => 'An unrelated receipt must not capture this reserved bank return.',
            ])->assertSessionHasErrors('source');

            $originalReturnReference = $returnLine->bank_reference;
            $returnLine->update(['bank_reference' => 'ALTERED-RETURN-REFERENCE']);
            $this->as($ctx)->post(route('finance.reviews.request', [
                'type' => 'transaction_reversal', 'source' => $correction->id,
            ]), [
                'posting_on' => $today,
                'basis_notes' => 'This altered bank-return source evidence must be rejected.',
            ])->assertStatus(422);
            $returnLine->update(['bank_reference' => $originalReturnReference]);
            $this->as($ctx)->post(route('finance.reviews.request', [
                'type' => 'transaction_reversal', 'source' => $correction->id,
            ]), [
                'posting_on' => now()->subDay()->toDateString(),
                'basis_notes' => 'The journal date cannot precede the actual returned bank transaction.',
            ])->assertSessionHasErrors('posting_on');
            $this->reviewPost($ctx, 'transaction_reversal', $correction->id, null, $today);
            $this->assertSame('reversed', $cost->fresh()->status);
            $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent($originalProof));

            $this->as($ctx)->post(route('finance.bank.match', [
                'statement' => $returnStatement->id, 'line' => $returnLine->id,
            ]), [
                'candidate' => 'transaction_reversal:'.$correction->id,
                'note' => 'Imported bank return is paired to the independently approved correction.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $returnMatch = FinanceBankMatch::query()
                ->where('business_id', $ctx['business']->id)
                ->where('statement_line_id', $returnLine->id)->firstOrFail();
            $this->as($ctx, 'owner')->post(route('finance.bank.confirm', $returnMatch->id), [
                'note' => 'Independently checked this return is on the original bank and amount.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->as($ctx, 'owner')->post(route('finance.bank.complete', $returnStatement->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $returnProof = $this->proof($ctx, $returnStatement);
            $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent($returnProof));
            $this->assertDatabaseCount('finance_journals', 3);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_foreign_bank_revaluation_posts_base_only_gain_and_loss_with_no_bank_transfer(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'USD');
        $openingRate = $this->fx($ctx, 'USD', '3600');
        $this->foreignOpening($ctx, $bank, $openingRate, '100');
        $today = now()->toDateString();
        $this->as($ctx)->post(route('finance.rates.propose'), [
            'currency' => 'USD', 'rate_to_base' => '3700', 'effective_on' => $today,
            'source_note' => 'Independent closing valuation rate for USD',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $rate = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
        $this->as($ctx, 'owner')->post(route('finance.rates.approve', $rate->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->as($ctx)->get(route('finance.fx-revaluations.index'))
            ->assertOk()->assertSee('Foreign bank revaluation');
        $this->post(route('finance.fx-revaluations.prepare'), [
            'bank_profile_id' => $bank->id, 'exchange_rate_id' => $rate->id,
            'valued_on' => $today, 'valuation_evidence' => 'Approved independent USD closing rate and historical bank original-units ledger evidence.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $revaluation = FinanceFxRevaluation::firstOrFail();
        $this->assertTrue(BigDecimal::of((string) $revaluation->foreign_balance)->isEqualTo('100'));
        $this->assertTrue(BigDecimal::of((string) $revaluation->previous_base)->isEqualTo('360000'));
        $this->assertTrue(BigDecimal::of((string) $revaluation->change_base)->isEqualTo('10000'));
        // Deliberately pass the HTML form's string rate value, not an integer.
        $this->as($ctx)->post(route('finance.reviews.request', [
            'type' => 'fx_revaluation', 'source' => $revaluation->id,
        ]), [
            'posting_on' => $today, 'exchange_rate_id' => (string) $rate->id,
            'basis_notes' => 'Non-cash book valuation using a separately approved closing market rate.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('source_type', 'fx_revaluation')
            ->where('business_id', $ctx['business']->id)->firstOrFail();
        $this->as($ctx, 'owner')->post(route('finance.reviews.approve', $review->id), [
            'notes' => 'Independent valuation checked against bank source amount and FX reference.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->as($ctx)->post(route('finance.reviews.post', $review->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $journal = FinanceJournal::query()->where('source_type', 'fx_revaluation')
            ->where('business_id', $ctx['business']->id)->firstOrFail();
        $this->assertTrue($journal->lines()->get()->every(fn ($l) => BigDecimal::of((string) $l->debit)->isZero()
            && BigDecimal::of((string) $l->credit)->isZero()));
        $bankLines = FinanceJournalLine::query()->where('business_id', $ctx['business']->id)
            ->where('account_id', $bank->account_id)->get();
        $native = BigDecimal::zero();
        foreach ($bankLines as $line) {
            $native = $native->plus((string) $line->debit)
                ->minus((string) $line->credit);
        }
        $this->assertTrue($native->isEqualTo('100'));
        $report = app(FinanceStatements::class)->report($ctx['business'],
            now()->subDay()->toDateString(), $today);
        $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo('370000'));
        $this->assertTrue(BigDecimal::of($report['income'])->isEqualTo('10000'));
        $this->assertTrue(BigDecimal::of($report['equity'])->isEqualTo('360000'));
        $this->assertTrue(BigDecimal::of($report['balance_variance'])->isZero());
        $this->as($ctx)->post(route('finance.fx-revaluations.prepare'), [
            'bank_profile_id' => $bank->id, 'exchange_rate_id' => $rate->id,
            'valued_on' => $today, 'valuation_evidence' => 'A second non-cash revaluation of the same bank and date must not be accepted.',
        ])->assertSessionHasErrors('valued_on');
    }

    public function test_successive_foreign_bank_loss_then_gain_uses_last_approved_book_value(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'USD');
        $rate = $this->fx($ctx, 'USD', '3600');
        $this->foreignOpening($ctx, $bank, $rate, '100');
        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->as($ctx)->post(route('finance.rates.propose'), [
            'currency' => 'USD', 'rate_to_base' => '3500',
            'effective_on' => $yesterday,
            'source_note' => 'Verified previous closing exchange-rate evidence',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $rateLoss = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
        $this->as($ctx, 'owner')->post(route('finance.rates.approve', $rateLoss->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->as($ctx)->post(route('finance.fx-revaluations.prepare'), [
            'bank_profile_id' => $bank->id, 'exchange_rate_id' => $rateLoss->id,
            'valued_on' => $yesterday,
            'valuation_evidence' => 'Verified first bank valuation using independently approved USD rate.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $first = FinanceFxRevaluation::firstOrFail();
        $this->assertTrue(BigDecimal::of((string) $first->change_base)->isEqualTo('-10000'));
        $this->reviewPost($ctx, 'fx_revaluation', $first->id, $rateLoss->id, $yesterday);

        $this->as($ctx)->post(route('finance.rates.propose'), [
            'currency' => 'USD', 'rate_to_base' => '3750',
            'effective_on' => $today, 'source_note' => 'Latest independent bank revaluation rate',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $rateGain = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
        $this->as($ctx, 'owner')->post(route('finance.rates.approve', $rateGain->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->as($ctx)->post(route('finance.fx-revaluations.prepare'), [
            'bank_profile_id' => $bank->id, 'exchange_rate_id' => $rateGain->id,
            'valued_on' => $today,
            'valuation_evidence' => 'Latest closing rate recalculates the asset from its already revalued book value.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $second = FinanceFxRevaluation::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
        $this->assertTrue(BigDecimal::of((string) $second->previous_base)->isEqualTo('350000'));
        $this->assertTrue(BigDecimal::of((string) $second->change_base)->isEqualTo('25000'));
        $this->as($ctx)->get(route('finance.reviews'))->assertOk()
            ->assertSee($second->reference);
        $this->reviewPost($ctx, 'fx_revaluation', $second->id, $rateGain->id, $today);
        $report = app(FinanceStatements::class)->report($ctx['business'], $yesterday, $today);
        $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo('375000'));
        $this->assertTrue(BigDecimal::of($report['income'])->isEqualTo('25000'));
        $this->assertTrue(BigDecimal::of($report['expense'])->isEqualTo('10000'));
        $this->assertTrue(BigDecimal::of($report['net'])->isEqualTo('15000'));
        $this->assertTrue(BigDecimal::of($report['balance_variance'])->isZero());
    }

    public function test_bank_proof_remains_movement_balanced_but_becomes_stale_after_fx_revaluation(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'USD');
        $originalRate = $this->fx($ctx, 'USD', '3600');
        $this->foreignOpening($ctx, $bank, $originalRate, '100');
        $cost = $this->postedCost($ctx, $bank, '20', $originalRate);
        $statement = $this->reconciledStatement($ctx, $bank, '100', '80', [
            ['type' => 'shipment_cost', 'id' => $cost->id, 'amount' => '20', 'direction' => 'debit'],
        ]);
        $verified = $this->proof($ctx, $statement);
        $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent($verified));
        $this->as($ctx)->post(route('finance.rates.propose'), [
            'currency' => 'USD', 'rate_to_base' => '3700',
            'effective_on' => now()->toDateString(),
            'source_note' => 'Approved closing bank currency valuation evidence',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $rate = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
        $this->as($ctx, 'owner')->post(route('finance.rates.approve', $rate->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->as($ctx)->post(route('finance.fx-revaluations.prepare'), [
            'bank_profile_id' => $bank->id, 'exchange_rate_id' => $rate->id,
            'valued_on' => now()->toDateString(),
            'valuation_evidence' => 'Revalue foreign bank after the final source currency bank debit.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $valuation = FinanceFxRevaluation::firstOrFail();
        $this->assertTrue(BigDecimal::of((string) $valuation->change_base)->isEqualTo('8000'));
        $this->reviewPost($ctx, 'fx_revaluation', $valuation->id, $rate->id);
        $preview = app(FinanceBankLedgerProofs::class)->preview($statement);
        $this->assertTrue($preview['opening_matches']);
        $this->assertTrue($preview['closing_matches']);
        $this->assertTrue($preview['movement_matches']);
        $this->assertSame(1, $preview['ledger_movement_count']);
        $this->assertSame('296000.00', $preview['book_base_closing']);
        $this->assertFalse(app(FinanceBankLedgerProofs::class)->isCurrent($verified->fresh()));
        $updated = $this->proof($ctx, $statement);
        $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent($updated));
        $this->assertDatabaseCount('finance_bank_ledger_check_events', 5);
    }

    public function test_fx_valuation_draft_is_rejected_when_new_bank_journal_changes_its_basis(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'USD');
        $originalRate = $this->fx($ctx, 'USD', '3600');
        $this->foreignOpening($ctx, $bank, $originalRate, '100');
        $this->as($ctx)->post(route('finance.rates.propose'), [
            'currency' => 'USD', 'rate_to_base' => '3700',
            'effective_on' => now()->toDateString(),
            'source_note' => 'Approved closing USD market rate source',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $rate = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
            ->latest('id')->firstOrFail();
        $this->as($ctx, 'owner')->post(route('finance.rates.approve', $rate->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->as($ctx)->post(route('finance.fx-revaluations.prepare'), [
            'bank_profile_id' => $bank->id, 'exchange_rate_id' => $rate->id,
            'valued_on' => now()->toDateString(),
            'valuation_evidence' => 'Prepared valuation before any additional bank expense was posted.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft = FinanceFxRevaluation::firstOrFail();
        $this->postedCost($ctx, $bank, '20', $originalRate);
        $this->as($ctx)->post(route('finance.reviews.request', [
            'type' => 'fx_revaluation', 'source' => $draft->id,
        ]), [
            'posting_on' => now()->toDateString(),
            'exchange_rate_id' => $rate->id,
            'basis_notes' => 'A later bank ledger entry invalidated the original basis and must be detected.',
        ])->assertStatus(422);
        $this->assertSame('not_posted', $draft->fresh()->posting_status);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->as($ctx, 'viewer')->post(route('finance.fx-revaluations.prepare'), [
            'bank_profile_id' => $bank->id, 'exchange_rate_id' => $rate->id,
            'valued_on' => now()->toDateString(),
            'valuation_evidence' => 'Unauthorised staff may not revalue the general ledger.',
        ])->assertForbidden();
    }

    public function test_bank_statement_signature_requires_registered_issuer_and_exact_bank_file_hash(): void
    {
        $ctx = $this->context();
        $bank = $this->bank($ctx, 'UGX');
        $this->localOpening($ctx, $bank, '1000');
        $cost = $this->postedCost($ctx, $bank, '100');
        $statement = $this->reconciledStatement($ctx, $bank, '1000', '900', [
            ['type' => 'shipment_cost', 'id' => $cost->id, 'amount' => '100', 'direction' => 'debit'],
        ]);
        $accountRef = 'UG-1234-567890';
        $this->as($ctx, 'owner')->post(route('finance.controls.banks.identifier', $bank->id), [
            'account_identifier' => $accountRef,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(hash_hmac('sha256', hash('sha256', 'UG1234567890'),
            (string) config('app.key')),
            $bank->fresh()->account_identifier_sha256);
        $private = openssl_pkey_new(['private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        $this->assertIsArray($details);
        $issuer = [
            'active' => true, 'verified_out_of_band' => true,
            'institution' => 'Demo Bank Ltd', 'key_id' => 'verified-bank-2026',
            'public_key_pem' => $details['key'],
        ];
        $payload = [
            'version' => 1, 'issuer_id' => 'official.test-bank',
            'key_id' => 'verified-bank-2026',
            'account_identifier_sha256' => hash('sha256', 'UG1234567890'),
            'file_sha256' => $statement->file_sha256,
            'currency' => $statement->currency,
            'period_from' => $statement->period_from->toDateString(),
            'period_to' => $statement->period_to->toDateString(),
            'opening_balance' => (string) $statement->opening_balance,
            'closing_balance' => (string) $statement->closing_balance,
            'credits' => (string) $statement->credits,
            'debits' => (string) $statement->debits,
            'statement_reference' => 'BANK-VERIFIED-REFERENCE-01',
            'signed_at' => now()->toIso8601String(),
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        openssl_sign($raw, $signature, $private, OPENSSL_ALGO_SHA256);
        $form = [
            'signed_attestation' => UploadedFile::fake()
                ->createWithContent('bank-signed.json', $raw),
            'signature_base64' => base64_encode($signature),
        ];
        $this->as($ctx)->post(route('finance.bank.authenticate', $statement->id), $form)
            ->assertSessionHasErrors('attestation');
        $this->assertDatabaseCount('finance_bank_statement_attestations', 0);
        config()->set('finance_banks.trusted_issuers', ['official.test-bank' => $issuer]);
        $tampered = $payload;
        $tampered['file_sha256'] = str_repeat('a', 64);
        $tamperedRaw = json_encode($tampered, JSON_THROW_ON_ERROR);
        openssl_sign($tamperedRaw, $tamperedSig, $private, OPENSSL_ALGO_SHA256);
        $this->post(route('finance.bank.authenticate', $statement->id), [
            'signed_attestation' => UploadedFile::fake()
                ->createWithContent('wrong-file.json', $tamperedRaw),
            'signature_base64' => base64_encode($tamperedSig),
        ])->assertSessionHasErrors('attestation');
        $this->post(route('finance.bank.authenticate', $statement->id), [
            'signed_attestation' => UploadedFile::fake()
                ->createWithContent('correct-file.json', $raw),
            'signature_base64' => base64_encode(str_repeat('Z', 256)),
        ])->assertSessionHasErrors('signature');
        $this->post(route('finance.bank.authenticate', $statement->id), [
            'signed_attestation' => UploadedFile::fake()
                ->createWithContent('bank-signed.json', $raw),
            'signature_base64' => base64_encode($signature),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $auth = app(FinanceBankAuthenticity::class);
        $this->assertTrue($auth->current($statement));
        $this->as($ctx)->get(route('finance.bank.show', $statement->id))
            ->assertOk()->assertSee('SIGNED EVIDENCE VALID');
        $this->assertDatabaseCount('finance_bank_statement_attestations', 1);
        $this->as($ctx, 'owner')->post(route('finance.controls.banks.identifier', $bank->id), [
            'account_identifier' => 'ALTERED12345678',
        ])->assertSessionHasErrors('bank_profile_id');
        // Re-verification is computed every time, not assumed from status.
        $statement->update(['closing_balance' => '800']);
        $this->assertFalse($auth->current($statement->fresh()));
        $this->as($ctx)->get(route('finance.bank.show', $statement->id))
            ->assertOk()->assertSee('UNAUTHENTICATED CSV');
        config()->set('finance_banks.trusted_issuers', []);
    }

    private function documentedForeignInvoice(array $ctx, string $amount = '100'): CommercialInvoice
    {
        $rfq = SalesRequest::create([
            'business_id' => $ctx['business']->id, 'customer_id' => $ctx['customer']->id,
            'reference' => 'RFQ-'.Str::ulid(), 'status' => 'confirmed', 'created_by' => $ctx['owner']->id,
        ]);
        $quotation = SalesQuotation::create([
            'business_id' => $ctx['business']->id, 'sales_request_id' => $rfq->id,
            'reference' => 'QUOTE-'.Str::ulid(), 'revision' => 1, 'status' => 'accepted',
            'currency' => 'USD', 'subtotal' => $amount, 'discount_amount' => '0',
            'tax_amount' => '0', 'grand_total' => $amount, 'created_by' => $ctx['owner']->id,
        ]);
        $order = SalesOrder::create([
            'business_id' => $ctx['business']->id, 'reference' => 'SO-'.Str::ulid(),
            'sales_request_id' => $rfq->id, 'sales_quotation_id' => $quotation->id,
            'customer_id' => $ctx['customer']->id, 'status' => 'confirmed', 'currency' => 'USD',
            'subtotal' => $amount, 'discount_amount' => '0', 'tax_amount' => '0',
            'grand_total' => $amount, 'buyer_confirmation' => 'Buyer confirmed price and order.',
            'confirmed_by' => $ctx['owner']->id, 'confirmed_at' => now(),
        ]);
        $ctx['shipment']->update(['sales_order_id' => $order->id]);

        return CommercialInvoice::create([
            'business_id' => $ctx['business']->id, 'reference' => 'INV-'.Str::ulid(),
            'shipment_id' => $ctx['shipment']->id, 'sales_order_id' => $order->id,
            'customer_id' => $ctx['customer']->id, 'currency' => 'USD',
            'subtotal' => $amount, 'discount_amount' => '0', 'tax_amount' => '0',
            'freight_amount' => '0', 'insurance_amount' => '0', 'grand_total' => $amount,
            'posting_status' => 'not_posted', 'data_snapshot' => [],
            'html_snapshot' => '<h1>Foreign buyer invoice</h1>',
            'sha256' => hash('sha256', '<h1>Foreign buyer invoice</h1>'),
            'issued_by' => $ctx['owner']->id, 'issued_at' => now(),
        ]);
    }

    public function test_foreign_receivable_revaluation_is_reclassified_as_paid_without_phantom_balance(): void
    {
        $clock = now();
        try {
            $ctx = $this->context();
            $bank = $this->bank($ctx, 'USD');
            $originalRate = $this->fx($ctx, 'USD', '3600');
            $invoice = $this->documentedForeignInvoice($ctx, '100');
            $this->reviewPost($ctx, 'commercial_invoice', $invoice->id, $originalRate->id);
            $this->as($ctx)->post(route('finance.rates.propose'), [
                'currency' => 'USD', 'rate_to_base' => '3700',
                'effective_on' => now()->subDay()->toDateString(),
                'source_note' => 'Bank supported closing rate for foreign invoice',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $closingRate = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
                ->latest('id')->firstOrFail();
            $this->as($ctx, 'owner')->post(route('finance.rates.approve', $closingRate->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $today = now()->toDateString();
            $this->as($ctx)->get(route('finance.monetary-revaluations.index'))
                ->assertOk()->assertSee($invoice->reference);
            $this->post(route('finance.monetary-revaluations.prepare'), [
                'source_type' => 'commercial_invoice', 'source_id' => $invoice->id,
                'exchange_rate_id' => $closingRate->id, 'valued_on' => $today,
                'valuation_evidence' => 'Closing rate source approved and the original buyer invoice remains outstanding.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $valuation = FinanceMonetaryRevaluation::firstOrFail();
            $this->assertTrue(BigDecimal::of((string) $valuation->change_base)->isEqualTo('10000'));
            $this->reviewPost($ctx, 'monetary_revaluation', $valuation->id, $closingRate->id, $today);
            $position = app(FinanceMonetaryRevaluations::class)
                ->position($ctx['business'], 'commercial_invoice', $invoice->id);
            $this->assertSame('370000.00', $position['previous_base']);
            $this->assertSame('100.00', $position['foreign_balance']);
            foreach ([['amount' => '40', 'rate' => '3800', 'settled' => '148000'],
                ['amount' => '60', 'rate' => '3900', 'settled' => '222000']] as $index => $iteration) {
                if ($index === 1) {
                    Carbon::setTestNow($clock->copy()->addDay());
                }
                $settlementDate = now()->toDateString();
                $this->as($ctx)->post(route('finance.rates.propose'), [
                    'currency' => 'USD', 'rate_to_base' => $iteration['rate'],
                    'effective_on' => $settlementDate,
                    'source_note' => 'Independently evidenced buyer settlement FX rate',
                ])->assertRedirect()->assertSessionHasNoErrors();
                $rate = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
                    ->latest('id')->firstOrFail();
                $this->as($ctx, 'owner')->post(route('finance.rates.approve', $rate->id))
                    ->assertRedirect()->assertSessionHasNoErrors();
                $this->as($ctx)->post(route('finance.payments.store'), [
                    'direction' => 'in', 'commercial_invoice_id' => $invoice->id,
                    'bank_profile_id' => $bank->id, 'cash_account_key' => 'bank',
                    'amount' => $iteration['amount'], 'payment_on' => $settlementDate,
                    'external_reference' => 'BUYER-'.Str::ulid(),
                    'idempotency_key' => (string) Str::uuid(),
                ])->assertRedirect()->assertSessionHasNoErrors();
                $payment = FinancePayment::query()->where('business_id', $ctx['business']->id)
                    ->orderByDesc('id')->firstOrFail();
                $this->reviewPost($ctx, 'payment', $payment->id, $rate->id);
                $this->assertTrue(BigDecimal::of((string) $payment->fresh()->settled_base_amount)
                    ->isEqualTo($iteration['settled']));
            }
            $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice, false)->isZero());
            $report = app(FinanceStatements::class)->report($ctx['business'], $today, now()->toDateString());
            $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo('386000'));
            $this->assertTrue(BigDecimal::of($report['income'])->isEqualTo('386000'));
            $this->assertTrue(BigDecimal::of($report['balance_variance'])->isZero());
            $position = app(FinanceMonetaryRevaluations::class)
                ->position($ctx['business'], 'commercial_invoice', $invoice->id);
            $this->assertSame('0.00', $position['foreign_balance']);
            $this->assertSame('0.00', $position['previous_base']);
            $this->assertSame('0.00', $position['unrealized_open']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_foreign_supplier_payable_revaluation_releases_liability_on_settlement(): void
    {
        $clock = now();
        try {
            $ctx = $this->context();
            $bank = $this->bank($ctx, 'USD');
            $rateOriginal = $this->fx($ctx, 'USD', '3600');
            $product = Product::create([
                'business_id' => $ctx['business']->id, 'sku' => 'AV-'.Str::ulid(),
                'name' => 'Fresh avocados', 'base_unit' => 'kg', 'active' => true,
            ]);
            $order = PurchaseOrder::create([
                'business_id' => $ctx['business']->id, 'reference' => 'PO-'.Str::ulid(),
                'kind' => 'market', 'product_id' => $product->id, 'vendor_name' => 'Produce supplier',
                'purchased_on' => now()->toDateString(), 'quantity' => '10', 'unit_price' => '10',
                'estimated_total' => '100', 'currency' => 'USD', 'status' => 'received',
                'idempotency_key' => (string) Str::uuid(), 'created_by' => $ctx['owner']->id,
            ]);
            $bill = FinanceSupplierBill::create([
                'business_id' => $ctx['business']->id, 'purchase_order_id' => $order->id,
                'reference' => 'SBILL-'.Str::ulid(), 'vendor_invoice_reference' => 'SUP-100',
                'vendor_invoice_date' => now()->toDateString(),
                'accepted_quantity' => '10', 'total' => '100', 'currency' => 'USD',
                'posting_status' => 'not_posted', 'created_by' => $ctx['owner']->id,
            ]);
            $this->reviewPost($ctx, 'supplier_bill', $bill->id, $rateOriginal->id);
            $today = now()->toDateString();
            $this->as($ctx)->post(route('finance.rates.propose'), [
                'currency' => 'USD', 'rate_to_base' => '3700',
                'effective_on' => $today, 'source_note' => 'Independently confirmed supplier FX revaluation rate',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $rateClosing = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
                ->latest('id')->firstOrFail();
            $this->as($ctx, 'owner')->post(route('finance.rates.approve', $rateClosing->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->as($ctx)->post(route('finance.monetary-revaluations.prepare'), [
                'source_type' => 'supplier_bill', 'source_id' => $bill->id,
                'exchange_rate_id' => $rateClosing->id, 'valued_on' => $today,
                'valuation_evidence' => 'Supplier invoice contract and closing rate verified by another reviewer.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $valuation = FinanceMonetaryRevaluation::firstOrFail();
            $this->assertTrue(BigDecimal::of((string) $valuation->change_base)->isEqualTo('10000'));
            $this->reviewPost($ctx, 'monetary_revaluation', $valuation->id, $rateClosing->id, $today);
            Carbon::setTestNow($clock->copy()->addDay());
            $paidOn = now()->toDateString();
            $this->as($ctx)->post(route('finance.rates.propose'), [
                'currency' => 'USD', 'rate_to_base' => '3800',
                'effective_on' => $paidOn, 'source_note' => 'Evidence-backed supplier payment rate',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $paymentRate = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
                ->latest('id')->firstOrFail();
            $this->as($ctx, 'owner')->post(route('finance.rates.approve', $paymentRate->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->as($ctx)->post(route('finance.payments.store'), [
                'direction' => 'out', 'supplier_bill_id' => $bill->id,
                'cash_account_key' => 'bank', 'bank_profile_id' => $bank->id,
                'amount' => '100', 'payment_on' => $paidOn,
                'external_reference' => 'SUPPAY-'.Str::ulid(),
                'idempotency_key' => (string) Str::uuid(),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $payment = FinancePayment::firstOrFail();
            $this->reviewPost($ctx, 'payment', $payment->id, $paymentRate->id);
            $this->assertTrue(BigDecimal::of((string) $payment->fresh()->settled_base_amount)
                ->isEqualTo('370000'));
            $this->assertTrue(app(FinanceDocuments::class)->outstanding($bill, false)->isZero());
            $position = app(FinanceMonetaryRevaluations::class)
                ->position($ctx['business'], 'supplier_bill', $bill->id);
            $this->assertSame('0.00', $position['previous_base']);
            $this->assertSame('0.00', $position['unrealized_open']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_partial_customer_receipt_return_reopens_only_refunded_receivable_and_allows_repayment(): void
    {
        $clock = now();
        try {
            Carbon::setTestNow($clock->copy()->subDays(2));
            $ctx = $this->context();
            $bank = $this->bank($ctx, 'USD');
            $initialRate = $this->fx($ctx, 'USD', '3600');
            $invoice = $this->documentedForeignInvoice($ctx, '100');
            $this->reviewPost($ctx, 'commercial_invoice', $invoice->id, $initialRate->id);
            $this->as($ctx)->post(route('finance.rates.propose'), [
                'currency' => 'USD', 'rate_to_base' => '3700',
                'effective_on' => now()->toDateString(),
                'source_note' => 'Independently evidenced first buyer receipt FX',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $rate = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
                ->latest('id')->firstOrFail();
            $this->as($ctx, 'owner')->post(route('finance.rates.approve', $rate->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->as($ctx)->post(route('finance.payments.store'), [
                'direction' => 'in', 'commercial_invoice_id' => $invoice->id,
                'bank_profile_id' => $bank->id, 'cash_account_key' => 'bank',
                'amount' => '100', 'payment_on' => now()->toDateString(),
                'external_reference' => 'CUSTOMER-FULL-100',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $paid = FinancePayment::firstOrFail();
            $this->reviewPost($ctx, 'payment', $paid->id, $rate->id);
            $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice, false)->isZero());
            $originalStatement = $this->reconciledStatement($ctx, $bank, '0', '100', [
                ['type' => 'payment', 'id' => $paid->id, 'amount' => '100', 'direction' => 'credit'],
            ]);
            $this->proof($ctx, $originalStatement);

            Carbon::setTestNow($clock->copy()->subDay());
            $date = now()->toDateString();
            $this->as($ctx)->post(route('finance.bank.import'), [
                'bank_profile_id' => $bank->id, 'currency' => 'USD',
                'period_from' => $date, 'period_to' => $date,
                'opening_balance' => '100', 'closing_balance' => '75',
                'file' => UploadedFile::fake()->createWithContent('buyer-partial-return.csv',
                    "date,direction,amount,reference,description\n".
                    "{$date},debit,25,BUYER-RETURN-25,Actual documented partial refund to buyer\n"),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $returnStatement = FinanceBankStatement::query()->where('business_id', $ctx['business']->id)
                ->latest('id')->firstOrFail();
            $line = $returnStatement->lines()->firstOrFail();
            $this->as($ctx)->post(route('finance.transaction-reversals.prepare', [
                'type' => 'payment', 'id' => $paid->id,
            ]), [
                'return_bank_line_id' => $line->id,
                'reason' => 'A documented part of the buyer remittance was sent back and is now due again.',
                'external_evidence' => 'Buyer receipt and separate bank debit for twenty-five dollars are independently traceable.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $partial = FinancePartialBankReturn::firstOrFail();
            $this->reviewPost($ctx, 'partial_bank_return', $partial->id, null, $date);
            $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice, false)->isEqualTo('25'));
            $position = app(FinanceMonetaryRevaluations::class)
                ->position($ctx['business'], 'commercial_invoice', $invoice->id);
            $this->assertSame('25.00', $position['foreign_balance']);
            $this->assertSame('90000.00', $position['previous_base']);
            $this->as($ctx)->post(route('finance.bank.match', [
                'statement' => $returnStatement->id, 'line' => $line->id,
            ]), [
                'candidate' => 'partial_bank_return:'.$partial->id,
                'note' => 'Partial refund is matched to its exact independently approved bank entry.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $match = FinanceBankMatch::query()->where('business_id', $ctx['business']->id)
                ->where('statement_line_id', $line->id)->firstOrFail();
            $this->as($ctx, 'owner')->post(route('finance.bank.confirm', $match->id), [
                'note' => 'The bank debit belongs only to the separately approved buyer return.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->as($ctx, 'owner')->post(route('finance.bank.complete', $returnStatement->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->proof($ctx, $returnStatement);
            Carbon::setTestNow($clock);
            $today = now()->toDateString();
            $this->as($ctx)->post(route('finance.rates.propose'), [
                'currency' => 'USD', 'rate_to_base' => '3800',
                'effective_on' => $today,
                'source_note' => 'Independently evidenced replacement buyer payment FX rate',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $repayRate = FinanceExchangeRate::query()->where('business_id', $ctx['business']->id)
                ->latest('id')->firstOrFail();
            $this->as($ctx, 'owner')->post(route('finance.rates.approve', $repayRate->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->as($ctx)->post(route('finance.payments.store'), [
                'direction' => 'in', 'commercial_invoice_id' => $invoice->id,
                'cash_account_key' => 'bank', 'bank_profile_id' => $bank->id,
                'amount' => '25', 'payment_on' => $today,
                'external_reference' => 'BUYER-REPAY-25',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $newPayment = FinancePayment::query()->where('business_id', $ctx['business']->id)
                ->orderByDesc('id')->firstOrFail();
            $this->reviewPost($ctx, 'payment', $newPayment->id, $repayRate->id);
            $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice, false)->isZero());
            $this->assertTrue(BigDecimal::of((string) $newPayment->fresh()->settled_base_amount)
                ->isEqualTo('90000'));
            $final = app(FinanceMonetaryRevaluations::class)
                ->position($ctx['business'], 'commercial_invoice', $invoice->id);
            $this->assertSame('0.00', $final['previous_base']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_multiple_partial_bank_returns_restore_only_actual_returned_amount(): void
    {
        $clock = now();
        try {
            Carbon::setTestNow($clock->copy()->subDays(2));
            $ctx = $this->context();
            $bank = $this->bank($ctx, 'UGX');
            $this->localOpening($ctx, $bank, '1000');
            $cost = $this->postedCost($ctx, $bank, '100');
            $original = $this->reconciledStatement($ctx, $bank, '1000', '900', [
                ['type' => 'shipment_cost', 'id' => $cost->id,
                    'amount' => '100', 'direction' => 'debit'],
            ]);
            $proof = $this->proof($ctx, $original);
            $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent($proof));
            foreach ([['days' => 1, 'return' => '30', 'opening' => '900', 'closing' => '930'],
                ['days' => 0, 'return' => '20', 'opening' => '930', 'closing' => '950']] as $item) {
                Carbon::setTestNow($clock->copy()->subDays($item['days']));
                $date = now()->toDateString();
                $csv = "date,direction,amount,reference,description\n".
                    "{$date},credit,{$item['return']},RETURN-{$item['return']},Evidence-backed partial counter transfer\n";
                $this->as($ctx)->post(route('finance.bank.import'), [
                    'bank_profile_id' => $bank->id, 'currency' => 'UGX',
                    'period_from' => $date, 'period_to' => $date,
                    'opening_balance' => $item['opening'],
                    'closing_balance' => $item['closing'],
                    'file' => UploadedFile::fake()->createWithContent('partial-'.$item['return'].'.csv', $csv),
                ])->assertRedirect()->assertSessionHasNoErrors();
                $statement = FinanceBankStatement::query()->where('business_id', $ctx['business']->id)
                    ->orderByDesc('id')->firstOrFail();
                $returnLine = $statement->lines()->firstOrFail();
                $this->as($ctx)->get(route('finance.transaction-reversals.index'))
                    ->assertOk()->assertSee('RETURN-'.$item['return']);
                $this->post(route('finance.transaction-reversals.prepare', [
                    'type' => 'shipment_cost', 'id' => $cost->id,
                ]), [
                    'return_bank_line_id' => $returnLine->id,
                    'reason' => 'The vendor refunded part of an earlier handling cost by a separate bank transaction.',
                    'external_evidence' => 'The original bank debit and this exact partial counter transfer have identifiable external bank references.',
                ])->assertRedirect()->assertSessionHasNoErrors();
                $partial = FinancePartialBankReturn::query()
                    ->where('business_id', $ctx['business']->id)
                    ->orderByDesc('id')->firstOrFail();
                $this->assertTrue(BigDecimal::of((string) $partial->amount)
                    ->isEqualTo($item['return']));
                $this->assertSame('posted', $cost->fresh()->status);
                $this->reviewPost($ctx, 'partial_bank_return', $partial->id, null, $date);
                $this->assertSame('posted', $partial->fresh()->posting_status);
                $this->as($ctx)->post(route('finance.bank.match', [
                    'statement' => $statement->id, 'line' => $returnLine->id,
                ]), [
                    'candidate' => 'partial_bank_return:'.$partial->id,
                    'note' => 'The independently approved return matches only this reserved bank statement line.',
                ])->assertRedirect()->assertSessionHasNoErrors();
                $match = FinanceBankMatch::query()->where('business_id', $ctx['business']->id)
                    ->where('statement_line_id', $returnLine->id)->firstOrFail();
                $this->as($ctx, 'owner')->post(route('finance.bank.confirm', $match->id), [
                    'note' => 'Different authorised reviewer confirmed the partial return on the same account.',
                ])->assertRedirect()->assertSessionHasNoErrors();
                $this->as($ctx, 'owner')->post(route('finance.bank.complete', $statement->id))
                    ->assertRedirect()->assertSessionHasNoErrors();
                $this->proof($ctx, $statement);
            }
            $this->assertDatabaseCount('finance_partial_bank_returns', 2);
            $this->assertSame('posted', $cost->fresh()->status);
            $report = app(FinanceStatements::class)->report($ctx['business'],
                $clock->copy()->subDays(3)->toDateString(),
                $clock->toDateString());
            $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo('950'));
            $this->assertTrue(BigDecimal::of($report['expense'])->isEqualTo('50'));
            $this->assertTrue(BigDecimal::of($report['balance_variance'])->isZero());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_closed_period_refund_date_requires_independent_reopening_then_new_close(): void
    {
        $originalClock = now();
        try {
            Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00', 'Africa/Kampala'));
            $ctx = $this->context();
            $bank = $this->bank($ctx, 'UGX');
            $this->localOpening($ctx, $bank, '1000');
            $cost = $this->postedCost($ctx, $bank, '100');
            $original = $this->reconciledStatement($ctx, $bank, '1000', '900', [
                ['type' => 'shipment_cost', 'id' => $cost->id, 'amount' => '100', 'direction' => 'debit'],
            ]);
            $this->proof($ctx, $original);
            Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00', 'Africa/Kampala'));
            $oldClosing = $this->reconciledStatement($ctx, $bank, '900', '900', []);
            $this->proof($ctx, $oldClosing);
            Carbon::setTestNow($originalClock);
            $this->as($ctx)->post(route('finance.controls.periods.store'), [
                'period_from' => '2026-09-01', 'period_to' => '2026-09-30',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $period = FinancePeriod::firstOrFail();
            $this->post(route('finance.controls.periods.submit', $period->id), [
                'reason' => 'September financial journals and earlier bank statement were independently reconciled.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->as($ctx, 'owner')->post(route('finance.controls.periods.review', [
                'period' => $period->id, 'action' => 'approve',
            ]), [
                'reason' => 'Approved original September close after independent source and bank review.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $originalHash = $period->fresh()->close_hash;
            $this->assertSame('closed', $period->fresh()->status);
            $this->as($ctx)->post(route('finance.bank.import'), [
                'bank_profile_id' => $bank->id, 'currency' => 'UGX',
                'period_from' => '2026-09-14', 'period_to' => '2026-09-14',
                'opening_balance' => '900', 'closing_balance' => '930',
                'file' => UploadedFile::fake()->createWithContent('discovered-september-return.csv',
                    "date,direction,amount,reference,description\n".
                    "2026-09-14,credit,30,LATE-SEP-RETURN,Return discovered after September reporting closed\n"),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $returnStatement = FinanceBankStatement::query()->where('business_id', $ctx['business']->id)
                ->latest('id')->firstOrFail();
            $returnLine = $returnStatement->lines()->firstOrFail();
            $source = [
                'return_bank_line_id' => $returnLine->id,
                'reason' => 'A bank return dated September was discovered after the formally approved September close.',
                'external_evidence' => 'Original reconciled September payment and late-bank-provided September return statement.',
            ];
            $this->as($ctx)->post(route('finance.transaction-reversals.prepare', [
                'type' => 'shipment_cost', 'id' => $cost->id,
            ]), $source)->assertSessionHasErrors('posting_on');
            $this->assertDatabaseCount('finance_partial_bank_returns', 0);
            $request = [
                'reason' => 'A previously omitted September partial bank credit changes the reported bank and expense balance.',
                'materiality_assessment' => 'Independent accountant will document September figures requiring restatement and statutory filing impacts.',
                'external_evidence' => 'The official bank record for 14 September and the prior approved original cash transaction can be inspected.',
            ];
            $this->post(route('finance.controls.periods.reopening.request', $period->id), $request)
                ->assertRedirect()->assertSessionHasNoErrors();
            $case = FinancePeriodReopenRequest::firstOrFail();
            $this->assertSame($originalHash, $case->original_close_hash);
            $this->assertSame('closed', $period->fresh()->status);
            $this->post(route('finance.controls.periods.reopening.review', [
                'case' => $case->id, 'decision' => 'approve',
            ]), [
                'notes' => 'The accountant who requested reopening is not permitted to approve it.',
            ])->assertForbidden();
            $this->as($ctx, 'owner')->post(route('finance.controls.periods.reopening.review', [
                'case' => $case->id, 'decision' => 'approve',
            ]), [
                'notes' => 'Independently approved reopening after checking the missed transaction and reporting impact.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('approved', $case->fresh()->status);
            $this->assertSame('open', $period->fresh()->status);
            $this->as($ctx)->post(route('finance.transaction-reversals.prepare', [
                'type' => 'shipment_cost', 'id' => $cost->id,
            ]), $source)->assertRedirect()->assertSessionHasNoErrors();
            $partial = FinancePartialBankReturn::firstOrFail();
            $this->reviewPost($ctx, 'partial_bank_return', $partial->id, null, '2026-09-14');
            $newJournal = FinanceJournal::query()->where('business_id', $ctx['business']->id)
                ->where('source_type', 'partial_bank_return')->firstOrFail();
            $this->assertSame('2026-09-14', $newJournal->posted_on->toDateString());
            $this->as($ctx)->post(route('finance.bank.match', [
                'statement' => $returnStatement->id, 'line' => $returnLine->id,
            ]), [
                'candidate' => 'partial_bank_return:'.$partial->id,
                'note' => 'Returned September cash appears against the independently approved correction.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $match = FinanceBankMatch::query()->where('business_id', $ctx['business']->id)
                ->where('statement_line_id', $returnLine->id)->firstOrFail();
            $this->as($ctx, 'owner')->post(route('finance.bank.confirm', $match->id), [
                'note' => 'The late-discovered cash item and reviewed partial return agree exactly.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->as($ctx, 'owner')->post(route('finance.bank.complete', $returnStatement->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->proof($ctx, $returnStatement);
            $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent(
                FinanceBankLedgerCheck::query()->where('statement_id', $original->id)
                    ->firstOrFail()));
            Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00', 'Africa/Kampala'));
            $correctClosing = $this->reconciledStatement($ctx, $bank, '930', '930', []);
            $this->proof($ctx, $correctClosing);
            Carbon::setTestNow($originalClock);
            $oldProof = FinanceBankLedgerCheck::where('statement_id', $oldClosing->id)->firstOrFail();
            $newProof = FinanceBankLedgerCheck::where('statement_id', $correctClosing->id)->firstOrFail();
            $this->as($ctx)->post(route('finance.bank.proof.supersede', $oldProof->id), ['replacement_id' => $newProof->id, 'notes' => 'Original preparer cannot approve replacement balance evidence.'])->assertForbidden();
            $this->as($ctx, 'owner')->post(route('finance.bank.proof.supersede', $oldProof->id), ['replacement_id' => $newProof->id, 'notes' => 'Independently checked the corrected bank-issued month-end balance after the late return was found.'])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertTrue(app(FinanceBankLedgerProofs::class)->isSuperseded($oldProof->fresh()));
            $this->as($ctx)->post(route('finance.controls.periods.submit', $period->id), [
                'reason' => 'Revised September trial balance and newly reconciled bank return were independently checked.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->as($ctx, 'owner')->post(route('finance.controls.periods.review', [
                'period' => $period->id, 'action' => 'approve',
            ]), [
                'reason' => 'Re-approve corrected September close after documented restatement and bank proof.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('closed', $period->fresh()->status);
            $this->assertNotSame($originalHash, $period->fresh()->close_hash);
            $this->assertSame($originalHash, $case->fresh()->original_close_hash);
            $this->assertDatabaseCount('finance_period_reopen_requests', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_closed_original_period_allows_later_partial_return_without_rewriting_history(): void
    {
        $clock = now();
        try {
            Carbon::setTestNow($clock->copy()->subDay());
            $ctx = $this->context();
            $bank = $this->bank($ctx, 'UGX');
            $this->localOpening($ctx, $bank, '1000');
            $cost = $this->postedCost($ctx, $bank, '100');
            $statement = $this->reconciledStatement($ctx, $bank, '1000', '900', [
                ['type' => 'shipment_cost', 'id' => $cost->id, 'amount' => '100', 'direction' => 'debit'],
            ]);
            $this->proof($ctx, $statement);
            $date = now()->toDateString();
            $this->as($ctx)->post(route('finance.controls.periods.store'), [
                'period_from' => $date, 'period_to' => $date,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $period = FinancePeriod::firstOrFail();
            $this->post(route('finance.controls.periods.submit', $period->id), [
                'reason' => 'Close original month/day after independent evidence and bank proof.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->as($ctx, 'owner')->post(route('finance.controls.periods.review', ['period' => $period->id, 'action' => 'approve']), [
                'reason' => 'Independently confirm the source and closed-period trial balance.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('closed', $period->fresh()->status);
            $originalJournal = FinanceJournal::query()->where('business_id', $ctx['business']->id)
                ->where('source_type', 'shipment_cost')->where('source_id', $cost->id)->firstOrFail();
            Carbon::setTestNow($clock);
            $today = now()->toDateString();
            $this->as($ctx)->post(route('finance.bank.import'), [
                'bank_profile_id' => $bank->id, 'currency' => 'UGX',
                'period_from' => $today, 'period_to' => $today,
                'opening_balance' => '900', 'closing_balance' => '930',
                'file' => UploadedFile::fake()->createWithContent('postclose-return.csv',
                    "date,direction,amount,reference,description\n".
                    "{$today},credit,30,POSTCLOSE-RETURN,Return paid after the original accounting period closed\n"),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $return = FinanceBankStatement::query()->where('business_id', $ctx['business']->id)
                ->orderByDesc('id')->firstOrFail()->lines()->firstOrFail();
            $this->post(route('finance.transaction-reversals.prepare', [
                'type' => 'shipment_cost', 'id' => $cost->id,
            ]), [
                'return_bank_line_id' => $return->id,
                'reason' => 'An externally evidenced partial bank refund occurred after the source period was closed.',
                'external_evidence' => 'Bank return took place in the currently open period, without changing original closed-period source entries.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $partial = FinancePartialBankReturn::firstOrFail();
            $this->reviewPost($ctx, 'partial_bank_return', $partial->id, null, $today);
            $this->assertSame('closed', $period->fresh()->status);
            $this->assertTrue($originalJournal->fresh()->lines()->exists());
            $newJournal = FinanceJournal::query()->where('business_id', $ctx['business']->id)
                ->where('source_type', 'partial_bank_return')->firstOrFail();
            $this->assertSame($today, $newJournal->posted_on->toDateString());
            $this->assertNotSame($originalJournal->id, $newJournal->id);
        } finally {
            Carbon::setTestNow();
        }
    }
}
