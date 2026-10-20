<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\CartonContent;
use App\Models\Customer;
use App\Models\ExportDocument;
use App\Models\ExportDocumentTemplate;
use App\Models\ExportShipment;
use App\Models\LabelTemplate;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\ShipmentCarton;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportShipmentsTest extends TestCase
{
    use RefreshDatabase;

    private function setupCompany(string $code): array
    {
        $business=Business::create([
            'name'=>$code.' Growers Ltd','code'=>$code,'country_code'=>'UG',
            'base_currency'=>'UGX','timezone'=>'Africa/Kampala','default_qr_level'=>'carton',
        ]);
        $owner=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$business->id,'user_id'=>$owner->id,'role'=>'owner','active'=>true,
        ]);
        $customer=Customer::create([
            'business_id'=>$business->id,'customer_code'=>'BUYER-1',
            'name'=>'Gulf Fresh Produce','country_code'=>'AE','city'=>'Dubai','currency'=>'USD',
            'active'=>true,
        ]);
        $product=Product::create([
            'business_id'=>$business->id,'sku'=>'RED-1','name'=>'Red Beans','base_unit'=>'kg',
        ]);
        $job=PackingJob::create([
            'business_id'=>$business->id,'reference'=>'PK-'.Str::ulid(),
            'product_id'=>$product->id,'status'=>'completed','created_by'=>$owner->id,
        ]);
        $batch=StockBatch::create([
            'business_id'=>$business->id,'product_id'=>$product->id,
            'batch_code'=>'BT-UG-1','source_reference'=>'UG-FARM-45','received_on'=>'2026-10-19',
        ]);
        $cartons=[];
        foreach ([['CT-001','12.500','13.100'],['CT-002','20.000','21.000']] as $row) {
            $carton=Carton::create([
                'business_id'=>$business->id,'carton_code'=>$row[0],
                'packing_job_id'=>$job->id,'net_weight_kg'=>$row[1],
                'gross_weight_kg'=>$row[2],'tare_weight_kg'=>(string)
                    \Brick\Math\BigDecimal::of($row[2])->minus($row[1]),
                'status'=>'packed','grade'=>'Grade A','packaging_type'=>'Air export carton',
            ]);
            CartonContent::create([
                'business_id'=>$business->id,'carton_id'=>$carton->id,
                'batch_id'=>$batch->id,'quantity'=>$row[1],
            ]);
            $cartons[]=$carton;
        }
        return compact('business','owner','customer','product','job','batch','cartons');
    }

    private function shipmentData(Customer $customer,array $overrides=[]): array
    {
        return array_merge([
            'creation_key'=>(string)Str::uuid(),
            'customer_id'=>$customer->id,
            'origin_country'=>'UG','destination_country'=>'AE',
            'origin_airport'=>'Entebbe (EBB)',
            'destination_airport'=>'Dubai (DXB)',
            'carrier'=>'Emirates Cargo',
            'flight_number'=>'EK730',
            'air_waybill'=>'176-12345675',
            'scheduled_departure_at'=>'2026-10-25T16:00',
            'estimated_arrival_at'=>'2026-10-25T23:30',
            'incoterm'=>'FCA',
        ],$overrides);
    }

    private function createShipment(array $context,?array $overrides=null): ExportShipment
    {
        $this->actingAs($context['owner'])->post(route('shipments.store'),
            $this->shipmentData($context['customer'],$overrides??[]))
            ->assertRedirect()->assertSessionHasNoErrors();
        return ExportShipment::query()->where('business_id',$context['business']->id)
            ->orderByDesc('id')->firstOrFail();
    }

    private function templateData(string $type='packing_list',array $extra=[]): array
    {
        return array_merge([
            'name'=>$type==='packing_list'?'Buyer Packing List':'Dispatch Note',
            'type'=>$type,'paper_size'=>'A4','orientation'=>'landscape',
            'visible_columns'=>$type==='packing_list'
                ? ['carton','product','farmer','batches','net','tare','gross','boxes'] : [],
            'header_fields'=>['customer','carrier','flight','awb','route','schedule','incoterm'],
        ],$extra);
    }

    public function test_complete_shipment_document_and_delivery_process_with_immutable_pdf(): void
    {
        $context=$this->setupCompany('FIRST');
        $shipment=$this->createShipment($context);
        $this->assertSame('draft',$shipment->status);
        $this->assertSame('13:00',$shipment->scheduled_departure_at->format('H:i')); // 16:00 Kampala → UTC
        $ids=array_map(fn ($c)=>$c->id,$context['cartons']);
        $this->post(route('shipments.allocate',$shipment->id),[
            'carton_ids'=>$ids,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shipments.allocate',$shipment->id),[
            'carton_ids'=>$ids,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('shipment_cartons',2);
        $this->assertSame('reserved',$context['cartons'][0]->fresh()->status);
        $this->get(route('shipments.show',$shipment->id))->assertOk()->assertSee('CT-001');
        $this->post(route('shipments.ready',$shipment->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ready',$shipment->fresh()->status);
        $this->post(route('shipments.release',[$shipment->id,$ids[0]]))->assertMethodNotAllowed();

        $this->post(route('document-templates.store'),$this->templateData())
            ->assertRedirect()->assertSessionHasNoErrors();
        $template=ExportDocumentTemplate::firstOrFail();
        $this->post(route('export-documents.issue',[$shipment->id,$template->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $document=ExportDocument::firstOrFail();
        $this->assertSame('ready',$document->data_snapshot['shipment']['status']);
        $this->assertSame('2',$document->data_snapshot['totals']['cartons'].'');
        $this->assertSame('32.500',$document->data_snapshot['totals']['net']);
        $this->assertSame('34.100',$document->data_snapshot['totals']['gross']);
        $this->assertSame('1.600',$document->data_snapshot['totals']['tare']);
        $this->assertSame('16:00',substr($document->data_snapshot['header']['schedule'],-5));
        $this->assertSame('UG-FARM-45',$document->data_snapshot['lines'][0]['farmer']);
        $this->get(route('export-documents.print',$document->id))->assertOk()
            ->assertSee('Gulf Fresh Produce')->assertSee('UG-FARM-45')
            ->assertSee('34.100')->assertSee('EK730');

        $pdf=$this->get(route('export-documents.pdf',$document->id))
            ->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringStartsWith('%PDF',$pdf->getContent());
        $this->assertGreaterThan(1700,strlen($pdf->getContent()));

        $oldHash=$document->sha256;
        $oldHtml=$document->html_snapshot;
        $this->put(route('document-templates.update',$template->id),$this->templateData(
            'packing_list',['orientation'=>'portrait','visible_columns'=>['product','net']]
        ))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2,$template->fresh()->version);
        $this->assertSame($oldHash,$document->fresh()->sha256);
        $this->assertSame($oldHtml,$document->fresh()->html_snapshot);

        $this->post(route('shipments.dispatch',$shipment->id),[
            'actual_departure_at'=>'2026-10-25T16:30','confirm_dispatch'=>'1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('dispatched',$shipment->fresh()->status);
        $this->assertSame('shipped',$context['cartons'][0]->fresh()->status);
        $this->assertDatabaseCount('stock_movements',0); // Cannot deduct already-packed produce twice.
        $this->assertSame('ready',$document->fresh()->data_snapshot['shipment']['status']);

        $this->post(route('document-templates.store'),$this->templateData('dispatch_note'))
            ->assertRedirect()->assertSessionHasNoErrors();
        $dispatchTemplate=ExportDocumentTemplate::where('type','dispatch_note')->firstOrFail();
        $this->post(route('export-documents.issue',[$shipment->id,$dispatchTemplate->id]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $dispatchNote=ExportDocument::where('document_type','dispatch_note')->firstOrFail();
        $this->assertSame('dispatched',$dispatchNote->data_snapshot['shipment']['status']);
        $this->assertStringNotContainsString('Packed produce — carton manifest',$dispatchNote->html_snapshot);
        $this->assertStringContainsString('Cargo handover details',$dispatchNote->html_snapshot);

        $this->post(route('shipments.arrive',$shipment->id),[
            'actual_arrival_at'=>'2026-10-25T23:55','confirm_arrival'=>'1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('delivered',$shipment->fresh()->status);
        $this->assertSame('delivered',$context['cartons'][0]->fresh()->status);
        $this->assertDatabaseCount('shipment_cartons',2);
        $this->assertDatabaseCount('export_documents',2);
    }

    public function test_shipment_double_booking_is_blocked_and_cancel_releases_cartons(): void
    {
        $c=$this->setupCompany('FIRST');
        $a=$this->createShipment($c);
        $b=$this->createShipment($c);
        $this->post(route('shipments.allocate',$a->id),[
            'carton_ids'=>[$c['cartons'][0]->id],
        ])->assertRedirect();
        $this->post(route('shipments.allocate',$b->id),[
            'carton_ids'=>[$c['cartons'][0]->id],
        ])->assertSessionHasErrors('carton_ids');
        $this->assertDatabaseCount('shipment_cartons',1);

        $this->post(route('shipments.cancel',$a->id),[
            'confirm_cancel'=>'1',
        ])->assertRedirect();
        $this->assertSame('cancelled',$a->fresh()->status);
        $this->assertSame('packed',$c['cartons'][0]->fresh()->status);
        $this->assertDatabaseCount('shipment_cartons',0);
        $this->post(route('shipments.allocate',$b->id),[
            'carton_ids'=>[$c['cartons'][0]->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reserved',$c['cartons'][0]->fresh()->status);
        $this->assertDatabaseCount('shipment_cartons',1);
    }

    public function test_ready_validation_and_cross_business_authorisation(): void
    {
        $a=$this->setupCompany('FIRST');
        $b=$this->setupCompany('SECOND');
        $shipment=$this->createShipment($a,['flight_number'=>null,'air_waybill'=>null]);
        $this->post(route('shipments.ready',$shipment->id))
            ->assertSessionHasErrors();
        $this->post(route('shipments.allocate',$shipment->id),[
            'carton_ids'=>[$b['cartons'][0]->id],
        ])->assertNotFound();
        $this->assertDatabaseCount('shipment_cartons',0);

        $this->actingAs($b['owner'])->withSession(['active_business_id'=>$b['business']->id]);
        $this->get(route('shipments.show',$shipment->id))->assertNotFound();
        $this->put(route('shipments.update',$shipment->id),
            $this->shipmentData($a['customer']))->assertNotFound();
        $this->post(route('shipments.allocate',$shipment->id),[
            'carton_ids'=>[$a['cartons'][0]->id],
        ])->assertNotFound();
    }

    public function test_duplicate_creation_key_and_shipment_permissions(): void
    {
        $c=$this->setupCompany('FIRST');
        $data=$this->shipmentData($c['customer']);
        $this->actingAs($c['owner'])->post(route('shipments.store'),$data)->assertRedirect();
        $this->post(route('shipments.store'),$data)->assertRedirect();
        $this->assertDatabaseCount('export_shipments',1);

        $warehouse=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$c['business']->id,'user_id'=>$warehouse->id,
            'role'=>'warehouse','active'=>true,
        ]);
        $this->actingAs($warehouse)->withSession(['active_business_id'=>$c['business']->id])
            ->get(route('shipments.index'))->assertOk();
        $this->post(route('shipments.dispatch',ExportShipment::first()->id),[
            'actual_departure_at'=>'2026-10-25T16:00','confirm_dispatch'=>'1',
        ])->assertForbidden();

        $packer=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$c['business']->id,'user_id'=>$packer->id,
            'role'=>'packing','active'=>true,
        ]);
        $this->actingAs($packer)->withSession(['active_business_id'=>$c['business']->id])
            ->get(route('shipments.index'))->assertForbidden();
    }

    public function test_reopen_and_reissue_keep_original_document_snapshot(): void
    {
        $c=$this->setupCompany('FIRST');
        $shipment=$this->createShipment($c);
        $this->post(route('shipments.allocate',$shipment->id),[
            'carton_ids'=>[$c['cartons'][0]->id],
        ])->assertRedirect();
        $this->post(route('shipments.ready',$shipment->id))->assertRedirect();
        $this->post(route('document-templates.store'),$this->templateData())
            ->assertRedirect();
        $template=ExportDocumentTemplate::firstOrFail();
        $this->post(route('export-documents.issue',[$shipment->id,$template->id]))
            ->assertRedirect();
        $first=ExportDocument::firstOrFail();
        $this->assertSame('1',$first->data_snapshot['totals']['cartons'].'');
        $this->assertSame('CT-001',$first->data_snapshot['lines'][0]['carton']);

        $this->post(route('shipments.reopen',$shipment->id))->assertRedirect();
        $this->delete(route('shipments.release',[$shipment->id,$c['cartons'][0]->id]))
            ->assertRedirect();
        $this->post(route('shipments.allocate',$shipment->id),[
            'carton_ids'=>[$c['cartons'][1]->id],
        ])->assertRedirect();
        $this->post(route('shipments.ready',$shipment->id))->assertRedirect();
        $this->post(route('export-documents.issue',[$shipment->id,$template->id]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('export_documents',2);
        $second=ExportDocument::where('version',2)->firstOrFail();
        $this->assertSame('CT-002',$second->data_snapshot['lines'][0]['carton']);
        $this->assertSame('CT-001',$first->fresh()->data_snapshot['lines'][0]['carton']);
        $this->assertNotEquals($first->sha256,$second->sha256);
        $this->get(route('shipments.show',$shipment->id))->assertOk()->assertSee('reopened');

        $this->post(route('shipments.cancel',$shipment->id),[
            'confirm_cancel'=>'1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled',$shipment->fresh()->status);
        $this->assertSame('packed',$c['cartons'][1]->fresh()->status);
        $this->get(route('export-documents.print',$first->id))
            ->assertOk()->assertSee('CT-001');
        $this->post(route('export-documents.issue',[$shipment->id,$template->id]))
            ->assertStatus(422);
    }

    public function test_mixed_batch_manifest_keeps_weights_once_and_rejects_inconsistent_weights(): void
    {
        $c=$this->setupCompany('FIRST');
        $more=StockBatch::create([
            'business_id'=>$c['business']->id,'product_id'=>$c['product']->id,
            'batch_code'=>'BT-MIX-2','source_reference'=>'UG-FARM-78',
            'received_on'=>'2026-10-20',
        ]);
        CartonContent::create([
            'business_id'=>$c['business']->id,'carton_id'=>$c['cartons'][0]->id,
            'batch_id'=>$more->id,'quantity'=>'3.000',
        ]);
        $shipment=$this->createShipment($c);
        $this->post(route('shipments.allocate',$shipment->id),[
            'carton_ids'=>[$c['cartons'][0]->id],
        ])->assertRedirect();
        $this->post(route('shipments.ready',$shipment->id))->assertRedirect();
        $this->post(route('document-templates.store'),$this->templateData())
            ->assertRedirect();
        $template=ExportDocumentTemplate::firstOrFail();
        $this->post(route('export-documents.issue',[$shipment->id,$template->id]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $snapshot=ExportDocument::firstOrFail()->data_snapshot;
        $this->assertSame('12.500',$snapshot['totals']['net']);
        $this->assertSame('1',$snapshot['totals']['cartons'].'');
        $this->assertStringContainsString('UG-FARM-45',$snapshot['lines'][0]['farmer']);
        $this->assertStringContainsString('UG-FARM-78',$snapshot['lines'][0]['farmer']);
        $c['cartons'][0]->update(['gross_weight_kg'=>'10']);
        $this->post(route('export-documents.issue',[$shipment->id,$template->id]))
            ->assertSessionHasErrors('document');
        $this->assertDatabaseCount('export_documents',1);
    }

    public function test_shipment_zebra_labels_use_selected_flight_and_awb(): void
    {
        $c=$this->setupCompany('FIRST');
        $shipment=$this->createShipment($c);
        $this->post(route('shipments.allocate',$shipment->id),[
            'carton_ids'=>[$c['cartons'][0]->id],
        ])->assertRedirect();
        $label=LabelTemplate::create([
            'business_id'=>$c['business']->id,'name'=>'Flight and AWB sticker',
            'width_mm'=>100,'height_mm'=>50,'dpi'=>203,'qr_level'=>'none',
            'include_barcode'=>false,'visible_fields'=>['flight','awb','destination'],
            'created_by'=>$c['owner']->id,
        ]);
        $zpl=$this->post(route('labels.generate',[
            'carton'=>$c['cartons'][0]->id,'template'=>$label->id,
        ]))->assertOk()->streamedContent();
        $encode=fn(string $text)=>implode('',array_map(
            fn($char)=>sprintf('_%02X',ord($char)),
            str_split($text),
        ));
        $this->assertStringContainsString($encode('Flight: EK730'),$zpl);
        $this->assertStringContainsString($encode('AWB: 176-12345675'),$zpl);
        $this->assertStringContainsString($encode('To: Dubai (DXB)'),$zpl);
    }

    public function test_document_routes_and_dispatch_actions_are_company_isolated(): void
    {
        $c=$this->setupCompany('FIRST');
        $other=$this->setupCompany('SECOND');
        $shipment=$this->createShipment($c);
        $this->post(route('shipments.allocate',$shipment->id),[
            'carton_ids'=>[$c['cartons'][0]->id],
        ])->assertRedirect();
        $this->post(route('shipments.ready',$shipment->id))->assertRedirect();
        $this->post(route('document-templates.store'),$this->templateData())
            ->assertRedirect();
        $template=ExportDocumentTemplate::firstOrFail();
        $this->post(route('export-documents.issue',[$shipment->id,$template->id]))
            ->assertRedirect();
        $document=ExportDocument::firstOrFail();
        $this->actingAs($other['owner'])->withSession([
            'active_business_id'=>$other['business']->id,
        ])->get(route('export-documents.print',$document->id))->assertNotFound();
        $this->get(route('export-documents.pdf',$document->id))->assertNotFound();
        $this->post(route('export-documents.issue',[$shipment->id,$template->id]))
            ->assertNotFound();

        $logistics=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$c['business']->id,'user_id'=>$logistics->id,
            'role'=>'logistics','active'=>true,
        ]);
        $this->actingAs($logistics)->withSession([
            'active_business_id'=>$c['business']->id,
        ])->post(route('shipments.dispatch',$shipment->id),[
            'actual_departure_at'=>'2026-10-25T16:00','confirm_dispatch'=>'1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shipments.cancel',$shipment->id),[
            'confirm_cancel'=>'1',
        ])->assertStatus(422);
        $this->post(route('shipments.arrive',$shipment->id),[
            'actual_arrival_at'=>'2026-10-25T15:00','confirm_arrival'=>'1',
        ])->assertSessionHasErrors('actual_arrival_at');
    }
}
