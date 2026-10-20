<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\CartonContent;
use App\Models\CommercialInvoice;
use App\Models\InvoiceAccountingHandoff;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesRequest;
use App\Models\StockBatch;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code='FIRST'): array
    {
        $business=Business::create([
            'name'=>$code.' Exporter','code'=>$code,'country_code'=>'UG',
            'base_currency'=>'UGX','timezone'=>'Africa/Kampala','default_qr_level'=>'carton',
        ]);
        $owner=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$business->id,'user_id'=>$owner->id,'role'=>'owner','active'=>true,
        ]);
        $customer=Customer::create([
            'business_id'=>$business->id,'customer_code'=>'BUY-1','name'=>'Dubai Fresh Importers',
            'country_code'=>'AE','city'=>'Dubai','currency'=>'USD','active'=>true,
        ]);
        $product=Product::create([
            'business_id'=>$business->id,'name'=>'Fresh Matooke',
            'sku'=>'MAT-01','base_unit'=>'kg','active'=>true,
        ]);
        return compact('business','owner','customer','product');
    }

    private function makeRequest(array $context,string $qty='50'):SalesRequest
    {
        $this->actingAs($context['owner'])->post(route('sales-requests.store'),[
            'customer_id'=>$context['customer']->id,
            'required_on'=>'2026-11-01',
            'items'=>[['product_id'=>$context['product']->id,'quantity'=>$qty]],
            'notes'=>'Air freight fresh produce',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SalesRequest::query()->where('business_id',$context['business']->id)->orderByDesc('id')->firstOrFail();
    }

    private function makeQuote(array $context,string $qty='50',string $price='2.50',
        string $discount='0',string $tax='0',string $currency='USD'):SalesQuotation
    {
        $rfq=$this->makeRequest($context,$qty);
        $this->post(route('sales-requests.draft',$rfq->id))->assertRedirect()->assertSessionHasNoErrors();
        $quote=SalesQuotation::query()->where('sales_request_id',$rfq->id)->firstOrFail();
        $this->put(route('sales-quotes.update',$quote->id),[
            'currency'=>$currency,'incoterm'=>'FCA','valid_until'=>'2026-12-01',
            'discount_percent'=>$discount,'tax_percent'=>$tax,
            'items'=>[['product_id'=>$context['product']->id,'quantity'=>$qty,'unit_price'=>$price]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('sales-quotes.issue',$quote->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('issued',$quote->fresh()->status);
        return $quote->fresh();
    }

    private function confirm(SalesQuotation $quote):SalesOrder
    {
        $this->post(route('sales-quotes.confirm',$quote->id),[
            'buyer_confirmation'=>'Buyer purchase order PO-ACCEPT-01',
        ])->assertRedirect()->assertSessionHasNoErrors();
        return SalesOrder::query()->where('sales_quotation_id',$quote->id)->firstOrFail();
    }

    private function carton(array $context,string $quantity,string $suffix):Carton
    {
        $job=PackingJob::create([
            'business_id'=>$context['business']->id,'reference'=>'PK-'.Str::ulid(),
            'product_id'=>$context['product']->id,'status'=>'completed',
            'created_by'=>$context['owner']->id,
        ]);
        $batch=StockBatch::create([
            'business_id'=>$context['business']->id,'product_id'=>$context['product']->id,
            'batch_code'=>'B-'.$suffix,'received_on'=>'2026-10-20',
        ]);
        $carton=Carton::create([
            'business_id'=>$context['business']->id,
            'carton_code'=>'CT-'.$suffix,'status'=>'packed','packing_job_id'=>$job->id,
            'net_weight_kg'=>$quantity,
            'gross_weight_kg'=>(string)BigDecimal::of($quantity)->plus('1'),
            'tare_weight_kg'=>'1',
        ]);
        CartonContent::create([
            'business_id'=>$context['business']->id,'carton_id'=>$carton->id,
            'batch_id'=>$batch->id,'quantity'=>$quantity,
        ]);
        return $carton;
    }

    private function shipment(array $context,?SalesOrder $order=null):ExportShipment
    {
        $data=[
            'creation_key'=>(string)Str::uuid(),'customer_id'=>$context['customer']->id,
            'origin_country'=>'UG','destination_country'=>'AE',
            'origin_airport'=>'EBB','destination_airport'=>'DXB',
            'flight_number'=>'EK730','air_waybill'=>'176-12345675',
            'scheduled_departure_at'=>'2026-10-28T15:00',
        ];
        if($order)$data['sales_order_id']=$order->id;
        $this->actingAs($context['owner'])->post(route('shipments.store'),$data)->assertRedirect()->assertSessionHasNoErrors();
        return ExportShipment::query()->where('business_id',$context['business']->id)
            ->orderByDesc('id')->firstOrFail();
    }

    public function test_request_quote_issue_accept_and_sales_order_price_snapshot():void
    {
        $context=$this->company();
        $rfq=$this->makeRequest($context);
        $this->get(route('sales-requests.show',$rfq->id))->assertOk()
            ->assertSee('Fresh Matooke');
        $this->post(route('sales-requests.draft',$rfq->id))->assertRedirect();
        $quote=SalesQuotation::firstOrFail();
        $this->get(route('sales-quotes.show',$quote->id))->assertOk()
            ->assertSee('Quotation pricing');
        $this->post(route('sales-quotes.issue',$quote->id))->assertSessionHasErrors('items');
        $this->put(route('sales-quotes.update',$quote->id),[
            'currency'=>'USD','incoterm'=>'FCA','valid_until'=>'2026-12-01',
            'discount_percent'=>'5','tax_percent'=>'0',
            'items'=>[['product_id'=>$context['product']->id,'quantity'=>'50','unit_price'=>'2.50']],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('sales-quotes.issue',$quote->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('issued',$quote->fresh()->status);
        $this->assertTrue(BigDecimal::of((string)$quote->fresh()->grand_total)->isEqualTo('118.75'));
        $this->put(route('sales-quotes.update',$quote->id),[
            'currency'=>'USD','discount_percent'=>'0','tax_percent'=>'0',
            'items'=>[['product_id'=>$context['product']->id,'quantity'=>'50','unit_price'=>'1']],
        ])->assertStatus(422);
        $issuedHtml=$this->get(route('sales-quotes.print',$quote->id))->assertOk()->assertSee('118.75')->getContent();
        $context['product']->update(['name'=>'Renamed Fresh Matooke']);
        $context['customer']->update(['name'=>'Renamed Buyer']);
        $this->assertSame($issuedHtml,$this->get(route('sales-quotes.print',$quote->id))->getContent());
        $this->put(route('products.update',$context['product']->id),[
            'sku'=>'MAT-01','name'=>'Renamed Fresh Matooke','base_unit'=>'box','active'=>'1',
        ])->assertSessionHasErrors('base_unit');
        $pdf=$this->get(route('sales-quotes.pdf',$quote->id))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $order=$this->confirm($quote);
        $this->assertSame('confirmed',$order->status);
        $this->assertSame($quote->id,$order->sales_quotation_id);
        $this->assertSame('Buyer purchase order PO-ACCEPT-01',$order->buyer_confirmation);
        $this->assertTrue(BigDecimal::of((string)$order->grand_total)->isEqualTo('118.75'));
        $this->post(route('sales-quotes.confirm',$quote->id),[
            'buyer_confirmation'=>'Another PO-001',
        ])->assertStatus(422);
        $this->assertDatabaseCount('sales_orders',1);
        $this->get(route('sales-orders.show',$order->id))->assertOk()->assertSee('Still to allocate');
    }

    public function test_quotation_revisions_preserve_previous_offer_and_expire():void
    {
        $context=$this->company();
        $quote=$this->makeQuote($context,'25','3.25');
        $this->post(route('sales-requests.draft',$quote->sales_request_id))->assertRedirect();
        $second=SalesQuotation::query()->where('revision',2)->firstOrFail();
        $this->assertSame('draft',$second->status);
        $this->put(route('sales-quotes.update',$second->id),[
            'currency'=>'USD','incoterm'=>'FCA','valid_until'=>'2026-12-01',
            'discount_percent'=>'0','tax_percent'=>'0',
            'items'=>[['product_id'=>$context['product']->id,'quantity'=>'20','unit_price'=>'3.75']],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('sales-quotes.issue',$second->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('superseded',$quote->fresh()->status);
        $this->post(route('sales-quotes.confirm',$quote->id),[
            'buyer_confirmation'=>'PO-OLDEST-01',
        ])->assertStatus(422);
        $order=$this->confirm($second);
        $this->assertTrue(BigDecimal::of((string)$order->lines->first()->quantity)->isEqualTo('20'));
        $this->assertTrue(BigDecimal::of((string)$quote->fresh()->lines->first()->quantity)->isEqualTo('25'));
        $this->assertDatabaseCount('sales_quotations',2);
    }

    public function test_partial_shipments_capacity_and_immutable_commercial_invoice():void
    {
        $context=$this->company();
        $order=$this->confirm($this->makeQuote($context,'50','2.50','5','0'));
        $one=$this->carton($context,'20','001');
        $two=$this->carton($context,'15','002');
        $over=$this->carton($context,'20','003');
        $first=$this->shipment($context,$order);
        $this->post(route('shipments.allocate',$first->id),[
            'carton_ids'=>[$one->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shipments.ready',$first->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('shipments.show',$first->id))->assertOk()->assertSee('Commercial invoice');
        $this->post(route('commercial-invoices.issue',$first->id),[
            'freight_amount'=>'5.00','insurance_amount'=>'1.25',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $invoice=CommercialInvoice::firstOrFail();
        $this->assertSame('USD',$invoice->currency);
        $this->assertTrue(BigDecimal::of((string)$invoice->subtotal)->isEqualTo('50'));
        $this->assertTrue(BigDecimal::of((string)$invoice->discount_amount)->isEqualTo('2.50'));
        $this->assertTrue(BigDecimal::of((string)$invoice->grand_total)->isEqualTo('53.75'));
        $this->assertSame('not_posted',$invoice->posting_status);
        $this->assertSame(hash('sha256',$invoice->html_snapshot),$invoice->sha256);
        $this->assertDatabaseCount('commercial_invoices',1);
        $handoff=InvoiceAccountingHandoff::firstOrFail();
        $this->assertSame('awaiting_accounting_policy',$handoff->posting_status);
        $this->assertSame('USD',$handoff->currency);
        $this->assertDatabaseCount('invoice_accounting_handoffs',1);
        $this->get(route('invoice-accounting-handoff.index'))->assertOk()->assertSee('53.75');
        $this->assertStringStartsWith('%PDF',
            $this->get(route('commercial-invoices.pdf',$invoice->id))->assertOk()->getContent());
        $this->post(route('commercial-invoices.issue',$first->id),[
            'freight_amount'=>'12','insurance_amount'=>'0',
        ])->assertStatus(422);
        $this->post(route('shipments.reopen',$first->id))->assertStatus(422);

        $second=$this->shipment($context,$order);
        $this->post(route('shipments.allocate',$second->id),[
            'carton_ids'=>[$two->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shipments.allocate',$second->id),[
            'carton_ids'=>[$over->id],
        ])->assertSessionHasErrors('carton_ids');
        $this->assertSame('packed',$over->fresh()->status);
        $this->assertDatabaseCount('shipment_cartons',2);
        $this->get(route('sales-orders.show',$order->id))->assertOk()->assertSee('15');
        $this->assertDatabaseCount('commercial_invoices',1);
    }

    public function test_mismatched_customer_other_company_and_role_permissions():void
    {
        $context=$this->company('FIRST');
        $order=$this->confirm($this->makeQuote($context));
        $other=Customer::create([
            'business_id'=>$context['business']->id,'customer_code'=>'BUY-OTHER',
            'name'=>'Different Buyer','country_code'=>'GB','currency'=>'GBP',
        ]);
        $this->actingAs($context['owner'])->post(route('shipments.store'),[
            'creation_key'=>(string)Str::uuid(),'customer_id'=>$other->id,
            'sales_order_id'=>$order->id,'origin_country'=>'UG','destination_country'=>'GB',
        ])->assertStatus(422);
        $this->assertDatabaseCount('export_shipments',0);
        $second=$this->company('SECOND');
        $this->actingAs($second['owner'])->withSession(['active_business_id'=>$second['business']->id])
            ->get(route('sales-orders.show',$order->id))->assertNotFound();
        $this->post(route('sales-requests.draft',$order->sales_request_id))->assertNotFound();
        $this->post(route('shipments.store'),[
            'creation_key'=>(string)Str::uuid(),'customer_id'=>$second['customer']->id,
            'sales_order_id'=>$order->id,'origin_country'=>'UG','destination_country'=>'AE',
        ])->assertNotFound();

        $salesperson=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$context['business']->id,'user_id'=>$salesperson->id,
            'role'=>'sales','active'=>true,
        ]);
        $this->actingAs($salesperson)->withSession(['active_business_id'=>$context['business']->id])
            ->get(route('sales-requests.index'))->assertOk();
        $this->get(route('sales-orders.index'))->assertOk();
        $this->get(route('invoice-accounting-handoff.index'))->assertForbidden();
        $this->post(route('sales-quotes.confirm',$order->sales_quotation_id),[
            'buyer_confirmation'=>'A fake reference',
        ])->assertForbidden();
        $this->post(route('commercial-invoices.issue',1),[
            'freight_amount'=>'0','insurance_amount'=>'0',
        ])->assertForbidden();
    }

    public function test_invoice_requires_ready_linked_order_and_correct_status():void
    {
        $context=$this->company();
        $first=$this->shipment($context);
        $this->post(route('commercial-invoices.issue',$first->id),[
            'freight_amount'=>'0','insurance_amount'=>'0',
        ])->assertStatus(422);
        $order=$this->confirm($this->makeQuote($context,'10','10'));
        $this->post(route('sales-orders.shipments.link',[
            'order'=>$order->id,'shipment'=>$first->id,
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('commercial-invoices.issue',$first->id),[
            'freight_amount'=>'0','insurance_amount'=>'0',
        ])->assertStatus(422);
        $this->assertDatabaseCount('commercial_invoices',0);
    }

    public function test_invoice_handoffs_keep_currencies_separate_and_accountants_scoped():void
    {
        $context=$this->company('EXP');
        $orderUsd=$this->confirm($this->makeQuote($context,'10','20','0','0','USD'));
        $cartonUsd=$this->carton($context,'10','USD');
        $shipmentUsd=$this->shipment($context,$orderUsd);
        $this->post(route('shipments.allocate',$shipmentUsd->id),[
            'carton_ids'=>[$cartonUsd->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shipments.ready',$shipmentUsd->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('commercial-invoices.issue',$shipmentUsd->id),[
            'freight_amount'=>'0','insurance_amount'=>'0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $orderEur=$this->confirm($this->makeQuote($context,'5','10','10','18','EUR'));
        $cartonEur=$this->carton($context,'5','EUR');
        $shipmentEur=$this->shipment($context,$orderEur);
        $this->post(route('shipments.allocate',$shipmentEur->id),[
            'carton_ids'=>[$cartonEur->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shipments.ready',$shipmentEur->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('commercial-invoices.issue',$shipmentEur->id),[
            'freight_amount'=>'0','insurance_amount'=>'0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('invoice_accounting_handoffs',2);
        $this->assertDatabaseHas('invoice_accounting_handoffs',[
            'business_id'=>$context['business']->id,'currency'=>'USD',
            'posting_status'=>'awaiting_accounting_policy',
        ]);
        $this->assertDatabaseHas('invoice_accounting_handoffs',[
            'business_id'=>$context['business']->id,'currency'=>'EUR',
            'posting_status'=>'awaiting_accounting_policy',
        ]);
        $accountant=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$context['business']->id,'user_id'=>$accountant->id,
            'role'=>'accountant','active'=>true,
        ]);
        $this->actingAs($accountant)->withSession(['active_business_id'=>$context['business']->id])
            ->get(route('invoice-accounting-handoff.index'))->assertOk()
            ->assertSee('EUR')->assertSee('USD')->assertSee('53.10')->assertSee('200.00');

        $another=$this->company('OTHER');
        $invoice=CommercialInvoice::where('currency','EUR')->firstOrFail();
        $this->actingAs($another['owner'])->withSession(['active_business_id'=>$another['business']->id])
            ->get(route('commercial-invoices.show',$invoice->id))->assertNotFound();
        $this->get(route('invoice-accounting-handoff.index'))->assertOk()
            ->assertDontSee($invoice->reference);
        $this->assertDatabaseCount('commercial_invoices',2);
    }

    public function test_catalogue_import_cannot_reinterpret_units_of_requested_product():void
    {
        $context=$this->company();
        $this->makeRequest($context,'75');
        $csv=\Illuminate\Http\UploadedFile::fake()->createWithContent(
            'products.csv',"SKU,Product,Unit\nMAT-01,Matooke in boxes,box\n",
        );
        $this->post(route('products.import.preview'),['file'=>$csv])
            ->assertOk()->assertSee('Review products');
        $import=\App\Models\CatalogueImport::firstOrFail();
        $this->post(route('products.import.apply',$import->id))
            ->assertSessionHasErrors('base_unit');
        $this->assertSame('kg',$context['product']->fresh()->base_unit);
        $this->assertSame('Fresh Matooke',$context['product']->fresh()->name);
        $this->assertSame('pending',$import->fresh()->status);
    }

    public function test_sales_creation_invoicing_and_warehouse_navigation_respect_roles():void
    {
        $context=$this->company();
        $this->actingAs($context['owner'])
            ->get(route('sales-requests.create'))->assertOk()->assertSee('New customer request');
        $this->get(route('sales-orders.index'))->assertOk()->assertSee('Confirmed orders');
        $this->get(route('commercial-invoices.index'))->assertOk()->assertSee('Issued invoices');
        $this->get(route('invoice-accounting-handoff.index'))->assertOk()
            ->assertSee('No general-ledger posting has occurred');
        $this->get(route('shipments.create',[
            'customer_id'=>$context['customer']->id,
        ]))->assertOk()->assertSee('Confirmed sales order');

        foreach(['sales','logistics','accountant'] as $role) {
            $user=User::factory()->create();
            BusinessMembership::create([
                'business_id'=>$context['business']->id,
                'user_id'=>$user->id,'role'=>$role,'active'=>true,
            ]);
            $this->actingAs($user)->withSession([
                'active_business_id'=>$context['business']->id,
            ]);
            if($role==='sales') {
                $this->get(route('sales-requests.create'))->assertOk();
                $this->get(route('shipments.create'))->assertForbidden();
                $this->get(route('invoice-accounting-handoff.index'))->assertForbidden();
            } elseif($role==='logistics') {
                $this->get(route('shipments.create'))->assertOk();
                $this->get(route('sales-orders.index'))->assertOk();
                $this->get(route('commercial-invoices.index'))->assertForbidden();
            } else {
                $this->get(route('invoice-accounting-handoff.index'))->assertOk();
                $this->get(route('commercial-invoices.index'))->assertOk();
                $this->get(route('sales-requests.create'))->assertForbidden();
            }
        }
    }
}
