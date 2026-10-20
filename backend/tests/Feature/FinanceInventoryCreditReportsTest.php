<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\CartonContent;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceAccount;
use App\Models\FinanceCreditNote;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinancePayment;
use App\Models\FinancePostingRequest;
use App\Models\FinanceSupplierBill;
use App\Models\GoodsReceipt;
use App\Models\InvoiceAccountingHandoff;
use App\Models\PackingJob;
use App\Models\PackingWasteEntry;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesRequest;
use App\Models\ShipmentCarton;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FinanceLedger;
use App\Services\FinanceSetup;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceInventoryCreditReportsTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $code='A'):array
    {
        $business=Business::create([
            'code'=>$code,'name'=>'Exporter '.$code,'country_code'=>'UG',
            'base_currency'=>'UGX','timezone'=>'Africa/Kampala','default_qr_level'=>'carton',
        ]);
        $owner=User::factory()->create();$accountant=User::factory()->create();
        foreach([[$owner,'owner'],[$accountant,'accountant']] as [$user,$role]) {
            BusinessMembership::create([
                'business_id'=>$business->id,'user_id'=>$user->id,
                'role'=>$role,'active'=>true,
            ]);
        }
        app(FinanceSetup::class)->activate($business);
        $customer=Customer::create([
            'business_id'=>$business->id,'customer_code'=>'C-1','name'=>'Imported Fresh',
            'country_code'=>'AE','currency'=>'USD','active'=>true,
        ]);
        $product=Product::create([
            'business_id'=>$business->id,'sku'=>'P-100',
            'name'=>'Fresh pineapples','base_unit'=>'kg','active'=>true,
        ]);
        return compact('business','owner','accountant','customer','product');
    }

    private function journal(array $ctx,string $type,int $id,
        string $currency,string $rate,array $lines):FinanceJournal
    {
        return app(FinanceLedger::class)->record(
            $ctx['business'],$type,$id,$currency,$rate,now()->toDateString(),
            'Test fixture source journal',$ctx['accountant']->id,$ctx['owner']->id,$lines,
        );
    }

    private function origin(array $ctx,string $accepted='30',string $unitPrice='2000'):array
    {
        $purchase=PurchaseOrder::create([
            'business_id'=>$ctx['business']->id,'reference'=>'PO-'.Str::ulid(),
            'kind'=>'market','vendor_name'=>'Morning market','product_id'=>$ctx['product']->id,
            'purchased_on'=>now()->toDateString(),'quantity'=>$accepted,
            'unit_price'=>$unitPrice,'estimated_total'=>'60000',
            'currency'=>'UGX','status'=>'received',
            'idempotency_key'=>(string)Str::uuid(),'created_by'=>$ctx['owner']->id,
        ]);
        $batch=StockBatch::create([
            'business_id'=>$ctx['business']->id,'product_id'=>$ctx['product']->id,
            'batch_code'=>'BT-'.Str::ulid(),'received_on'=>now()->toDateString(),
        ]);
        $receipt=GoodsReceipt::create([
            'business_id'=>$ctx['business']->id,'reference'=>'GR-'.Str::ulid(),
            'purchase_order_id'=>$purchase->id,'batch_id'=>$batch->id,
            'received_quantity'=>$accepted,'accepted_quantity'=>$accepted,'rejected_quantity'=>0,
            'received_on'=>now()->toDateString(),'posted_by'=>$ctx['owner']->id,
            'idempotency_key'=>(string)Str::uuid(),
        ]);
        StockMovement::create([
            'business_id'=>$ctx['business']->id,'batch_id'=>$batch->id,
            'movement_type'=>'received','quantity'=>$accepted,'unit'=>'kg',
            'source_type'=>'goods_receipt','source_id'=>$receipt->id,
            'created_by'=>$ctx['owner']->id,
        ]);
        $bill=FinanceSupplierBill::create([
            'business_id'=>$ctx['business']->id,'purchase_order_id'=>$purchase->id,
            'reference'=>'BILL-'.Str::ulid(),'vendor_invoice_reference'=>'FARM-500',
            'vendor_invoice_date'=>now()->toDateString(),
            'accepted_quantity'=>$accepted,'total'=>'60000','currency'=>'UGX',
            'posting_status'=>'posted','created_by'=>$ctx['owner']->id,'posted_at'=>now(),
        ]);
        $ledger=app(FinanceLedger::class);
        $this->journal($ctx,'supplier_bill',$bill->id,'UGX','1',[
            $ledger->line('inventory','60000','0','60000','0'),
            $ledger->line('payable','0','60000','0','60000'),
        ]);
        return compact('purchase','batch','bill');
    }

    private function shipping(array $ctx,StockBatch $batch,string $qty='10'):ExportShipment
    {
        $job=PackingJob::create([
            'business_id'=>$ctx['business']->id,'reference'=>'PK-'.Str::ulid(),
            'product_id'=>$ctx['product']->id,'status'=>'completed',
            'created_by'=>$ctx['owner']->id,
        ]);
        $carton=Carton::create([
            'business_id'=>$ctx['business']->id,'carton_code'=>'CART-'.Str::ulid(),
            'status'=>'shipped','packing_job_id'=>$job->id,
            'net_weight_kg'=>$qty,'gross_weight_kg'=>BigDecimal::of($qty)->plus('1'),
        ]);
        $content=CartonContent::create([
            'business_id'=>$ctx['business']->id,'carton_id'=>$carton->id,
            'batch_id'=>$batch->id,'quantity'=>$qty,
        ]);
        StockMovement::create([
            'business_id'=>$ctx['business']->id,'batch_id'=>$batch->id,
            'movement_type'=>'packing_consumption','quantity'=>
                (string)BigDecimal::of($qty)->negated(),'unit'=>'kg',
            'source_type'=>'carton_content','source_id'=>$content->id,
            'created_by'=>$ctx['owner']->id,
        ]);
        $shipment=ExportShipment::create([
            'business_id'=>$ctx['business']->id,'reference'=>'SHIP-'.Str::ulid(),
            'customer_id'=>$ctx['customer']->id,'status'=>'dispatched',
            'transport_mode'=>'air','origin_country'=>'UG','destination_country'=>'AE',
            'created_by'=>$ctx['owner']->id,
        ]);
        ShipmentCarton::create([
            'business_id'=>$ctx['business']->id,'shipment_id'=>$shipment->id,
            'carton_id'=>$carton->id,'allocated_by'=>$ctx['owner']->id,
        ]);
        return $shipment;
    }

    private function postReview(array $ctx,string $type,int $id):FinanceJournal
    {
        $this->actingAs($ctx['accountant'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->post(route('finance.reviews.request',['type'=>$type,'source'=>$id]),[
            'posting_on'=>now()->toDateString(),
            'basis_notes'=>'Documents and source batches verified for this controlled accounting posting.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $review=FinancePostingRequest::query()->where('business_id',$ctx['business']->id)
            ->where('source_type',$type)->where('source_id',$id)->firstOrFail();
        $this->actingAs($ctx['owner'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->post(route('finance.reviews.approve',$review->id),[
            'notes'=>'Independent supporting document review and posting approval.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ctx['accountant'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->post(route('finance.reviews.post',$review->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        return FinanceJournal::query()->where('business_id',$ctx['business']->id)
            ->where('source_type',$type)->where('source_id',$id)->firstOrFail();
    }

    public function test_cogs_and_waste_reduce_inventory_and_management_reports_are_balanced():void
    {
        $ctx=$this->context();
        $source=$this->origin($ctx);
        $shipment=$this->shipping($ctx,$source['batch'],'10');
        $this->actingAs($ctx['accountant'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->get(route('finance.inventory.index'))->assertOk()->assertSee('Stock valuation');
        $this->post(route('finance.inventory.prepare',[
            'kind'=>'cogs','id'=>$shipment->id,
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $cogs=FinanceInventoryRelief::firstOrFail();
        $this->assertTrue(BigDecimal::of((string)$cogs->base_amount)->isEqualTo('20000'));
        $this->post(route('finance.inventory.prepare',[
            'kind'=>'cogs','id'=>$shipment->id,
        ]))->assertStatus(422);
        $this->get(route('finance.inventory.show',$cogs->id))->assertOk()->assertSee('20000');
        $this->postReview($ctx,'inventory_relief',$cogs->id);
        $this->assertSame('posted',$cogs->fresh()->posting_status);

        $waste=PackingWasteEntry::create([
            'business_id'=>$ctx['business']->id,'packing_job_id'=>$shipment
                ->allocations()->firstOrFail()->carton->packing_job_id,
            'batch_id'=>$source['batch']->id,
            'quantity'=>'5','reason'=>'grading_loss','notes'=>'Spoiled before dispatch',
            'created_by'=>$ctx['owner']->id,'idempotency_key'=>(string)Str::uuid(),
        ]);
        StockMovement::create([
            'business_id'=>$ctx['business']->id,'batch_id'=>$source['batch']->id,
            'movement_type'=>'grading_loss','quantity'=>'-5','unit'=>'kg',
            'source_type'=>'packing_waste','source_id'=>$waste->id,
            'created_by'=>$ctx['owner']->id,
        ]);
        $this->post(route('finance.inventory.prepare',['kind'=>'waste','id'=>$waste->id]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $loss=FinanceInventoryRelief::query()->where('kind','waste')->firstOrFail();
        $this->assertTrue(BigDecimal::of((string)$loss->base_amount)->isEqualTo('10000'));
        $this->postReview($ctx,'inventory_relief',$loss->id);
        $this->assertDatabaseCount('finance_journals',3);
        $this->assertDatabaseCount('finance_inventory_reliefs',2);
        $report=app(\App\Services\FinanceStatements::class)->report(
            $ctx['business'],now()->startOfYear()->toDateString(),now()->toDateString()
        );
        $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo('30000'));
        $this->assertTrue(BigDecimal::of($report['liabilities'])->isEqualTo('60000'));
        $this->assertTrue(BigDecimal::of($report['expense'])->isEqualTo('30000'));
        $this->assertTrue(BigDecimal::of($report['net'])->isEqualTo('-30000'));
        $this->assertTrue(BigDecimal::of($report['balance_variance'])->isEqualTo(0));
        $this->get(route('finance.reports.index'))->assertOk()
            ->assertSee('Profit and loss')->assertSee('30000');
        $this->get(route('finance.reports.ledger',
            app(FinanceSetup::class)->account($ctx['business']->id,'inventory')->id
        ))->assertOk()->assertSee('30000');

        $over=$this->shipping($ctx,$source['batch'],'20');
        $this->post(route('finance.inventory.prepare',['kind'=>'cogs','id'=>$over->id]))
            ->assertSessionHasErrors('inventory');
        $this->assertDatabaseCount('finance_inventory_reliefs',2);
    }

    private function issuedPostedInvoice(array $ctx,string $currency='USD'):CommercialInvoice
    {
        $request=SalesRequest::create([
            'business_id'=>$ctx['business']->id,'customer_id'=>$ctx['customer']->id,
            'reference'=>'RFQ-'.Str::ulid(),'status'=>'confirmed','created_by'=>$ctx['owner']->id,
        ]);
        $quote=SalesQuotation::create([
            'business_id'=>$ctx['business']->id,'sales_request_id'=>$request->id,
            'reference'=>'Q-'.Str::ulid(),'revision'=>1,'status'=>'accepted',
            'currency'=>$currency,'subtotal'=>'90','discount_amount'=>'0',
            'tax_amount'=>'10','grand_total'=>'100','created_by'=>$ctx['owner']->id,
        ]);
        $order=SalesOrder::create([
            'business_id'=>$ctx['business']->id,'reference'=>'SO-'.Str::ulid(),
            'sales_request_id'=>$request->id,'sales_quotation_id'=>$quote->id,
            'customer_id'=>$ctx['customer']->id,'status'=>'confirmed',
            'currency'=>$currency,'subtotal'=>'90','discount_amount'=>'0',
            'tax_amount'=>'10','grand_total'=>'100','buyer_confirmation'=>'Accepted via buyer email',
            'confirmed_by'=>$ctx['owner']->id,'confirmed_at'=>now(),
        ]);
        $shipment=ExportShipment::create([
            'business_id'=>$ctx['business']->id,'reference'=>'SHP-'.Str::ulid(),
            'customer_id'=>$ctx['customer']->id,'sales_order_id'=>$order->id,
            'status'=>'dispatched','transport_mode'=>'air',
            'origin_country'=>'UG','destination_country'=>'AE','created_by'=>$ctx['owner']->id,
        ]);
        $invoice=CommercialInvoice::create([
            'business_id'=>$ctx['business']->id,'reference'=>'CI-'.Str::ulid(),
            'shipment_id'=>$shipment->id,'sales_order_id'=>$order->id,'customer_id'=>$ctx['customer']->id,
            'currency'=>$currency,'subtotal'=>'90','discount_amount'=>'0','tax_amount'=>'10',
            'freight_amount'=>'0','insurance_amount'=>'0','grand_total'=>'100',
            'posting_status'=>'posted','data_snapshot'=>[
                'business'=>['name'=>$ctx['business']->name],
                'customer'=>['name'=>$ctx['customer']->name],
            ],
            'html_snapshot'=>'<h1>Original invoice</h1>',
            'sha256'=>hash('sha256','<h1>Original invoice</h1>'),
            'issued_by'=>$ctx['owner']->id,'issued_at'=>now()->subDay(),
        ]);
        InvoiceAccountingHandoff::create([
            'business_id'=>$ctx['business']->id,'commercial_invoice_id'=>$invoice->id,
            'currency'=>$currency,'commercial_amount'=>'100',
            'posting_status'=>'posted','prepared_at'=>now(),'details'=>[],
        ]);
        $ledger=app(FinanceLedger::class);
        $this->journal($ctx,'commercial_invoice',$invoice->id,$currency,'3600',[
            $ledger->line('receivable','100','0','360000','0'),
            $ledger->line('export_sales','0','90','0','324000'),
            $ledger->line('sales_tax','0','10','0','36000'),
        ]);
        return $invoice;
    }

    public function test_full_credit_note_reverses_original_exactly_without_erasing_document_or_shipment():void
    {
        $ctx=$this->context();
        $invoice=$this->issuedPostedInvoice($ctx);
        $before=$invoice->html_snapshot;
        $this->actingAs($ctx['accountant'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->get(route('finance.credits.index'))->assertOk()->assertSee($invoice->reference);
        $this->post(route('finance.credits.prepare',$invoice->id),[
            'reason'=>'The buyer agreed that this whole invoice was wrongly issued; fully reverse it.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $note=FinanceCreditNote::firstOrFail();
        $this->assertSame('not_posted',$note->posting_status);
        $this->get(route('finance.credits.pdf',$note->id))->assertStatus(422);
        $this->postReview($ctx,'credit_note',$note->id);
        $this->assertSame('posted',$note->fresh()->posting_status);
        $this->assertSame('credited',$invoice->fresh()->posting_status);
        $this->assertTrue(app(\App\Services\FinanceDocuments::class)
            ->outstanding($invoice->fresh())->isEqualTo(0));
        $this->assertSame('credited',$invoice->accountingHandoff->fresh()->posting_status);
        $this->assertSame($before,$invoice->fresh()->html_snapshot);
        $this->assertSame('dispatched',$invoice->shipment->fresh()->status);
        $this->assertDatabaseCount('finance_journals',2);
        $this->assertDatabaseCount('finance_journal_lines',6);
        $this->assertSame(hash('sha256',$note->fresh()->html_snapshot),$note->fresh()->sha256);
        $pdf=$this->get(route('finance.credits.pdf',$note->id))->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF',$pdf);
        $report=app(\App\Services\FinanceStatements::class)->report(
            $ctx['business'],now()->startOfYear()->toDateString(),now()->toDateString()
        );
        $this->assertTrue(BigDecimal::of($report['income'])->isEqualTo(0));
        $this->assertTrue(BigDecimal::of($report['liabilities'])->isEqualTo(0));
        $this->assertTrue(BigDecimal::of($report['assets'])->isEqualTo(0));
        $this->post(route('finance.credits.prepare',$invoice->id),[
            'reason'=>'Trying a duplicate full invoice correction that must not be created.',
        ])->assertStatus(422);
    }

    public function test_customer_payment_blocks_full_credit_and_company_isolation():void
    {
        $ctx=$this->context();
        $invoice=$this->issuedPostedInvoice($ctx);
        $bank=app(FinanceSetup::class)->account($ctx['business']->id,'bank');
        FinancePayment::create([
            'business_id'=>$ctx['business']->id,'reference'=>'PAY-'.Str::ulid(),
            'direction'=>'in','commercial_invoice_id'=>$invoice->id,
            'cash_account_id'=>$bank->id,'currency'=>'USD','amount'=>'25',
            'payment_on'=>now()->toDateString(),
            'external_reference'=>'BANK-RX-25','status'=>'posted',
            'idempotency_key'=>(string)Str::uuid(),'created_by'=>$ctx['owner']->id,
        ]);
        $this->actingAs($ctx['accountant'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->post(route('finance.credits.prepare',$invoice->id),[
            'reason'=>'Cannot cancel an invoice for which customer payments exist.',
        ])->assertSessionHasErrors('invoice');
        $this->assertDatabaseCount('finance_credit_notes',0);

        $other=$this->context('OTHER');
        $this->actingAs($other['accountant'])->withSession([
            'active_business_id'=>$other['business']->id,
        ])->post(route('finance.credits.prepare',$invoice->id),[
            'reason'=>'Trying to create another company commercial credit note.',
        ])->assertNotFound();
        $this->get(route('finance.inventory.show',1))->assertNotFound();
        $this->get(route('finance.reports.ledger',
            app(FinanceSetup::class)->account($ctx['business']->id,'bank')->id
        ))->assertNotFound();
    }

    public function test_existing_finance_chart_adds_new_cost_account_without_replacing_existing_records():void
    {
        $ctx=$this->context();
        $waste=app(FinanceSetup::class)->account($ctx['business']->id,'waste_loss');
        $waste->delete();
        $this->assertDatabaseCount('finance_accounts',15);
        $this->actingAs($ctx['owner'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->get(route('finance.index'))->assertOk()
            ->assertSee('Add missing finance accounts safely');
        $this->post(route('finance.setup'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('finance_accounts',16);
        $this->assertSame(1,\App\Models\FinanceAccount::query()
            ->where('business_id',$ctx['business']->id)
            ->where('system_key','waste_loss')->count());
        $this->post(route('finance.setup'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('finance_accounts',16);
    }
}
