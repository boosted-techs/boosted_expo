<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\CartonContent;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceJournal;
use App\Models\FinancePayment;
use App\Models\FinancePostingRequest;
use App\Models\FinanceSupplierBill;
use App\Models\GoodsReceipt;
use App\Models\InvoiceAccountingHandoff;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesRequest;
use App\Models\ShipmentCarton;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\FinanceCostBasis;
use App\Services\FinanceDocuments;
use App\Services\FinanceLedger;
use App\Services\FinanceSetup;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceBalanceAdjustmentsPhaseTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code='EXA'):array
    {
        $business=Business::create([
            'name'=>$code.' Growers','code'=>$code,'country_code'=>'UG',
            'base_currency'=>'UGX','timezone'=>'Africa/Kampala','default_qr_level'=>'carton',
        ]);
        $owner=User::factory()->create();$accountant=User::factory()->create();
        foreach([[$owner,'owner'],[$accountant,'accountant']] as [$user,$role]) {
            BusinessMembership::create([
                'business_id'=>$business->id,'user_id'=>$user->id,'role'=>$role,'active'=>true,
            ]);
        }
        app(FinanceSetup::class)->activate($business);
        $customer=Customer::create([
            'business_id'=>$business->id,'customer_code'=>'GULF-1','name'=>'Gulf Fresh Foods',
            'country_code'=>'AE','currency'=>'USD','active'=>true,
        ]);
        $product=Product::create([
            'business_id'=>$business->id,'sku'=>'STAW','name'=>'Strawberries','base_unit'=>'kg','active'=>true,
        ]);
        return compact('business','owner','accountant','customer','product');
    }

    private function as(array $ctx,string $role='accountant'):self
    {
        return $this->actingAs($ctx[$role])->withSession(['active_business_id'=>$ctx['business']->id]);
    }

    private function posted(array $ctx,string $type,int $id,string $currency,string $rate,
        array $lines):FinanceJournal
    {
        return app(FinanceLedger::class)->record(
            $ctx['business'],$type,$id,$currency,$rate,
            now()->toDateString(),'Test source document posting',
            $ctx['accountant']->id,$ctx['owner']->id,$lines
        );
    }

    private function completeReview(array $ctx,int $id,string $type='balance_adjustment'):FinanceJournal
    {
        $this->as($ctx)->post(route('finance.reviews.request',[
            'type'=>$type,'source'=>$id,
        ]),[
            'posting_on'=>now()->toDateString(),
            'basis_notes'=>'Signed commercial concession agreement and original finance journal verified.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $review=FinancePostingRequest::query()->where('business_id',$ctx['business']->id)
            ->where('source_type',$type)->where('source_id',$id)->firstOrFail();
        $this->as($ctx,'owner')->post(route('finance.reviews.approve',$review->id),[
            'notes'=>'Independent review of the concession and original contract.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->as($ctx)->post(route('finance.reviews.post',$review->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        return FinanceJournal::query()->where('business_id',$ctx['business']->id)
            ->where('source_type',$type)->where('source_id',$id)->firstOrFail();
    }

    private function invoice(array $ctx):CommercialInvoice
    {
        $rfq=SalesRequest::create([
            'business_id'=>$ctx['business']->id,'customer_id'=>$ctx['customer']->id,
            'reference'=>'RFQ-'.Str::ulid(),'status'=>'confirmed','created_by'=>$ctx['owner']->id,
        ]);
        $quote=SalesQuotation::create([
            'business_id'=>$ctx['business']->id,'sales_request_id'=>$rfq->id,
            'reference'=>'QUO-'.Str::ulid(),'revision'=>1,'status'=>'accepted',
            'currency'=>'USD','subtotal'=>'100','discount_amount'=>0,
            'tax_amount'=>0,'grand_total'=>'100','created_by'=>$ctx['owner']->id,
        ]);
        $order=SalesOrder::create([
            'business_id'=>$ctx['business']->id,'reference'=>'ORD-'.Str::ulid(),
            'sales_request_id'=>$rfq->id,'sales_quotation_id'=>$quote->id,
            'customer_id'=>$ctx['customer']->id,'status'=>'confirmed',
            'currency'=>'USD','subtotal'=>'100','discount_amount'=>0,
            'tax_amount'=>0,'grand_total'=>'100','buyer_confirmation'=>'Verified signed sales quote',
            'confirmed_by'=>$ctx['owner']->id,'confirmed_at'=>now(),
        ]);
        $shipment=ExportShipment::create([
            'business_id'=>$ctx['business']->id,'reference'=>'SHP-'.Str::ulid(),
            'customer_id'=>$ctx['customer']->id,'sales_order_id'=>$order->id,
            'status'=>'dispatched','transport_mode'=>'air',
            'origin_country'=>'UG','destination_country'=>'AE','created_by'=>$ctx['owner']->id,
        ]);
        $invoice=CommercialInvoice::create([
            'business_id'=>$ctx['business']->id,'shipment_id'=>$shipment->id,
            'sales_order_id'=>$order->id,'customer_id'=>$ctx['customer']->id,
            'reference'=>'CI-'.Str::ulid(),'currency'=>'USD',
            'subtotal'=>'100','discount_amount'=>0,'tax_amount'=>0,
            'freight_amount'=>0,'insurance_amount'=>0,'grand_total'=>'100',
            'posting_status'=>'posted','data_snapshot'=>[],
            'html_snapshot'=>'<h1>Original</h1>','sha256'=>hash('sha256','<h1>Original</h1>'),
            'issued_by'=>$ctx['owner']->id,'issued_at'=>now(),
        ]);
        InvoiceAccountingHandoff::create([
            'business_id'=>$ctx['business']->id,'commercial_invoice_id'=>$invoice->id,
            'currency'=>'USD','commercial_amount'=>'100',
            'posting_status'=>'posted','prepared_at'=>now(),'details'=>[],
        ]);
        $ledger=app(FinanceLedger::class);
        $this->posted($ctx,'commercial_invoice',$invoice->id,'USD','3600',[
            $ledger->line('receivable','100','0','360000','0'),
            $ledger->line('export_sales','0','100','0','360000'),
        ]);
        return $invoice;
    }

    private function bill(array $ctx):array
    {
        $purchase=PurchaseOrder::create([
            'business_id'=>$ctx['business']->id,'reference'=>'PO-'.Str::ulid(),
            'kind'=>'market','product_id'=>$ctx['product']->id,'vendor_name'=>'Market vendor',
            'purchased_on'=>now()->toDateString(),'quantity'=>'50',
            'unit_price'=>'2000','estimated_total'=>'100000','currency'=>'UGX',
            'status'=>'received','idempotency_key'=>(string)Str::uuid(),
            'created_by'=>$ctx['owner']->id,
        ]);
        $batch=StockBatch::create([
            'business_id'=>$ctx['business']->id,'batch_code'=>'LOT-'.Str::ulid(),
            'product_id'=>$ctx['product']->id,'received_on'=>now()->toDateString(),
        ]);
        GoodsReceipt::create([
            'business_id'=>$ctx['business']->id,'reference'=>'RCV-'.Str::ulid(),
            'purchase_order_id'=>$purchase->id,'batch_id'=>$batch->id,
            'received_quantity'=>'50','accepted_quantity'=>'50','rejected_quantity'=>'0',
            'received_on'=>now()->toDateString(),
            'posted_by'=>$ctx['owner']->id,'idempotency_key'=>(string)Str::uuid(),
        ]);
        $bill=FinanceSupplierBill::create([
            'business_id'=>$ctx['business']->id,'reference'=>'BILL-'.Str::ulid(),
            'purchase_order_id'=>$purchase->id,'vendor_invoice_reference'=>'MARKET-102',
            'vendor_invoice_date'=>now()->toDateString(),
            'accepted_quantity'=>'50','total'=>'100000','currency'=>'UGX',
            'posting_status'=>'posted','posted_at'=>now(),'created_by'=>$ctx['owner']->id,
        ]);
        $ledger=app(FinanceLedger::class);
        $journal=$this->posted($ctx,'supplier_bill',$bill->id,'UGX','1',[
            $ledger->line('inventory','100000','0','100000','0'),
            $ledger->line('payable','0','100000','0','100000'),
        ]);
        return compact('bill','batch','purchase','journal');
    }

    public function test_partial_customer_concession_posts_original_fx_and_reduces_collectible_amount():void
    {
        $ctx=$this->company();
        $invoice=$this->invoice($ctx);
        $this->as($ctx)->post(route('finance.controls.adjustments.store',[
            'kind'=>'customer','id'=>$invoice->id,
        ]),['amount'=>'10','reason'=>'Buyer agreed a ten dollar deduction for a quality shortfall.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $adj=FinanceBalanceAdjustment::firstOrFail();
        $this->assertSame('not_posted',$adj->posting_status);
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice)->isEqualTo(90));
        $this->post(route('finance.payments.store'),[
            'direction'=>'in','commercial_invoice_id'=>$invoice->id,
            'cash_account_key'=>'bank','amount'=>'90',
            'payment_on'=>now()->toDateString(),'external_reference'=>'PAY-BEFORE-CREDIT',
            'idempotency_key'=>(string)Str::uuid(),
        ])->assertSessionHasErrors('document');
        $journal=$this->completeReview($ctx,$adj->id);
        $this->assertTrue(BigDecimal::of((string)$journal->rate_to_base)->isEqualTo('3600'));
        $this->assertSame('posted',$adj->fresh()->posting_status);
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice,false)->isEqualTo(90));
        $this->assertSame('posted',$invoice->fresh()->posting_status);
        $this->assertDatabaseCount('finance_journals',2);
        $this->as($ctx)->post(route('finance.controls.adjustments.store',[
            'kind'=>'customer','id'=>$invoice->id,
        ]),['amount'=>'5','reason'=>'Second concession must not be silently applied to the same invoice.'])
            ->assertSessionHasErrors('adjustment');
        $this->post(route('finance.credits.prepare',$invoice->id),[
            'reason'=>'Full credit conflicts with the posted prior partial concession.',
        ])->assertStatus(422);
        $this->post(route('finance.payments.store'),[
            'direction'=>'in','commercial_invoice_id'=>$invoice->id,
            'cash_account_key'=>'bank','amount'=>'91',
            'payment_on'=>now()->toDateString(),'external_reference'=>'BANK-OVERPAY',
            'idempotency_key'=>(string)Str::uuid(),
        ])->assertSessionHasErrors('amount');
        $this->post(route('finance.payments.store'),[
            'direction'=>'in','commercial_invoice_id'=>$invoice->id,
            'cash_account_key'=>'bank','amount'=>'90',
            'payment_on'=>now()->toDateString(),'external_reference'=>'BANK-EXACTPAY',
            'idempotency_key'=>(string)Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('finance_payments',1);
    }

    public function test_supplier_concession_reduces_inventory_cost_basis_and_payable_before_packing():void
    {
        $ctx=$this->company();
        $origin=$this->bill($ctx);
        $bill=$origin['bill'];
        $this->as($ctx)->post(route('finance.controls.adjustments.store',[
            'kind'=>'supplier','id'=>$bill->id,
        ]),['amount'=>'10000','reason'=>'Supplier granted a verified bulk-price reduction before any packing.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $adj=FinanceBalanceAdjustment::firstOrFail();
        try {
            app(FinanceCostBasis::class)->unitBase($bill,$origin['journal']);
            $this->fail('Pending supplier price concessions must block cost valuation.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('inventory',$e->errors());
        }
        $this->completeReview($ctx,$adj->id);
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($bill,false)->isEqualTo(90000));
        $unit=app(FinanceCostBasis::class)->unitBase($bill,$origin['journal']);
        $this->assertTrue($unit->isEqualTo('1800'));
        $this->as($ctx)->post(route('finance.payments.store'),[
            'direction'=>'out','supplier_bill_id'=>$bill->id,'cash_account_key'=>'cash',
            'amount'=>'90000','payment_on'=>now()->toDateString(),
            'external_reference'=>'CASH-PAID','idempotency_key'=>(string)Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.payments.store'),[
            'direction'=>'out','supplier_bill_id'=>$bill->id,'cash_account_key'=>'cash',
            'amount'=>'1','payment_on'=>now()->toDateString(),
            'external_reference'=>'OVERPAY','idempotency_key'=>(string)Str::uuid(),
        ])->assertSessionHasErrors('amount');
        $paid=FinancePayment::firstOrFail();
        $journal=$this->completeReview($ctx,$paid->id,'payment');
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($bill,false)->isZero());
        $this->assertTrue($journal->lines()->whereHas('account',
            fn($q)=>$q->where('system_key','payable'))->exists());

        // A second bill with any packed quantities cannot receive a supplier
        // concession because its inventory source valuation is no longer intact.
        $another=$this->company('OTHER');
        $otherOrigin=$this->bill($another);
        $job=PackingJob::create([
            'business_id'=>$another['business']->id,'reference'=>'PK-'.Str::ulid(),
            'product_id'=>$another['product']->id,'status'=>'completed',
            'created_by'=>$another['owner']->id,
        ]);
        $carton=Carton::create([
            'business_id'=>$another['business']->id,'carton_code'=>'CT-'.Str::ulid(),
            'status'=>'packed','packing_job_id'=>$job->id,
            'net_weight_kg'=>'10','gross_weight_kg'=>'11',
        ]);
        CartonContent::create([
            'business_id'=>$another['business']->id,'carton_id'=>$carton->id,
            'batch_id'=>$otherOrigin['batch']->id,'quantity'=>'10',
        ]);
        $this->as($another)->post(route('finance.controls.adjustments.store',[
            'kind'=>'supplier','id'=>$otherOrigin['bill']->id,
        ]),['amount'=>'5000','reason'=>'An already packed batch must not be discounted silently.'])
            ->assertSessionHasErrors('adjustment');
        $this->post(route('finance.controls.adjustments.store',[
            'kind'=>'supplier','id'=>$bill->id,
        ]),['amount'=>'500','reason'=>'Cannot access another company supplier bill.'])
            ->assertNotFound();
        $this->assertDatabaseCount('finance_balance_adjustments',1);
    }
}
