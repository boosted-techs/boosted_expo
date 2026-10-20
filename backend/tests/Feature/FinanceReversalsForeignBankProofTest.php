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
use App\Models\FinanceJournal;
use App\Models\FinancePayment;
use App\Models\FinancePeriod;
use App\Models\FinancePostingRequest;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceTransactionReversal;
use App\Models\InvoiceAccountingHandoff;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesRequest;
use App\Models\User;
use App\Services\FinanceBankLedgerProofs;
use App\Services\FinanceDocuments;
use App\Services\FinanceLedger;
use App\Services\FinanceSetup;
use App\Services\FinanceStatements;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceReversalsForeignBankProofTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $code='EXPO'):array
    {
        $business=Business::create([
            'name'=>$code.' Exporters','code'=>$code,'country_code'=>'UG',
            'base_currency'=>'UGX','timezone'=>'Africa/Kampala',
            'default_qr_level'=>'carton',
        ]);
        $owner=User::factory()->create();
        $accountant=User::factory()->create();
        $viewer=User::factory()->create();
        foreach([[$owner,'owner'],[$accountant,'accountant'],[$viewer,'viewer']] as [$user,$role]){
            BusinessMembership::create([
                'business_id'=>$business->id,'user_id'=>$user->id,
                'role'=>$role,'active'=>true,
            ]);
        }
        $this->actingAs($owner)->withSession(['active_business_id'=>$business->id])
            ->post(route('finance.setup'))->assertRedirect()->assertSessionHasNoErrors();
        $customer=Customer::create([
            'business_id'=>$business->id,'customer_code'=>'BUY-01','name'=>'Export Buyer',
            'country_code'=>'AE','currency'=>'USD','active'=>true,
        ]);
        $shipment=ExportShipment::create([
            'business_id'=>$business->id,'reference'=>'SHIP-'.Str::ulid(),
            'customer_id'=>$customer->id,'status'=>'dispatched','transport_mode'=>'air',
            'origin_country'=>'UG','destination_country'=>'AE',
            'created_by'=>$owner->id,
        ]);
        return compact('business','owner','accountant','viewer','customer','shipment');
    }

    private function as(array $ctx,string $role='accountant'):self
    {
        return $this->actingAs($ctx[$role])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ]);
    }

    private function account(array $ctx,string $key):FinanceAccount
    {
        return app(FinanceSetup::class)->account($ctx['business']->id,$key);
    }

    private function bank(array $ctx,string $currency='USD'):FinanceBankProfile
    {
        $this->as($ctx,'owner')->post(route('finance.controls.banks.store'),[
            'label'=>'Settlements '.$currency,'institution'=>'Demo Bank Ltd',
            'currency'=>$currency,'masked_reference'=>'****9876',
        ])->assertRedirect()->assertSessionHasNoErrors();
        return FinanceBankProfile::query()->where('business_id',$ctx['business']->id)
            ->latest('id')->firstOrFail();
    }

    private function fx(array $ctx,string $currency='USD',string $rate='3700'):FinanceExchangeRate
    {
        $this->as($ctx)->post(route('finance.rates.propose'),[
            'currency'=>$currency,'rate_to_base'=>$rate,
            'effective_on'=>now()->subDays(2)->toDateString(),
            'source_note'=>'Bank-supported historical rate evidence',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $record=FinanceExchangeRate::query()->where('business_id',$ctx['business']->id)
            ->latest('id')->firstOrFail();
        $this->as($ctx,'owner')->post(route('finance.rates.approve',$record->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        return $record->fresh();
    }

    private function reviewPost(array $ctx,string $type,int $id,?int $rateId=null,
        ?string $postingDate=null):FinanceJournal
    {
        $this->as($ctx)->post(route('finance.reviews.request',[
            'type'=>$type,'source'=>$id,
        ]),array_filter([
            'posting_on'=>$postingDate??now()->toDateString(),
            'exchange_rate_id'=>$rateId,
            'basis_notes'=>'Verifiable journal source vouchers, rates and approvals checked against company records.',
        ],fn($v)=>$v!==null))->assertRedirect()->assertSessionHasNoErrors();
        $review=FinancePostingRequest::query()->where('business_id',$ctx['business']->id)
            ->where('source_type',$type)->where('source_id',$id)->firstOrFail();
        $this->as($ctx,'owner')->post(route('finance.reviews.approve',$review->id),[
            'notes'=>'Independently reviewed original commercial source and rate documents.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->as($ctx)->post(route('finance.reviews.post',$review->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        return FinanceJournal::query()->where('business_id',$ctx['business']->id)
            ->where('source_type',$type)->where('source_id',$id)->firstOrFail();
    }

    private function foreignOpening(array $ctx,FinanceBankProfile $bank,
        FinanceExchangeRate $rate,string $amount='100'):FinanceForeignBankOpening
    {
        $date=now()->subDay()->toDateString();
        $this->as($ctx)->post(route('finance.controls.foreign-opening.store'),[
            'bank_profile_id'=>$bank->id,'currency'=>$bank->currency,
            'foreign_amount'=>$amount,'exchange_rate_id'=>$rate->id,
            'effective_on'=>$date,
            'bank_evidence'=>'Verified original foreign bank statement opening and independently approved rate.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $opening=FinanceForeignBankOpening::query()->where('business_id',$ctx['business']->id)
            ->firstOrFail();
        $this->reviewPost($ctx,'foreign_bank_opening',$opening->id,$rate->id,$date);
        return $opening->fresh();
    }

    private function localOpening(array $ctx,FinanceBankProfile $bank,string $amount='1000'):void
    {
        $date=now()->subDay()->toDateString();
        $this->as($ctx)->post(route('finance.controls.opening.store'),[
            'effective_on'=>$date,'lines'=>[
                ['account_id'=>$bank->account_id,'debit'=>$amount,'credit'=>'0'],
                ['account_id'=>$this->account($ctx,'opening_equity')->id,
                    'debit'=>'0','credit'=>$amount],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $opening=\App\Models\FinanceOpeningBalance::query()
            ->where('business_id',$ctx['business']->id)->firstOrFail();
        $this->reviewPost($ctx,'opening_balance',$opening->id,null,$date);
    }

    private function postedCost(array $ctx,FinanceBankProfile $bank,
        string $amount='20',?FinanceExchangeRate $rate=null):FinanceShipmentCost
    {
        $cost=FinanceShipmentCost::create([
            'business_id'=>$ctx['business']->id,
            'shipment_id'=>$ctx['shipment']->id,'reference'=>'COST-'.Str::ulid(),
            'category'=>'handling','description'=>'Airport handling transfer',
            'currency'=>$bank->currency,'amount'=>$amount,
            'cash_account_id'=>$bank->account_id,'paid_on'=>now()->toDateString(),
            'external_reference'=>'BANK-COST-'.Str::ulid(),
            'status'=>'pending','created_by'=>$ctx['accountant']->id,
        ]);
        $this->reviewPost($ctx,'shipment_cost',$cost->id,$rate?->id);
        return $cost->fresh();
    }

    private function reconciledStatement(array $ctx,FinanceBankProfile $bank,
        string $opening,string $closing,array $matches):FinanceBankStatement
    {
        $today=now()->toDateString();
        $csv="date,direction,amount,reference,description\n";
        foreach($matches as $n=>$m) {
            $csv.=$today.",".$m['direction'].",".$m['amount'].",BANK-".($n+1).
                ",Independent transaction reference ".($n+1)."\n";
        }
        $this->as($ctx)->post(route('finance.bank.import'),[
            'currency'=>$bank->currency,'bank_profile_id'=>$bank->id,
            'period_from'=>$today,'period_to'=>$today,
            'opening_balance'=>$opening,'closing_balance'=>$closing,
            'file'=>UploadedFile::fake()->createWithContent('ledger-bank.csv',$csv),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $statement=FinanceBankStatement::query()->where('business_id',$ctx['business']->id)
            ->latest('id')->firstOrFail();
        $lines=$statement->lines()->orderBy('line_number')->get();
        foreach($matches as $n=>$m) {
            $line=$lines[$n];
            $this->as($ctx)->post(route('finance.bank.match',[
                'statement'=>$statement->id,'line'=>$line->id,
            ]),[
                'candidate'=>$m['type'].':'.$m['id'],
                'note'=>'Manually matched the bank reference, amount and company ledger account.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $match=FinanceBankMatch::query()->where('business_id',$ctx['business']->id)
                ->where('statement_line_id',$line->id)->firstOrFail();
            $this->as($ctx,'owner')->post(route('finance.bank.confirm',$match->id),[
                'note'=>'Independently checked transaction, bank reference, currency and amount.',
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->as($ctx,'owner')->post(route('finance.bank.complete',$statement->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reconciled',$statement->fresh()->status);
        return $statement->fresh();
    }

    private function proof(array $ctx,FinanceBankStatement $statement):FinanceBankLedgerCheck
    {
        $this->as($ctx)->post(route('finance.bank.proof.submit',$statement->id),[
            'notes'=>'Source account history and statement opening, movements and closing were matched.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $check=FinanceBankLedgerCheck::query()->where('business_id',$ctx['business']->id)
            ->where('statement_id',$statement->id)->firstOrFail();
        $this->as($ctx,'owner')->post(route('finance.bank.proof.review',[
            'check'=>$check->id,'decision'=>'approve',
        ]),['notes'=>'Confirmed independent bank statement and original-currency ledger agreement.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        return $check->fresh();
    }

    public function test_historical_foreign_bank_opening_and_independent_statement_book_proof():void
    {
        $ctx=$this->context();
        $bank=$this->bank($ctx,'USD');$rate=$this->fx($ctx);
        $opening=$this->foreignOpening($ctx,$bank,$rate,'100');
        $this->assertSame('posted',$opening->posting_status);
        $this->assertTrue(BigDecimal::of((string)$opening->base_amount)->isEqualTo('370000'));
        $openingJournal=FinanceJournal::query()->where('business_id',$ctx['business']->id)
            ->where('source_type','foreign_bank_opening')->firstOrFail();
        $this->assertTrue(BigDecimal::of((string)$openingJournal->rate_to_base)->isEqualTo('3700'));

        $cost=$this->postedCost($ctx,$bank,'20',$rate);
        $statement=$this->reconciledStatement($ctx,$bank,'100','80',[
            ['type'=>'shipment_cost','id'=>$cost->id,'amount'=>'20.00','direction'=>'debit'],
        ]);
        $preview=app(FinanceBankLedgerProofs::class)->preview($statement);
        $this->assertTrue($preview['opening_matches'] && $preview['closing_matches']
            && $preview['movement_matches']);
        $this->assertSame('80.00',$preview['book_closing']);
        $this->assertSame('296000.00',$preview['book_base_closing']);
        $this->as($ctx)->get(route('finance.bank.show',$statement->id))
            ->assertOk()->assertSee('Independent bank-to-ledger balance proof');
        $check=$this->proof($ctx,$statement);
        $this->assertSame('verified',$check->status);
        $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent($check));
        $this->assertDatabaseCount('finance_bank_ledger_check_events',2);
        $this->as($ctx)->post(route('finance.controls.periods.store'),[
            'period_from'=>now()->toDateString(),'period_to'=>now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $period=FinancePeriod::firstOrFail();
        $this->post(route('finance.controls.periods.submit',$period->id),[
            'reason'=>'Journal and verified bank-to-ledger figures agree as at close.',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_matched_statement_without_opening_fails_book_proof_and_is_not_bank_verified():void
    {
        $ctx=$this->context();
        $bank=$this->bank($ctx,'USD');$rate=$this->fx($ctx);
        $cost=$this->postedCost($ctx,$bank,'20',$rate);
        $statement=$this->reconciledStatement($ctx,$bank,'100','80',[
            ['type'=>'shipment_cost','id'=>$cost->id,'amount'=>'20','direction'=>'debit'],
        ]);
        $preview=app(FinanceBankLedgerProofs::class)->preview($statement);
        $this->assertSame('0.00',$preview['book_opening']);
        $this->assertSame('-20.00',$preview['book_closing']);
        $this->assertFalse($preview['opening_matches']);
        $this->as($ctx)->post(route('finance.bank.proof.submit',$statement->id),[
            'notes'=>'Unable to substantiate this bank opening and closing with posted balances.',
        ])->assertSessionHasErrors('statement');
        $this->assertDatabaseCount('finance_bank_ledger_checks',0);
    }

    public function test_payment_reversal_preserves_history_and_later_bank_matching_is_possible():void
    {
        $ctx=$this->context();
        $bank=$this->bank($ctx,'UGX');
        $this->localOpening($ctx,$bank,'1000');
        $cost=$this->postedCost($ctx,$bank,'100');
        $original=FinanceJournal::query()->where('business_id',$ctx['business']->id)
            ->where('source_type','shipment_cost')->where('source_id',$cost->id)->firstOrFail();
        $this->as($ctx)->post(route('finance.transaction-reversals.prepare',[
            'type'=>'shipment_cost','id'=>$cost->id,
        ]),[
            'reason'=>'The posted shipment handling payment was corrected by a documented counter-transaction.',
            'external_evidence'=>'Original transfer and incoming counter-entry are in the attached verified cash record.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $reversal=FinanceTransactionReversal::query()->where('business_id',$ctx['business']->id)
            ->firstOrFail();
        $this->assertSame('not_posted',$reversal->posting_status);
        $this->assertSame('posted',$cost->fresh()->status);
        $this->reviewPost($ctx,'transaction_reversal',$reversal->id);
        $this->assertSame('reversed',$cost->fresh()->status);
        $this->assertSame('posted',$reversal->fresh()->posting_status);
        $this->assertSame(2,$original->lines()->count());
        $report=app(FinanceStatements::class)->report($ctx['business'],
            now()->subDay()->toDateString(),now()->toDateString());
        $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo('1000'));
        $this->assertTrue(BigDecimal::of($report['expense'])->isZero());
        $this->assertTrue(BigDecimal::of($report['balance_variance'])->isZero());
        $statement=$this->reconciledStatement($ctx,$bank,'1000','1000',[
            ['type'=>'shipment_cost','id'=>$cost->id,'amount'=>'100','direction'=>'debit'],
            ['type'=>'transaction_reversal','id'=>$reversal->id,'amount'=>'100','direction'=>'credit'],
        ]);
        $this->assertTrue(app(FinanceBankLedgerProofs::class)->preview($statement)['movement_matches']);
        $this->proof($ctx,$statement);
    }

    public function test_active_bank_match_blocks_reversal_and_cross_company_references():void
    {
        $ctx=$this->context();
        $bank=$this->bank($ctx,'UGX');$this->localOpening($ctx,$bank,'1000');
        $cost=$this->postedCost($ctx,$bank,'100');
        $today=now()->toDateString();
        $this->as($ctx)->post(route('finance.bank.import'),[
            'currency'=>'UGX','bank_profile_id'=>$bank->id,
            'period_from'=>$today,'period_to'=>$today,'opening_balance'=>'1000',
            'closing_balance'=>'900','file'=>UploadedFile::fake()->createWithContent(
                'matched.csv',"date,direction,amount,reference,description\n{$today},debit,100,BANK-100,Handling payment\n"
            ),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $statement=FinanceBankStatement::firstOrFail();
        $line=$statement->lines()->firstOrFail();
        $this->post(route('finance.bank.match',[
            'statement'=>$statement->id,'line'=>$line->id,
        ]),[
            'candidate'=>'shipment_cost:'.$cost->id,
            'note'=>'Original transaction is already awaiting bank match confirmation',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $payload=[
            'reason'=>'An erroneous cost payment requires a separate formal reversing journal.',
            'external_evidence'=>'The original recorded transfer is now already covered by an active bank match.',
        ];
        $this->post(route('finance.transaction-reversals.prepare',[
            'type'=>'shipment_cost','id'=>$cost->id,
        ]),$payload)->assertSessionHasErrors('reversal');
        $this->assertDatabaseCount('finance_transaction_reversals',0);
        $this->as($ctx,'owner')->post(route('finance.bank.confirm',
            FinanceBankMatch::firstOrFail()->id),[
            'note'=>'Original bank transfer independently matched and confirmed.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->as($ctx)->post(route('finance.transaction-reversals.prepare',[
            'type'=>'shipment_cost','id'=>$cost->id,
        ]),$payload)->assertSessionHasErrors('reversal');
        $other=$this->context('OTHER');
        $this->as($other)->post(route('finance.transaction-reversals.prepare',[
            'type'=>'shipment_cost','id'=>$cost->id,
        ]),$payload)->assertNotFound();
        $this->as($other)->get(route('finance.bank.show',$statement->id))->assertNotFound();
    }

    public function test_new_bank_journal_stales_verified_statement_and_blocks_period_close():void
    {
        $ctx=$this->context();
        $bank=$this->bank($ctx,'USD');$rate=$this->fx($ctx);
        $this->foreignOpening($ctx,$bank,$rate,'100');
        $cost=$this->postedCost($ctx,$bank,'20',$rate);
        $statement=$this->reconciledStatement($ctx,$bank,'100','80',[
            ['type'=>'shipment_cost','id'=>$cost->id,'amount'=>'20','direction'=>'debit'],
        ]);
        $check=$this->proof($ctx,$statement);
        $this->assertTrue(app(FinanceBankLedgerProofs::class)->isCurrent($check));
        $this->postedCost($ctx,$bank,'5',$rate);
        $this->assertFalse(app(FinanceBankLedgerProofs::class)->isCurrent($check->fresh()));
        $this->as($ctx)->get(route('finance.bank.show',$statement->id))
            ->assertOk()->assertSee('OUTDATED PROOF');
        $this->post(route('finance.bank.proof.submit',$statement->id),[
            'notes'=>'The new posted bank expense makes the historic statement proof stale.',
        ])->assertSessionHasErrors('statement');
        $this->post(route('finance.controls.periods.store'),[
            'period_from'=>now()->toDateString(),'period_to'=>now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $period=FinancePeriod::firstOrFail();
        $this->post(route('finance.controls.periods.submit',$period->id),[
            'reason'=>'Must reject closing because a new bank movement is not verified.',
        ])->assertSessionHasErrors('period');
        $this->assertSame('open',$period->fresh()->status);
    }

    public function test_customer_payment_correction_restores_receivable_and_reverses_fx_gain():void
    {
        $ctx=$this->context();
        $bank=$this->bank($ctx,'USD');
        $rfq=SalesRequest::create([
            'business_id'=>$ctx['business']->id,'customer_id'=>$ctx['customer']->id,
            'reference'=>'RFQ-'.Str::ulid(),'status'=>'confirmed','created_by'=>$ctx['owner']->id,
        ]);
        $quote=SalesQuotation::create([
            'business_id'=>$ctx['business']->id,'sales_request_id'=>$rfq->id,
            'reference'=>'QUOTE-'.Str::ulid(),'revision'=>1,'status'=>'accepted',
            'currency'=>'USD','subtotal'=>'100','discount_amount'=>'0',
            'tax_amount'=>'0','grand_total'=>'100','created_by'=>$ctx['owner']->id,
        ]);
        $order=SalesOrder::create([
            'business_id'=>$ctx['business']->id,'reference'=>'ORDER-'.Str::ulid(),
            'sales_request_id'=>$rfq->id,'sales_quotation_id'=>$quote->id,
            'customer_id'=>$ctx['customer']->id,'status'=>'confirmed','currency'=>'USD',
            'subtotal'=>'100','discount_amount'=>'0','tax_amount'=>'0','grand_total'=>'100',
            'buyer_confirmation'=>'Signed original commercial quotation',
            'confirmed_by'=>$ctx['owner']->id,'confirmed_at'=>now(),
        ]);
        $ctx['shipment']->update(['sales_order_id'=>$order->id]);
        $invoice=CommercialInvoice::create([
            'business_id'=>$ctx['business']->id,'reference'=>'CI-'.Str::ulid(),
            'shipment_id'=>$ctx['shipment']->id,'sales_order_id'=>$order->id,
            'customer_id'=>$ctx['customer']->id,
            'currency'=>'USD','subtotal'=>'100','discount_amount'=>'0',
            'tax_amount'=>'0','freight_amount'=>'0','insurance_amount'=>'0',
            'grand_total'=>'100','posting_status'=>'posted',
            'data_snapshot'=>[],'html_snapshot'=>'<h1>Original buyer invoice</h1>',
            'sha256'=>hash('sha256','<h1>Original buyer invoice</h1>'),
            'issued_by'=>$ctx['owner']->id,'issued_at'=>now(),
        ]);
        InvoiceAccountingHandoff::create([
            'business_id'=>$ctx['business']->id,'commercial_invoice_id'=>$invoice->id,
            'currency'=>'USD','commercial_amount'=>'100','posting_status'=>'posted',
            'prepared_at'=>now(),'details'=>[],
        ]);
        $ledger=app(FinanceLedger::class);
        $ledger->record($ctx['business'],'commercial_invoice',$invoice->id,
            'USD','3600',now()->toDateString(),'Original customer receivable',
            $ctx['accountant']->id,$ctx['owner']->id,[
                $ledger->line('receivable','100','0','360000','0'),
                $ledger->line('export_sales','0','100','0','360000'),
            ]);
        $payment=FinancePayment::create([
            'business_id'=>$ctx['business']->id,'reference'=>'PAY-'.Str::ulid(),
            'direction'=>'in','commercial_invoice_id'=>$invoice->id,
            'cash_account_id'=>$bank->account_id,'currency'=>'USD','amount'=>'40',
            'settled_base_amount'=>'144000',
            'payment_on'=>now()->toDateString(),
            'external_reference'=>'BANK-PAY-BUYER-100','status'=>'posted',
            'idempotency_key'=>(string)Str::uuid(),'posted_at'=>now(),
            'created_by'=>$ctx['accountant']->id,
        ]);
        $ledger->record($ctx['business'],'payment',$payment->id,'USD','3700',
            now()->toDateString(),'Original 40 USD customer settlement',
            $ctx['accountant']->id,$ctx['owner']->id,[
                $ledger->line($bank->account->system_key,'40','0','148000','0'),
                $ledger->line('receivable','0','40','0','144000'),
                $ledger->line('fx_gain','0','0','0','4000'),
            ]);
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice)->isEqualTo('60'));
        $this->as($ctx)->post(route('finance.transaction-reversals.prepare',[
            'type'=>'payment','id'=>$payment->id,
        ]),[
            'reason'=>'The buyer receipt was recorded in error and the original transfer never actually settled.',
            'external_evidence'=>'Independent bank evidence shows no settled transfer for this buyer receipt reference.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $reversal=FinanceTransactionReversal::firstOrFail();
        $this->reviewPost($ctx,'transaction_reversal',$reversal->id);
        $this->assertSame('reversed',$payment->fresh()->status);
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice)->isEqualTo('100'));
        $this->assertDatabaseCount('finance_journals',3);
        $report=app(FinanceStatements::class)->report($ctx['business'],
            now()->startOfMonth()->toDateString(),now()->toDateString());
        $this->assertTrue(BigDecimal::of($report['income'])->isEqualTo('360000'));
        $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo('360000'));
        $this->assertTrue(BigDecimal::of($report['balance_variance'])->isZero());
        $this->assertTrue($invoice->fresh()->posting_status==='posted');
    }

    public function test_reversal_evidence_tampering_is_rejected_without_journal():void
    {
        $ctx=$this->context();
        $bank=$this->bank($ctx,'UGX');
        $this->localOpening($ctx,$bank,'1000');
        $cost=$this->postedCost($ctx,$bank,'50');
        $this->as($ctx)->post(route('finance.transaction-reversals.prepare',[
            'type'=>'shipment_cost','id'=>$cost->id,
        ]),[
            'reason'=>'The paid shipment fee needs an evidence-backed accounting correction.',
            'external_evidence'=>'Original bank receipt was voided and a replacement entry was approved.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $correction=FinanceTransactionReversal::firstOrFail();
        $correction->update(['external_evidence'=>'Unapproved altered evidence after preparation']);
        $this->post(route('finance.reviews.request',[
            'type'=>'transaction_reversal','source'=>$correction->id,
        ]),[
            'posting_on'=>now()->toDateString(),
            'basis_notes'=>'Financial evidence must be identical to the prepared correction source.',
        ])->assertStatus(422);
        $this->assertSame('posted',$cost->fresh()->status);
        $this->assertDatabaseCount('finance_journals',2);
    }

    public function test_foreign_opening_cannot_overlap_existing_bank_movements():void
    {
        $ctx=$this->context();
        $bank=$this->bank($ctx,'USD');$rate=$this->fx($ctx);
        $this->postedCost($ctx,$bank,'20',$rate);
        $this->as($ctx)->post(route('finance.controls.foreign-opening.store'),[
            'bank_profile_id'=>$bank->id,'currency'=>'USD',
            'foreign_amount'=>'100','exchange_rate_id'=>$rate->id,
            'effective_on'=>now()->subDay()->toDateString(),
            'bank_evidence'=>'Source bank opening documented only after an existing posted bank debit.',
        ])->assertSessionHasErrors('bank_profile_id');
        $this->assertDatabaseCount('finance_foreign_bank_openings',0);
        $this->as($ctx,'viewer')->post(route('finance.controls.foreign-opening.store'),[
            'bank_profile_id'=>$bank->id,'currency'=>'USD',
            'foreign_amount'=>'100','exchange_rate_id'=>$rate->id,
            'effective_on'=>now()->subDay()->toDateString(),
            'bank_evidence'=>'A general viewer must not create opening financial balances.',
        ])->assertForbidden();
    }
}
