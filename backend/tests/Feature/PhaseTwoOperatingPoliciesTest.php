<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\CartonContent;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\ShipmentDispatchReview;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseTwoOperatingPoliciesTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code):array
    {
        $business=Business::create([
            'name'=>$code.' Exporters','code'=>$code,'country_code'=>'UG','base_currency'=>'UGX',
            'timezone'=>'Africa/Kampala','default_qr_level'=>'batch',
        ]);
        $roles=[];
        foreach(['owner','admin','logistics','warehouse'] as $role){
            $roles[$role]=User::factory()->create();
            BusinessMembership::create([
                'business_id'=>$business->id,'user_id'=>$roles[$role]->id,
                'role'=>$role,'active'=>true,
            ]);
        }
        $customer=Customer::create([
            'business_id'=>$business->id,'customer_code'=>'BUYER',
            'name'=>'Fresh Buyer','country_code'=>'AE','currency'=>'USD','active'=>true,
        ]);
        $product=Product::create([
            'business_id'=>$business->id,'sku'=>'MANGO','name'=>'Fresh Mango',
            'base_unit'=>'kg','reorder_point'=>'20',
        ]);
        $batch=StockBatch::create([
            'business_id'=>$business->id,'product_id'=>$product->id,
            'batch_code'=>'BATCH-1','received_on'=>now()->toDateString(),
        ]);
        StockMovement::create([
            'business_id'=>$business->id,'batch_id'=>$batch->id,
            'movement_type'=>'receipt','quantity'=>'100','unit'=>'kg',
            'source_type'=>'goods_receipt','source_id'=>1,
            'created_by'=>$roles['owner']->id,
        ]);
        $job=PackingJob::create([
            'business_id'=>$business->id,'reference'=>'PACK-'.Str::ulid(),
            'product_id'=>$product->id,'status'=>'completed',
            'created_by'=>$roles['owner']->id,
        ]);
        $carton=Carton::create([
            'business_id'=>$business->id,'carton_code'=>'CT-'.Str::ulid(),
            'packing_job_id'=>$job->id,'net_weight_kg'=>'10.000',
            'gross_weight_kg'=>'10.500','tare_weight_kg'=>'0.500',
            'status'=>'packed',
        ]);
        CartonContent::create([
            'business_id'=>$business->id,'carton_id'=>$carton->id,
            'batch_id'=>$batch->id,'quantity'=>'10.000',
        ]);
        $shipment=ExportShipment::create([
            'business_id'=>$business->id,'reference'=>'SHP-'.Str::ulid(),
            'creation_key'=>(string)Str::uuid(),'customer_id'=>$customer->id,
            'status'=>'draft','transport_mode'=>'air',
            'origin_country'=>'UG','destination_country'=>'AE',
            'origin_airport'=>'EBB','destination_airport'=>'DXB',
            'flight_number'=>'EK730','air_waybill'=>'176-44444444',
            'scheduled_departure_at'=>now()->addDay(),
            'created_by'=>$roles['owner']->id,
        ]);
        return compact('business','roles','customer','product','batch','job','carton','shipment');
    }

    private function as(array $c,string $role):self
    {
        return $this->actingAs($c['roles'][$role])
            ->withSession(['active_business_id'=>$c['business']->id]);
    }

    public function test_owner_enables_separate_dispatch_review_and_same_requester_cannot_approve():void
    {
        $c=$this->company('DISPATCH');
        $this->as($c,'admin')->post(route('business.policies.update'),[
            'dispatch_approval_required'=>1,'buyer_link_days'=>7,
        ])->assertForbidden();
        $this->as($c,'owner')->post(route('business.policies.update'),[
            'dispatch_approval_required'=>1,'buyer_link_days'=>10,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('business_operating_policies',[
            'business_id'=>$c['business']->id,'dispatch_approval_required'=>1,
        ]);
        $this->as($c,'logistics')->post(route('shipments.allocate',$c['shipment']->id),[
            'carton_ids'=>[$c['carton']->id],
        ])->assertSessionHasNoErrors();
        $this->post(route('shipments.ready',$c['shipment']->id))
            ->assertSessionHasNoErrors();
        $this->post(route('shipments.dispatch',$c['shipment']->id),[
            'actual_departure_at'=>now()->format('Y-m-d\TH:i'),
            'confirm_dispatch'=>1,
        ])->assertSessionHasErrors('dispatch');
        $this->post(route('shipments.approval.submit',$c['shipment']->id))
            ->assertSessionHasNoErrors();
        $review=ShipmentDispatchReview::firstOrFail();
        $this->assertSame($c['roles']['logistics']->id,$review->requested_by);
        $this->post(route('shipments.approval.review',[
            'shipment'=>$c['shipment']->id,'decision'=>'approve',
        ]),[
            'reason'=>'Logistics staff may not approve their own dispatch request.',
        ])->assertForbidden();
        $this->as($c,'owner')->post(route('shipments.approval.review',[
            'shipment'=>$c['shipment']->id,'decision'=>'approve',
        ]),[
            'reason'=>'Cargo, AWB, destination, schedule and allocations checked independently.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('approved',$review->fresh()->status);
        $this->as($c,'logistics')->post(route('shipments.dispatch',$c['shipment']->id),[
            'actual_departure_at'=>now()->format('Y-m-d\TH:i'),
            'confirm_dispatch'=>1,
        ])->assertSessionHasNoErrors();
        $this->assertSame('dispatched',$c['shipment']->fresh()->status);
    }

    public function test_reopened_shipment_loses_previous_signoff_and_requires_new_approval():void
    {
        $c=$this->company('RESET');
        $this->as($c,'owner')->post(route('business.policies.update'),[
            'dispatch_approval_required'=>1,'buyer_link_days'=>7,
        ])->assertSessionHasNoErrors();
        $this->as($c,'logistics')->post(route('shipments.allocate',$c['shipment']->id),[
            'carton_ids'=>[$c['carton']->id],
        ])->assertSessionHasNoErrors();
        $this->post(route('shipments.ready',$c['shipment']->id))->assertSessionHasNoErrors();
        $this->post(route('shipments.approval.submit',$c['shipment']->id))->assertSessionHasNoErrors();
        $this->as($c,'owner')->post(route('shipments.approval.review',[
            'shipment'=>$c['shipment']->id,'decision'=>'approve',
        ]),[
            'reason'=>'First version of flight and manifest independently reviewed.',
        ])->assertSessionHasNoErrors();
        $this->as($c,'logistics')->post(route('shipments.reopen',$c['shipment']->id))
            ->assertSessionHasNoErrors();
        $this->post(route('shipments.ready',$c['shipment']->id))
            ->assertSessionHasNoErrors();
        $this->post(route('shipments.dispatch',$c['shipment']->id),[
            'actual_departure_at'=>now()->format('Y-m-d\TH:i'),'confirm_dispatch'=>1,
        ])->assertSessionHasErrors('dispatch');
        $this->post(route('shipments.approval.submit',$c['shipment']->id))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('shipment_dispatch_reviews',2);
    }

    public function test_quality_hold_blocks_carton_allocation_and_is_owner_released_with_audit():void
    {
        $c=$this->company('QUALITY');
        $this->as($c,'warehouse')->post(route('stock.quality.change',[
            'batch'=>$c['batch']->id,'action'=>'hold',
        ]),['reason'=>'Packaging inspection indicated signs of product spoilage.'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($c['batch']->fresh()->is_quarantined);
        $this->post(route('stock.quality.change',[
            'batch'=>$c['batch']->id,'action'=>'release',
        ]),['reason'=>'Unauthorized staff must not release suspect produce.'])->assertForbidden();
        $this->as($c,'logistics')->post(route('shipments.allocate',$c['shipment']->id),[
            'carton_ids'=>[$c['carton']->id],
        ])->assertSessionHasErrors('carton_ids');
        $this->as($c,'owner')->post(route('stock.quality.change',[
            'batch'=>$c['batch']->id,'action'=>'release',
        ]),['reason'=>'Documented independent batch quality inspection cleared the goods.'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($c['batch']->fresh()->is_quarantined);
        $this->assertDatabaseCount('stock_quality_events',2);
        $this->as($c,'logistics')->post(route('shipments.allocate',$c['shipment']->id),[
            'carton_ids'=>[$c['carton']->id],
        ])->assertSessionHasNoErrors();
        $this->post(route('shipments.ready',$c['shipment']->id))->assertSessionHasNoErrors();
        $this->as($c,'warehouse')->post(route('stock.quality.change',[
            'batch'=>$c['batch']->id,'action'=>'hold',
        ]),['reason'=>'Further quality inspection after reservation found mould.'])
            ->assertSessionHasNoErrors();
        $this->as($c,'logistics')->post(route('shipments.dispatch',$c['shipment']->id),[
            'actual_departure_at'=>now()->format('Y-m-d\TH:i'),'confirm_dispatch'=>1,
        ])->assertSessionHasErrors('carton_ids');
        $this->assertSame('ready',$c['shipment']->fresh()->status);
    }

    public function test_quarantined_stock_cannot_be_packed_but_can_be_logged_as_sorting_loss():void
    {
        $c=$this->company('PACKHOLD');
        $openJob=PackingJob::create([
            'business_id'=>$c['business']->id,'reference'=>'PK-HOLD-'.Str::ulid(),
            'product_id'=>$c['product']->id,'status'=>'open',
            'created_by'=>$c['roles']['owner']->id,
        ]);
        $this->as($c,'warehouse')->post(route('stock.quality.change',[
            'batch'=>$c['batch']->id,'action'=>'hold',
        ]),['reason'=>'Temperature readings from the packing room failed inspection.'])
            ->assertSessionHasNoErrors();
        $this->as($c,'warehouse')->post(route('packing.pack',$openJob->id),[
            'packing_key'=>(string)Str::uuid(),
            'contents'=>[['batch_id'=>$c['batch']->id,'quantity'=>'5']],
            'net_weight_kg'=>'5','gross_weight_kg'=>'5.5',
        ])->assertSessionHasErrors('contents');
        $this->assertSame(1,Carton::query()->where('business_id',$c['business']->id)->count());
        $this->post(route('packing.loss',$openJob->id),[
            'batch_id'=>$c['batch']->id,'quantity'=>'2',
            'reason'=>'spoiled','notes'=>'Removed compromised stock while it remained on hold.',
            'idempotency_key'=>(string)Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('packing_waste_entries',1);
        $this->assertSame('in_progress',$openJob->fresh()->status);
    }

    public function test_quarantined_stock_does_not_inflate_available_dashboard_or_reorder_balance():void
    {
        $c=$this->company('HELDALERT');
        $this->as($c,'owner')->get(route('stock.index'))
            ->assertOk()->assertDontSee('Low-stock warnings');
        $this->as($c,'warehouse')->post(route('stock.quality.change',[
            'batch'=>$c['batch']->id,'action'=>'hold',
        ]),['reason'=>'Inspection found incompatible storage temperatures.'])
            ->assertSessionHasNoErrors();
        $this->get(route('stock.index'))
            ->assertOk()->assertSee('Low-stock warnings')
            ->assertSee('Available to pack')->assertSee('QUALITY HOLD')
            ->assertSee('Unpacked stock and quality holds');
        $ownerMembership=\App\Models\BusinessMembership::query()
            ->where('business_id',$c['business']->id)
            ->where('user_id',$c['roles']['owner']->id)->firstOrFail();
        $cards=app(\App\Services\WorkspaceMetrics::class)->overview($ownerMembership);
        $stockCard=collect($cards)->firstWhere('label','Batches ready for packing');
        $this->assertSame(0,$stockCard['value']);
    }

    public function test_mixed_released_and_quarantined_batches_use_only_cleared_quantities():void
    {
        $c=$this->company('MIXEDQUALITY');
        // Product warning point is 20 kg; the original 100 kg remains physically
        // in the warehouse but must not be counted once on a quality hold.
        $free=StockBatch::create([
            'business_id'=>$c['business']->id,'product_id'=>$c['product']->id,
            'batch_code'=>'BATCH-RELEASED',
            'received_on'=>now()->toDateString(),
        ]);
        StockMovement::create([
            'business_id'=>$c['business']->id,'batch_id'=>$free->id,
            'movement_type'=>'receipt','quantity'=>'5.000','unit'=>'kg',
            'source_type'=>'goods_receipt','source_id'=>2,
            'created_by'=>$c['roles']['owner']->id,
        ]);
        $this->as($c,'warehouse')->post(route('stock.quality.change',[
            'batch'=>$c['batch']->id,'action'=>'hold',
        ]),['reason'=>'Uncleared lots cannot be packed pending investigation.'])
            ->assertSessionHasNoErrors();

        $warehousePage=$this->get(route('stock.index'))->assertOk()
            ->assertSee('Low-stock warnings')
            ->assertSee('QUALITY HOLD')
            ->assertSee('BATCH-RELEASED');
        // PDO returns decimal display scales differently on PostgreSQL and
        // SQLite; assert the actual 5/20 mathematical warning regardless.
        $this->assertMatchesRegularExpression(
            '/5(?:\.0+)?\s*\/\s*20(?:\.0+)? kg \(available \/ warning level\)/',
            $warehousePage->getContent()
        );
        $member=BusinessMembership::query()
            ->where('business_id',$c['business']->id)
            ->where('user_id',$c['roles']['warehouse']->id)->firstOrFail();
        $cards=app(\App\Services\WorkspaceMetrics::class)->overview($member);
        $this->assertSame(1,collect($cards)
            ->firstWhere('label','Batches ready for packing')['value']);

        $this->as($c,'owner')->post(route('stock.quality.change',[
            'batch'=>$c['batch']->id,'action'=>'release',
        ]),['reason'=>'Management validated completed quality reinspection.'])
            ->assertSessionHasNoErrors();
        $this->get(route('stock.index'))->assertOk()
            ->assertDontSee('Low-stock warnings');
        $this->assertSame(2,collect(app(\App\Services\WorkspaceMetrics::class)
            ->overview($member))->firstWhere('label','Batches ready for packing')['value']);
    }

    public function test_stock_alerts_are_scoped_and_product_reorder_point_is_editable():void
    {
        $c=$this->company('ALERT');
        $this->as($c,'owner')->put(route('products.update',$c['product']->id),[
            'sku'=>$c['product']->sku,'name'=>$c['product']->name,
            'base_unit'=>'kg','reorder_point'=>'130','active'=>1,
        ])->assertSessionHasNoErrors();
        $this->get(route('stock.index'))->assertOk()->assertSee('Low-stock warnings')
            ->assertSee('Fresh Mango');
        $this->assertDatabaseHas('products',['id'=>$c['product']->id,'reorder_point'=>130]);
        $this->as($c,'warehouse')->get(route('stock.index'))
            ->assertOk()->assertSee('Low-stock warnings');
    }
}
