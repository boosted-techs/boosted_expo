<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinancePayment;
use App\Models\FinancePostingRequest;
use App\Models\FinanceSupplierBill;
use App\Models\InvoiceAccountingHandoff;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\GoodsReceipt;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesQuotation;
use App\Models\SalesRequest;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancePostingTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code='ONE'):array
    {
        $business=Business::create([
            'name'=>$code.' Exporters','code'=>$code,'country_code'=>'UG','base_currency'=>'UGX',
            'timezone'=>'Africa/Kampala','default_qr_level'=>'carton',
        ]);
        $owner=User::factory()->create();
        $accountant=User::factory()->create();
        foreach([[$owner,'owner'],[$accountant,'accountant']] as [$user,$role]) {
            BusinessMembership::create([
                'business_id'=>$business->id,'user_id'=>$user->id,
                'role'=>$role,'active'=>true,
            ]);
        }
        $customer=Customer::create([
            'business_id'=>$business->id,'customer_code'=>'BUY-1','name'=>'Ugandan Fresh Buyer',
            'country_code'=>'AE','currency'=>'USD','active'=>true,
        ]);
        $product=Product::create([
            'business_id'=>$business->id,'sku'=>'AV-100','name'=>'Fresh avocado',
            'base_unit'=>'kg','active'=>true,
        ]);
        $this->actingAs($owner)->post(route('finance.setup'))->assertRedirect();
        return compact('business','owner','accountant','customer','product');
    }

    private function rate(array $ctx,string $currency,string $rate,int $daysBack):FinanceExchangeRate
    {
        $this->actingAs($ctx['accountant'])->post(route('finance.rates.propose'),[
            'currency'=>$currency,'rate_to_base'=>$rate,
            'effective_on'=>now()->subDays($daysBack)->toDateString(),
            'source_note'=>'Bank statement FX quote '.Str::ulid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $record=FinanceExchangeRate::query()->where('business_id',$ctx['business']->id)
            ->orderByDesc('id')->firstOrFail();
        $this->actingAs($ctx['owner'])->post(route('finance.rates.approve',$record->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        return $record->refresh();
    }

    private function invoice(array $ctx,string $amount='100.00',string $currency='USD'):CommercialInvoice
    {
        $rfq=SalesRequest::create([
            'business_id'=>$ctx['business']->id,'customer_id'=>$ctx['customer']->id,
            'reference'=>'CRQ-'.Str::ulid(),'created_by'=>$ctx['owner']->id,
            'status'=>'confirmed',
        ]);
        $quote=SalesQuotation::create([
            'business_id'=>$ctx['business']->id,'sales_request_id'=>$rfq->id,
            'reference'=>'Q-'.Str::ulid(),'revision'=>1,'status'=>'accepted',
            'currency'=>$currency,'subtotal'=>$amount,'discount_amount'=>0,
            'tax_amount'=>0,'grand_total'=>$amount,'created_by'=>$ctx['owner']->id,
            'issued_at'=>now()->subDay(),
        ]);
        $order=SalesOrder::create([
            'business_id'=>$ctx['business']->id,'reference'=>'SO-'.Str::ulid(),
            'sales_request_id'=>$rfq->id,'sales_quotation_id'=>$quote->id,
            'customer_id'=>$ctx['customer']->id,'status'=>'confirmed',
            'currency'=>$currency,'subtotal'=>$amount,'discount_amount'=>0,
            'tax_amount'=>0,'grand_total'=>$amount,'buyer_confirmation'=>'Buyer email reference',
            'confirmed_by'=>$ctx['owner']->id,'confirmed_at'=>now()->subDay(),
        ]);
        SalesOrderLine::create([
            'business_id'=>$ctx['business']->id,'sales_order_id'=>$order->id,
            'product_id'=>$ctx['product']->id,'quantity'=>100,
            'unit_price'=>1,'line_total'=>$amount,
        ]);
        $shipment=ExportShipment::create([
            'business_id'=>$ctx['business']->id,'reference'=>'SHP-'.Str::ulid(),
            'customer_id'=>$ctx['customer']->id,'sales_order_id'=>$order->id,
            'status'=>'dispatched','transport_mode'=>'air',
            'origin_country'=>'UG','destination_country'=>'AE',
            'created_by'=>$ctx['owner']->id,
        ]);
        $invoice=CommercialInvoice::create([
            'business_id'=>$ctx['business']->id,'shipment_id'=>$shipment->id,
            'sales_order_id'=>$order->id,'customer_id'=>$ctx['customer']->id,
            'reference'=>'CI-'.Str::ulid(),'currency'=>$currency,
            'subtotal'=>$amount,'discount_amount'=>'0','tax_amount'=>'0',
            'freight_amount'=>'0','insurance_amount'=>'0','grand_total'=>$amount,
            'posting_status'=>'not_posted','data_snapshot'=>[],
            'html_snapshot'=>'<h1>Invoice</h1>','sha256'=>hash('sha256','<h1>Invoice</h1>'),
            'issued_by'=>$ctx['owner']->id,'issued_at'=>now()->subDay(),
        ]);
        InvoiceAccountingHandoff::create([
            'business_id'=>$ctx['business']->id,'commercial_invoice_id'=>$invoice->id,
            'currency'=>$currency,'commercial_amount'=>$amount,'posting_status'=>'awaiting_accounting_policy',
            'prepared_at'=>now(),'details'=>['no_journal_entry_created'=>true],
        ]);
        return $invoice;
    }

    private function approveAndPost(array $ctx,string $type,int $sourceId,?FinanceExchangeRate $rate=null,
        int $requesterIndex=0):FinanceJournal
    {
        $accountant=$ctx['accountant'];$owner=$ctx['owner'];
        $data=[
            'posting_on'=>now()->toDateString(),
            'basis_notes'=>'Verified supporting reference, contractual transfer evidence, and amount for this posting.',
        ];
        if($rate) $data['exchange_rate_id']=$rate->id;
        $this->actingAs($accountant)->post(route('finance.reviews.request',[
            'type'=>$type,'source'=>$sourceId,
        ]),$data)->assertRedirect()->assertSessionHasNoErrors();
        $review=FinancePostingRequest::query()->where('business_id',$ctx['business']->id)
            ->where('source_type',$type)->where('source_id',$sourceId)->firstOrFail();
        $this->post(route('finance.reviews.approve',$review->id),[
            'notes'=>'I approved independently after checking all evidence.',
        ])->assertForbidden();
        $this->actingAs($owner)->post(route('finance.reviews.approve',$review->id),[
            'notes'=>'Independent review approved with attached source evidence.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.reviews.post',$review->id))->assertStatus(422);
        $this->actingAs($accountant)->post(route('finance.reviews.post',$review->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('posted',$review->fresh()->status);
        return FinanceJournal::query()->where('business_id',$ctx['business']->id)
            ->where('source_type',$type)->where('source_id',$sourceId)->firstOrFail();
    }

    private function assertBalanced(FinanceJournal $journal):void
    {
        $lines=$journal->lines;
        $dr=BigDecimal::zero();$cr=BigDecimal::zero();
        $bdr=BigDecimal::zero();$bcr=BigDecimal::zero();
        foreach($lines as $line) {
            $dr=$dr->plus($line->debit);$cr=$cr->plus($line->credit);
            $bdr=$bdr->plus($line->base_debit);$bcr=$bcr->plus($line->base_credit);
        }
        $this->assertTrue($dr->isEqualTo($cr),'Document debits equal credits');
        $this->assertTrue($bdr->isEqualTo($bcr),'Base-currency debits equal credits');
        $this->assertTrue($dr->isGreaterThan(0));
    }

    public function test_invoice_recognition_is_reviewed_balanced_and_immutable():void
    {
        $ctx=$this->company();
        $this->assertDatabaseCount('finance_accounts',18);
        $fx=$this->rate($ctx,'USD','3600.0000000000',2);
        $invoice=$this->invoice($ctx);
        $this->actingAs($ctx['accountant'])->get(route('finance.index'))->assertOk()
            ->assertSee('Trial balance');
        $this->assertDatabaseCount('finance_journals',0);
        $journal=$this->approveAndPost($ctx,'commercial_invoice',$invoice->id,$fx);
        $this->assertBalanced($journal);
        $this->assertSame('UGX',$journal->base_currency);
        $this->assertSame('USD',$journal->currency);
        $this->assertSame('posted',$invoice->fresh()->posting_status);
        $this->assertSame('posted',$invoice->accountingHandoff->fresh()->posting_status);
        $this->assertDatabaseCount('finance_journals',1);
        $this->assertDatabaseCount('finance_journal_lines',2);
        $this->get(route('finance.journal',$journal->id))->assertOk()
            ->assertSee('360000.00');
        $this->get(route('finance.index'))->assertOk()->assertSee('360000.00');
        $review=FinancePostingRequest::where('source_type','commercial_invoice')->firstOrFail();
        $this->post(route('finance.reviews.post',$review->id))->assertStatus(422);
        $this->assertDatabaseCount('finance_journals',1);
    }

    public function test_partial_payments_realise_gain_then_loss_without_double_allocation():void
    {
        $ctx=$this->company();
        $initial=$this->rate($ctx,'USD','3600',3);
        $higher=$this->rate($ctx,'USD','3650',2);
        $lower=$this->rate($ctx,'USD','3500',1);
        $invoice=$this->invoice($ctx,'100.00');
        $this->approveAndPost($ctx,'commercial_invoice',$invoice->id,$initial);
        $first=$this->actingAs($ctx['accountant'])->post(route('finance.payments.store'),[
            'direction'=>'in','commercial_invoice_id'=>$invoice->id,'cash_account_key'=>'bank',
            'amount'=>'60.00','payment_on'=>now()->toDateString(),
            'external_reference'=>'BANK-RECEIPT-1','idempotency_key'=>(string)Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $payment1=FinancePayment::firstOrFail();
        $j1=$this->approveAndPost($ctx,'payment',$payment1->id,$higher);
        $this->assertBalanced($j1);
        $this->assertTrue(BigDecimal::of((string)$payment1->fresh()->settled_base_amount)->isEqualTo('216000.00'));
        $this->assertTrue(BigDecimal::of((string)$j1->lines()->whereHas('account',
            fn($q)=>$q->where('system_key','fx_gain'))->firstOrFail()->base_credit)->isEqualTo('3000.00'));

        $this->actingAs($ctx['accountant'])->post(route('finance.payments.store'),[
            'direction'=>'in','commercial_invoice_id'=>$invoice->id,'cash_account_key'=>'bank',
            'amount'=>'41.00','payment_on'=>now()->toDateString(),
            'external_reference'=>'BANK-OVER-ALLOCATION','idempotency_key'=>(string)Str::uuid(),
        ])->assertSessionHasErrors('amount');
        $this->post(route('finance.payments.store'),[
            'direction'=>'in','commercial_invoice_id'=>$invoice->id,'cash_account_key'=>'bank',
            'amount'=>'40.00','payment_on'=>now()->toDateString(),
            'external_reference'=>'BANK-RECEIPT-2','idempotency_key'=>(string)Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $payment2=FinancePayment::query()->orderByDesc('id')->firstOrFail();
        $j2=$this->approveAndPost($ctx,'payment',$payment2->id,$lower);
        $this->assertBalanced($j2);
        $this->assertTrue(BigDecimal::of((string)$j2->lines()->whereHas('account',
            fn($q)=>$q->where('system_key','fx_loss'))->firstOrFail()->base_debit)->isEqualTo('4000.00'));
        $this->assertTrue(app(\App\Services\FinanceDocuments::class)
            ->outstanding($invoice,false)->isEqualTo(0));
        $this->assertDatabaseCount('finance_payments',2);
        $this->assertDatabaseCount('finance_journals',3);
        $this->assertDatabaseCount('finance_journal_lines',8);
    }

    public function test_bill_uses_accepted_not_rejected_produce_and_waits_for_review():void
    {
        $ctx=$this->company();
        $purchase=PurchaseOrder::create([
            'business_id'=>$ctx['business']->id,'reference'=>'PO-'.Str::ulid(),
            'kind'=>'market','product_id'=>$ctx['product']->id,'vendor_name'=>'Market seller',
            'purchased_on'=>now()->toDateString(),'quantity'=>'50','unit_price'=>'2000',
            'estimated_total'=>'100000','currency'=>'UGX','status'=>'received',
            'idempotency_key'=>(string)Str::uuid(),'created_by'=>$ctx['owner']->id,
        ]);
        GoodsReceipt::create([
            'business_id'=>$ctx['business']->id,'reference'=>'GR-'.Str::ulid(),
            'purchase_order_id'=>$purchase->id,'received_quantity'=>'50',
            'accepted_quantity'=>'40','rejected_quantity'=>'10',
            'received_on'=>now()->toDateString(),
            'idempotency_key'=>(string)Str::uuid(),'posted_by'=>$ctx['owner']->id,
        ]);
        $this->actingAs($ctx['accountant'])->post(route('finance.bills.store',$purchase->id),[
            'vendor_invoice_reference'=>'VEND-100','vendor_invoice_date'=>now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $bill=FinanceSupplierBill::firstOrFail();
        $this->assertTrue(BigDecimal::of((string)$bill->total)->isEqualTo('80000.00'));
        $this->assertDatabaseCount('finance_journals',0);
        $journal=$this->approveAndPost($ctx,'supplier_bill',$bill->id);
        $this->assertBalanced($journal);
        $this->assertSame('posted',$bill->fresh()->posting_status);
        $this->assertDatabaseCount('finance_journal_lines',2);
        $this->post(route('finance.bills.store',$purchase->id),[
            'vendor_invoice_reference'=>'DUP-BILL','vendor_invoice_date'=>now()->toDateString(),
        ])->assertStatus(422);
        $this->actingAs($ctx['accountant'])->post(route('finance.payments.store'),[
            'direction'=>'out','supplier_bill_id'=>$bill->id,'cash_account_key'=>'cash',
            'amount'=>'80000','payment_on'=>now()->toDateString(),
            'external_reference'=>'CASH-VOUCHER-100','idempotency_key'=>(string)Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $payment=FinancePayment::firstOrFail();
        $paid=$this->approveAndPost($ctx,'payment',$payment->id);
        $this->assertBalanced($paid);
        $this->assertTrue(app(\App\Services\FinanceDocuments::class)
            ->outstanding($bill,false)->isEqualTo(0));
        $this->assertDatabaseCount('finance_journals',2);
    }

    public function test_shipment_costs_and_cross_business_permissions():void
    {
        $ctx=$this->company();
        $invoice=$this->invoice($ctx);
        $shipment=$invoice->shipment;
        $this->actingAs($ctx['accountant'])->post(route('finance.costs.store'),[
            'shipment_id'=>$shipment->id,'category'=>'handling','description'=>'Airport cargo handling',
            'currency'=>'UGX','amount'=>'125000','cash_account_key'=>'bank',
            'paid_on'=>now()->toDateString(),'external_reference'=>'BANK-COST-302',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $cost=\App\Models\FinanceShipmentCost::firstOrFail();
        $journal=$this->approveAndPost($ctx,'shipment_cost',$cost->id);
        $this->assertBalanced($journal);
        $this->assertDatabaseCount('finance_journals',1);
        $other=$this->company('TWO');
        $this->actingAs($other['accountant'])->withSession(['active_business_id'=>$other['business']->id])
            ->get(route('finance.journal',$journal->id))->assertNotFound();
        $this->post(route('finance.reviews.request',[
            'type'=>'commercial_invoice','source'=>$invoice->id,
        ]),[
            'posting_on'=>now()->toDateString(),
            'basis_notes'=>'Trying to post invoice owned by another company.',
        ])->assertNotFound();
        $this->get(route('finance.index'))->assertOk()->assertDontSee('125000');
        $viewer=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$ctx['business']->id,'user_id'=>$viewer->id,
            'role'=>'viewer','active'=>true,
        ]);
        $this->actingAs($viewer)->withSession(['active_business_id'=>$ctx['business']->id])
            ->get(route('finance.index'))->assertForbidden();
        $this->post(route('finance.setup'))->assertForbidden();
    }

    public function test_complete_shipment_contribution_uses_posted_bills_and_costs_but_not_unposted_profit():void
    {
        $ctx=$this->company();
        $fx=$this->rate($ctx,'USD','3600',2);
        $invoice=$this->invoice($ctx,'10.00');
        $shipment=$invoice->shipment;
        $purchase=PurchaseOrder::create([
            'business_id'=>$ctx['business']->id,'reference'=>'PO-'.Str::ulid(),
            'kind'=>'market','product_id'=>$ctx['product']->id,'vendor_name'=>'Avocado market',
            'purchased_on'=>now()->toDateString(),'quantity'=>'10','unit_price'=>'2000',
            'estimated_total'=>'20000','currency'=>'UGX','status'=>'received',
            'idempotency_key'=>(string)Str::uuid(),'created_by'=>$ctx['owner']->id,
        ]);
        $batch=\App\Models\StockBatch::create([
            'business_id'=>$ctx['business']->id,'product_id'=>$ctx['product']->id,
            'batch_code'=>'B-FARM-1','received_on'=>now()->toDateString(),
        ]);
        GoodsReceipt::create([
            'business_id'=>$ctx['business']->id,'reference'=>'GR-'.Str::ulid(),
            'purchase_order_id'=>$purchase->id,'batch_id'=>$batch->id,
            'received_quantity'=>'10','accepted_quantity'=>'10','rejected_quantity'=>'0',
            'received_on'=>now()->toDateString(),'posted_by'=>$ctx['owner']->id,
            'idempotency_key'=>(string)Str::uuid(),
        ]);
        $job=\App\Models\PackingJob::create([
            'business_id'=>$ctx['business']->id,'reference'=>'PK-'.Str::ulid(),
            'product_id'=>$ctx['product']->id,'status'=>'completed',
            'created_by'=>$ctx['owner']->id,
        ]);
        $carton=\App\Models\Carton::create([
            'business_id'=>$ctx['business']->id,'carton_code'=>'CT-TEST-1',
            'status'=>'dispatched','packing_job_id'=>$job->id,
            'net_weight_kg'=>'10','gross_weight_kg'=>'11',
        ]);
        \App\Models\CartonContent::create([
            'business_id'=>$ctx['business']->id,'carton_id'=>$carton->id,
            'batch_id'=>$batch->id,'quantity'=>'10',
        ]);
        \App\Models\ShipmentCarton::create([
            'business_id'=>$ctx['business']->id,'shipment_id'=>$shipment->id,
            'carton_id'=>$carton->id,'allocated_by'=>$ctx['owner']->id,
        ]);
        $this->actingAs($ctx['accountant'])
            ->get(route('finance.contribution',$shipment->id))
            ->assertOk()->assertSee('Insufficient validated data');

        $this->post(route('finance.bills.store',$purchase->id),[
            'vendor_invoice_reference'=>'SELLER-04','vendor_invoice_date'=>now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $bill=FinanceSupplierBill::firstOrFail();
        $this->approveAndPost($ctx,'supplier_bill',$bill->id);
        $this->approveAndPost($ctx,'commercial_invoice',$invoice->id,$fx);
        $this->actingAs($ctx['accountant'])->post(route('finance.costs.store'),[
            'shipment_id'=>$shipment->id,'category'=>'handling',
            'description'=>'Airport handling fees','currency'=>'UGX','amount'=>'5000',
            'cash_account_key'=>'bank','paid_on'=>now()->toDateString(),
            'external_reference'=>'BANK-EXPENSE-5',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $cost=\App\Models\FinanceShipmentCost::firstOrFail();
        $this->get(route('finance.contribution',$shipment->id))->assertOk()
            ->assertSee('Insufficient validated data');
        $this->approveAndPost($ctx,'shipment_cost',$cost->id);
        $report=app(\App\Services\ShipmentContribution::class)->report($shipment->fresh());
        $this->assertSame([],$report['missing']);
        $this->assertTrue(BigDecimal::of($report['revenue'])->isEqualTo('36000'));
        $this->assertTrue(BigDecimal::of($report['acquisition_cost'])->isEqualTo('20000'));
        $this->assertTrue(BigDecimal::of($report['operating_cost'])->isEqualTo('5000'));
        $this->assertTrue(BigDecimal::of($report['provisional_contribution'])->isEqualTo('11000'));
        $this->get(route('finance.contribution',$shipment->id))->assertOk()
            ->assertSee('11000')->assertSee('Management estimate');
        $this->assertDatabaseCount('finance_journals',3);
    }

    public function test_rejection_and_rate_approval_are_separate_with_no_journals():void
    {
        $ctx=$this->company();
        $this->actingAs($ctx['accountant'])->get(route('finance.documents'))->assertOk()
            ->assertSee('Supplier bills');
        $this->get(route('finance.rates'))->assertOk()->assertSee('Historical exchange rates');
        $this->get(route('finance.reviews'))->assertOk()->assertSee('Review and post transactions');
        $rate=$this->actingAs($ctx['accountant'])->post(route('finance.rates.propose'),[
            'currency'=>'USD','rate_to_base'=>'3650',
            'effective_on'=>now()->toDateString(),'source_note'=>'Verified bank quote from today',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $record=FinanceExchangeRate::firstOrFail();
        $this->post(route('finance.rates.approve',$record->id))->assertForbidden();
        $this->actingAs($ctx['owner'])->post(route('finance.rates.approve',$record->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved',$record->fresh()->status);

        $invoice=$this->invoice($ctx);
        $this->actingAs($ctx['accountant'])->post(route('finance.reviews.request',[
            'type'=>'commercial_invoice','source'=>$invoice->id,
        ]),[
            'posting_on'=>now()->toDateString(),
            'exchange_rate_id'=>$record->id,
            'basis_notes'=>'Verified shipment dispatched, contract transfer and buyer acceptance.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $review=FinancePostingRequest::firstOrFail();
        $this->actingAs($ctx['owner'])->post(route('finance.reviews.reject',$review->id),[
            'notes'=>'Missing documentary transfer evidence in the file.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected',$review->fresh()->status);
        $this->assertDatabaseCount('finance_journals',0);
        $this->actingAs($ctx['accountant'])->post(route('finance.reviews.request',[
            'type'=>'commercial_invoice','source'=>$invoice->id,
        ]),[
            'posting_on'=>now()->toDateString(),
            'exchange_rate_id'=>$record->id,
            'basis_notes'=>'Updated evidence: airwaybill and buyer acceptance attached; transfer event verified.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending',$review->fresh()->status);
        $this->assertDatabaseCount('finance_approval_events',3);
        $this->assertDatabaseCount('finance_journals',0);
    }
}
