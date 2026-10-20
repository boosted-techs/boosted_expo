<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\GoodsReceipt;
use App\Models\LabelGeneration;
use App\Models\LabelTemplate;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\QrIdentity;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockAvailability;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PackingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function exporter(string $code='A',string $role='owner'): array
    {
        $business = Business::create([
            'name'=>$code.' Exporter','code'=>$code,'country_code'=>'UG',
            'base_currency'=>'UGX','timezone'=>'Africa/Kampala','default_qr_level'=>'carton',
        ]);
        $user = User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$business->id,'user_id'=>$user->id,'role'=>$role,'active'=>true,
        ]);
        $product = Product::create([
            'business_id'=>$business->id,'sku'=>'AVO-01','name'=>'Hass Avocado','base_unit'=>'kg',
        ]);
        return [$business,$user,$product];
    }

    private function receive(Product $product, User $user, string $qty, string $farmer): StockBatch
    {
        $order = PurchaseOrder::create([
            'business_id'=>$product->business_id,
            'reference'=>'PO-'.Str::ulid(),'kind'=>'market','product_id'=>$product->id,
            'vendor_name'=>'Market vendor','purchased_on'=>'2026-10-20',
            'quantity'=>$qty,'unit_price'=>'2000','estimated_total'=>(string)BigDecimal::of($qty)->multipliedBy('2000'),
            'currency'=>'UGX','status'=>'ordered','idempotency_key'=>(string)Str::uuid(),
            'created_by'=>$user->id,
        ]);
        $this->actingAs($user)->post(route('receiving.store',$order->id),[
            'accepted_quantity'=>$qty,'rejected_quantity'=>'0',
            'received_on'=>'2026-10-20','farmer_code'=>$farmer,
            'idempotency_key'=>(string)Str::uuid(),
        ])->assertRedirect();
        return StockBatch::query()->where('business_id',$product->business_id)
            ->where('product_id',$product->id)->orderByDesc('id')->firstOrFail();
    }

    private function openJob(Product $product, User $user): PackingJob
    {
        $this->actingAs($user)->post(route('packing.store'),[
            'product_id'=>$product->id,'target_cartons'=>10,
        ])->assertRedirect();
        return PackingJob::query()->where('business_id',$product->business_id)->firstOrFail();
    }

    private function cartonForm(array $contents,array $override=[]): array
    {
        return array_merge([
            'packing_key'=>(string)Str::uuid(),'contents'=>$contents,
            'net_weight_kg'=>'40','gross_weight_kg'=>'42',
            'grade'=>'A','packaging_type'=>'Export carton',
        ],$override);
    }

    public function test_receiving_grading_mixed_cartons_and_stock_reconciliation(): void
    {
        [$business,$user,$product]=$this->exporter();
        $one=$this->receive($product,$user,'100','UG-FARM-01');
        $two=$this->receive($product,$user,'60','UG-FARM-02');
        $job=$this->openJob($product,$user);
        $contents=[
            ['batch_id'=>$one->id,'quantity'=>'25'],
            ['batch_id'=>$two->id,'quantity'=>'15'],
        ];
        $form=$this->cartonForm($contents);
        $this->post(route('packing.pack',$job->id),$form)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('packing.pack',$job->id),$form)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cartons',1);
        $this->assertDatabaseCount('carton_contents',2);
        $this->assertDatabaseCount('stock_movements',4); // two receipts, two packing deductions
        $carton=Carton::firstOrFail();
        $this->assertSame('packed',$carton->status);
        $this->assertSame($job->id,$carton->packing_job_id);
        $this->assertTrue(BigDecimal::of((string)$carton->tare_weight_kg)->isEqualTo('2'));
        $inventory=app(StockAvailability::class);
        $this->assertTrue($inventory->available($business->id,$one->id)->isEqualTo('75'));
        $this->assertTrue($inventory->available($business->id,$two->id)->isEqualTo('45'));
        $this->assertDatabaseHas('qr_identities',['level'=>'carton','record_id'=>$carton->id,'public_enabled'=>false]);

        $loss=['batch_id'=>$one->id,'quantity'=>'5','reason'=>'sorting_reject',
            'idempotency_key'=>(string)Str::uuid()];
        $this->post(route('packing.loss',$job->id),$loss)->assertRedirect();
        $this->post(route('packing.loss',$job->id),$loss)->assertRedirect();
        $this->assertDatabaseCount('packing_waste_entries',1);
        $this->assertTrue($inventory->available($business->id,$one->id)->isEqualTo('70'));
        $this->get(route('packing.show',$job->id))->assertOk()->assertSee('UG-FARM-01');
        $this->get(route('cartons.show',$carton->id))->assertOk()->assertSee('UG-FARM-02');

        $this->post(route('packing.complete',$job->id))->assertRedirect();
        $this->assertSame('completed',$job->fresh()->status);
        $this->post(route('packing.pack',$job->id),$this->cartonForm([
            ['batch_id'=>$one->id,'quantity'=>'40'],
        ]))->assertStatus(422);
        $this->post(route('packing.loss',$job->id),[
            'batch_id'=>$one->id,'quantity'=>'1','reason'=>'damaged',
            'idempotency_key'=>(string)Str::uuid(),
        ])->assertStatus(422);
    }

    public function test_prevents_overpacking_wrong_product_weights_and_cross_company(): void
    {
        [$business,$owner,$product]=$this->exporter('FIRST');
        $batch=$this->receive($product,$owner,'50','F-1');
        $job=$this->openJob($product,$owner);
        $this->post(route('packing.pack',$job->id),$this->cartonForm([
            ['batch_id'=>$batch->id,'quantity'=>'55'],
        ],['net_weight_kg'=>'55','gross_weight_kg'=>'56']))->assertSessionHasErrors('contents');
        $this->post(route('packing.pack',$job->id),$this->cartonForm([
            ['batch_id'=>$batch->id,'quantity'=>'20'],
        ],['net_weight_kg'=>'19']))->assertSessionHasErrors('net_weight_kg');
        $this->assertDatabaseCount('cartons',0);
        $this->assertDatabaseCount('carton_contents',0);
        $this->assertDatabaseCount('stock_movements',1);
        [$other,$otherUser,$otherProduct]=$this->exporter('SECOND');
        $this->actingAs($otherUser)->withSession(['active_business_id'=>$other->id])
            ->get(route('packing.show',$job->id))->assertNotFound();
        $this->post(route('packing.pack',$job->id),$this->cartonForm([
            ['batch_id'=>$batch->id,'quantity'=>'40'],
        ]))->assertNotFound();
        $this->get(route('cartons.index'))->assertOk()->assertDontSee($job->reference);
    }

    public function test_permissions_for_packing_staff_and_finance_are_separate(): void
    {
        [$business,$owner,$product]=$this->exporter('PERM');
        $packer=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$business->id,'user_id'=>$packer->id,'role'=>'packing','active'=>true,
        ]);
        $this->actingAs($packer)->get(route('packing.index'))->assertOk();
        $this->get(route('cartons.index'))->assertOk();
        $this->get(route('labels.index'))->assertOk();
        $this->post(route('labels.store'),[
            'name'=>'Unauthorised','width_mm'=>100,'height_mm'=>50,
            'qr_level'=>'none','include_barcode'=>true,'dpi'=>203,'visible_fields'=>[],
        ])->assertForbidden();
        $this->get(route('customers.index'))->assertForbidden();
        $this->get(route('purchases.index'))->assertForbidden();
        $this->get(route('workspace',['section'=>'finance']))->assertForbidden();
    }
}
