<?php
namespace Tests\Feature;

use App\Mail\SupplierRequestMail;
use App\Models\{Business,BusinessMembership,Customer,GoodsReceipt,Product,PurchaseOrder,StockBatch,StockMovement,Supplier,SupplierQuote,SupplyRequest,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcurementFlowTest extends TestCase
{
 use RefreshDatabase;
 private function setupBusiness(string $code='A',string $role='owner'): array
 {
  $business=Business::create(['name'=>$code.' Exporters','code'=>$code,'country_code'=>'UG','base_currency'=>'UGX','timezone'=>'Africa/Kampala','default_qr_level'=>'batch']);
  $user=User::factory()->create();
  BusinessMembership::create(['business_id'=>$business->id,'user_id'=>$user->id,'role'=>$role,'active'=>true]);
  $product=Product::create(['business_id'=>$business->id,'sku'=>'AVO-1','name'=>'Avocado','base_unit'=>'kg']);
  $supplier=Supplier::create(['business_id'=>$business->id,'supplier_code'=>'SUP-1','name'=>'Fresh Farms','kind'=>'company','email'=>$code.'@supplier.test']);
  return [$business,$user,$product,$supplier];
 }
 private function purchaseData(Product $product,array $changes=[]): array
 {
  return array_merge(['kind'=>'market','product_id'=>$product->id,'vendor_name'=>'Nakasero Traders',
   'market_location'=>'Nakasero','purchased_on'=>'2026-10-20','quantity'=>'500',
   'unit_price'=>'1500.25','currency'=>'UGX','idempotency_key'=>(string)Str::uuid()],$changes);
 }
 private function receiptData(array $changes=[]): array
 {
  return array_merge(['accepted_quantity'=>'400','rejected_quantity'=>'20','gross_weight_kg'=>'425',
   'tare_weight_kg'=>'5','farmer_code'=>'UG-001','received_on'=>'2026-10-20',
   'idempotency_key'=>(string)Str::uuid()],$changes);
 }
 public function test_market_purchase_receiving_partial_delivery_and_idempotent_replay(): void
 {
  [$b,$u,$p]=$this->setupBusiness();
  $this->actingAs($u);
  $data=$this->purchaseData($p);
  $this->post(route('purchases.store'),$data)->assertRedirect();
  $this->post(route('purchases.store'),$data)->assertRedirect();
  $purchase=PurchaseOrder::firstOrFail();
  $this->assertDatabaseCount('purchase_orders',1);
  $this->assertTrue(\Brick\Math\BigDecimal::of((string)$purchase->estimated_total)->isEqualTo('750125.00'));
  $this->assertDatabaseCount('stock_batches',0);
  $receipt=$this->receiptData();
  $this->post(route('receiving.store',$purchase->id),$receipt)->assertRedirect();
  $this->post(route('receiving.store',$purchase->id),$receipt)->assertRedirect();
  $this->assertDatabaseCount('goods_receipts',1);
  $this->assertDatabaseCount('stock_batches',1);
  $this->assertDatabaseCount('stock_movements',1);
  $this->assertTrue(\Brick\Math\BigDecimal::of((string)StockMovement::firstOrFail()->quantity)->isEqualTo('400'));
  $this->assertSame('part_received',$purchase->fresh()->status);
  $this->post(route('receiving.store',$purchase->id),$this->receiptData([
    'accepted_quantity'=>'80','rejected_quantity'=>'0','gross_weight_kg'=>null,'tare_weight_kg'=>null,
  ]))->assertRedirect();
  $this->assertSame('received',$purchase->fresh()->status);
  $this->assertDatabaseCount('stock_movements',2);
  $this->get(route('stock.index'))->assertOk()->assertSee('UG-001');
 }
 public function test_over_receiving_and_wrong_net_weight_cannot_post(): void
 {
  [$b,$u,$p]=$this->setupBusiness();$this->actingAs($u);
  $this->post(route('purchases.store'),$this->purchaseData($p,['quantity'=>'100']))->assertRedirect();
  $order=PurchaseOrder::firstOrFail();
  $this->post(route('receiving.store',$order->id),$this->receiptData())->assertSessionHasErrors();
  $this->post(route('receiving.store',$order->id),$this->receiptData([
    'accepted_quantity'=>'80','rejected_quantity'=>'10','gross_weight_kg'=>'100','tare_weight_kg'=>'5'
  ]))->assertSessionHasErrors();
  $this->assertDatabaseCount('goods_receipts',0);
  $this->assertDatabaseCount('stock_movements',0);
 }
 public function test_supplier_request_private_response_revisions_and_award(): void
 {
  Mail::fake();
  [$b,$u,$p,$supplier]=$this->setupBusiness();$this->actingAs($u);
  $this->post(route('supply-requests.store'),[
   'product_id'=>$p->id,'quantity'=>'200','currency'=>'UGX',
   'response_deadline'=>now()->addDays(3)->format('Y-m-d H:i:s'),
  ])->assertRedirect();
  $rfq=SupplyRequest::firstOrFail();
  $this->post(route('supply-requests.issue',$rfq->id),['supplier_ids'=>[$supplier->id]])->assertRedirect();
  $token=null;
  Mail::assertSent(SupplierRequestMail::class,function($m)use(&$token){$token=$m->plainToken;return true;});
  $this->assertNotNull($token);
  $this->get(route('supplier-offer.show',$token))->assertOk()->assertSee('Fresh Farms');
  $this->post(route('supplier-offer.store',$token),[
   'quantity'=>'190','unit_price'=>'1700','delivery_on'=>now()->addDay()->format('Y-m-d')
  ])->assertRedirect();
  $this->post(route('supplier-offer.store',$token),[
   'quantity'=>'180','unit_price'=>'1650','delivery_on'=>now()->addDay()->format('Y-m-d')
  ])->assertRedirect();
  $offer=SupplierQuote::firstOrFail();
  $this->assertSame(2,$offer->revision);
  $this->assertDatabaseCount('supplier_quote_revisions',2);
  $this->post(route('supply-requests.award',['rfq'=>$rfq->id,'quote'=>$offer->id]))->assertRedirect();
  $this->assertSame('awarded',$rfq->fresh()->status);
  $this->assertTrue(\Brick\Math\BigDecimal::of((string)PurchaseOrder::firstOrFail()->estimated_total)->isEqualTo('297000.00'));
  $this->post(route('supply-requests.award',['rfq'=>$rfq->id,'quote'=>$offer->id]))->assertStatus(422);
  $this->get(route('supplier-offer.show',$token))->assertNotFound();
 }
 public function test_company_isolation_and_role_permissions(): void
 {
  [$a,$owner,$product]=$this->setupBusiness('ONE');
  [$b,$other]=$this->setupBusiness('TWO');
  $this->actingAs($other)->withSession(['active_business_id'=>$b->id])->post(route('purchases.store'),$this->purchaseData($product))->assertNotFound();
  $this->actingAs($owner)->withSession(['active_business_id'=>$a->id])->post(route('purchases.store'),$this->purchaseData($product))->assertRedirect();
  $order=PurchaseOrder::firstOrFail();
  $this->actingAs($other)->withSession(['active_business_id'=>$b->id])->get(route('purchases.show',$order->id))->assertNotFound();
  $this->post(route('receiving.store',$order->id),$this->receiptData())->assertNotFound();
  $viewer=User::factory()->create();
  BusinessMembership::create(['business_id'=>$a->id,'user_id'=>$viewer->id,'role'=>'viewer']);
  $this->actingAs($viewer)->withSession(['active_business_id'=>$a->id])->get(route('purchases.index'))->assertForbidden();
  $this->post(route('purchases.store'),$this->purchaseData($product))->assertForbidden();
 }
 public function test_customer_and_supplier_directory_routes_work(): void
 {
  [$b,$u]=$this->setupBusiness();$this->actingAs($u);
  $this->post(route('customers.store'),[
   'customer_code'=>'DUB-1','name'=>'Dubai Produce','country_code'=>'AE','currency'=>'AED'
  ])->assertRedirect();
  $this->get(route('customers.index'))->assertOk()->assertSee('Dubai Produce');
  $this->get(route('suppliers.index'))->assertOk()->assertSee('Fresh Farms');
  $this->assertDatabaseCount('customers',1);
  $this->assertSame('AED',Customer::firstOrFail()->currency);
 }
}