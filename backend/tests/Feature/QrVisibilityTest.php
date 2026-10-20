<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Product;
use App\Models\QrIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QrVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function setupOwner(): array
    {
        $business = Business::create([
            'name'=>'Export Test Business', 'code'=>'TEST',
            'country_code'=>'UG','base_currency'=>'UGX',
            'timezone'=>'Africa/Kampala','default_qr_level'=>'product',
        ]);
        $user = User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$business->id,'user_id'=>$user->id,
            'role'=>'owner','active'=>true,
        ]);
        $product = Product::create([
            'business_id'=>$business->id,'sku'=>'BEANS-001',
            'name'=>'Fresh Red Beans','description'=>'Naturally grown Ugandan red beans',
            'base_unit'=>'kg',
        ]);

        return [$business,$user,$product];
    }

    public function test_real_qr_png_and_public_page_require_opt_in(): void
    {
        [$business,$user,$product] = $this->setupOwner();
        $this->actingAs($user)->get(route('products.edit', $product->id))->assertOk();
        $qr = QrIdentity::firstOrFail();
        $this->get(route('products.qr.png', $product->id))->assertOk()->assertHeader('Content-Type','image/png');
        $this->get(route('qr.public', $qr->public_token))->assertNotFound();
        $this->put(route('products.qr.update', $product->id), [
            'public_enabled' => '1',
        ])->assertRedirect();
        $this->get(route('qr.public', $qr->public_token))->assertOk()->assertSee('Fresh Red Beans')
            ->assertSee('Naturally grown Ugandan red beans');
        $this->put(route('products.qr.update', $product->id), [
            'public_enabled' => '0',
        ])->assertRedirect();
        $this->get(route('qr.public', $qr->public_token))->assertNotFound();
    }

    public function test_batch_and_carton_qr_pages_support_separate_identities(): void
    {
        [$business,$user,$product] = $this->setupOwner();
        $batch = DB::table('stock_batches')->insertGetId([
            'business_id'=>$business->id,'product_id'=>$product->id,
            'batch_code'=>'BT-100','created_at'=>now(),'updated_at'=>now(),
        ]);
        $carton = DB::table('cartons')->insertGetId([
            'business_id'=>$business->id,'carton_code'=>'CT-200',
            'net_weight_kg'=>'20.000','status'=>'packed','created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach (['batch'=>$batch,'carton'=>$carton] as $level=>$recordId) {
            $token = (string) Str::uuid();
            QrIdentity::create([
                'business_id'=>$business->id,'level'=>$level,'record_id'=>$recordId,
                'public_token'=>$token,'public_enabled'=>true,
            ]);
            $response = $this->get(route('qr.public', $token))->assertOk();
            $response->assertSee($level==='batch' ? 'BT-100' : 'CT-200');
        }
    }

    public function test_another_business_cannot_change_product_visibility(): void
    {
        [$business,$user,$product] = $this->setupOwner();
        $another = Business::create([
            'name'=>'Another', 'code'=>'OTHER',
            'country_code'=>'UG', 'base_currency'=>'UGX',
            'timezone'=>'Africa/Kampala', 'default_qr_level'=>'batch',
        ]);
        $otherUser = User::factory()->create();
        BusinessMembership::create(['business_id'=>$another->id,'user_id'=>$otherUser->id,'role'=>'owner']);
        $this->actingAs($otherUser)->withSession(['active_business_id'=>$another->id])
            ->put(route('products.qr.update', $product->id), ['public_enabled'=>'1'])->assertNotFound();
        $this->assertDatabaseCount('qr_identities', 0);
    }
}
