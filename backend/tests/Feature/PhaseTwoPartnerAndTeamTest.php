<?php

namespace Tests\Feature;

use App\Mail\BuyerOrderTrackingMail;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BuyerOrderLink;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesQuotation;
use App\Models\SalesRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseTwoPartnerAndTeamTest extends TestCase
{
    use RefreshDatabase;

    private function setupCompany(string $code):array
    {
        $business=Business::create([
            'name'=>$code.' Exporters','code'=>$code,'country_code'=>'UG',
            'base_currency'=>'UGX','timezone'=>'Africa/Kampala','default_qr_level'=>'batch',
        ]);
        $owner=User::factory()->create();
        $admin=User::factory()->create();
        $sales=User::factory()->create();
        $warehouse=User::factory()->create();
        foreach(compact('owner','admin','sales','warehouse') as $role=>$user){
            BusinessMembership::create([
                'business_id'=>$business->id,'user_id'=>$user->id,
                'role'=>$role,'active'=>true,
            ]);
        }
        $customer=Customer::create([
            'business_id'=>$business->id,'customer_code'=>'B-'.Str::random(6),
            'name'=>'Buyer Co','email'=>'buyer-'.$code.'@example.test',
            'country_code'=>'AE','currency'=>'USD','active'=>true,
        ]);
        $product=Product::create([
            'business_id'=>$business->id,'sku'=>'AVOCADO',
            'name'=>'Avocado','base_unit'=>'kg',
        ]);
        $request=SalesRequest::create([
            'business_id'=>$business->id,'reference'=>'REQ-'.Str::ulid(),
            'customer_id'=>$customer->id,'status'=>'open','created_by'=>$owner->id,
        ]);
        $quote=SalesQuotation::create([
            'business_id'=>$business->id,'reference'=>'QUO-'.Str::ulid(),
            'sales_request_id'=>$request->id,'revision'=>1,'status'=>'confirmed',
            'currency'=>'USD','incoterm'=>'FCA','subtotal'=>'100.00',
            'discount_percent'=>'0','tax_percent'=>'0','discount_amount'=>'0',
            'tax_amount'=>'0','grand_total'=>'100.00','created_by'=>$owner->id,
        ]);
        $order=SalesOrder::create([
            'business_id'=>$business->id,'reference'=>'ORDER-'.Str::ulid(),
            'sales_request_id'=>$request->id,'sales_quotation_id'=>$quote->id,
            'customer_id'=>$customer->id,'status'=>'confirmed','currency'=>'USD',
            'incoterm'=>'FCA','discount_percent'=>'0','tax_percent'=>'0',
            'subtotal'=>'100.00','discount_amount'=>'0','tax_amount'=>'0',
            'grand_total'=>'100.00','buyer_confirmation'=>'Buyer accepted quote',
            'confirmed_by'=>$owner->id,'confirmed_at'=>now(),
        ]);
        SalesOrderLine::create([
            'business_id'=>$business->id,'sales_order_id'=>$order->id,
            'product_id'=>$product->id,'quantity'=>'10','unit_price'=>'10',
            'line_total'=>'100','product_name'=>'Avocado','product_sku'=>'AVOCADO','unit'=>'kg',
        ]);
        return compact('business','owner','admin','sales','warehouse','customer','product','order');
    }

    private function as(array $c,string $role):self
    {
        return $this->actingAs($c[$role])->withSession([
            'active_business_id'=>$c['business']->id,
        ]);
    }

    public function test_buyer_private_link_is_email_delivered_scoped_and_replaced():void
    {
        Mail::fake();
        $a=$this->setupCompany('FIRST');
        $b=$this->setupCompany('SECOND');
        $this->as($a,'warehouse')->post(route('sales-orders.buyer-link.share',$a['order']->id))
            ->assertForbidden();
        $this->as($b,'sales')->post(route('sales-orders.buyer-link.share',$a['order']->id))
            ->assertNotFound();

        $this->as($a,'sales')->post(route('sales-orders.buyer-link.share',$a['order']->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertSentCount(1);
        $sent=Mail::sent(BuyerOrderTrackingMail::class)->first();
        $first=$sent?->plainToken;
        $this->assertSame($a['order']->reference,$sent->orderReference);
        $this->assertSame(strtolower($a['customer']->email),$sent->to[0]['address']);
        $this->assertNotNull($first);
        $this->assertSame(64,strlen($first));
        $record=BuyerOrderLink::firstOrFail();
        $this->assertSame(hash('sha256',$first),$record->token_hash);
        $this->assertNotSame($first,$record->token_hash);
        $this->get(route('buyer-order.show',str_repeat('X',64)))
            ->assertNotFound();
        $this->get(route('buyer-order.show',$first))->assertOk()
            ->assertSee($a['order']->reference)->assertSee('Avocado')
            ->assertSee('USD')->assertDontSee($b['order']->reference)
            ->assertHeader('Referrer-Policy','no-referrer')
            ->assertHeader('Cache-Control','no-store, private');

        $this->as($a,'sales')->post(route('sales-orders.buyer-link.share',$a['order']->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($record->fresh()->revoked_at);
        $this->get(route('buyer-order.show',$first))->assertNotFound();
        $this->assertDatabaseCount('buyer_order_links',2);
    }

    public function test_buyer_link_is_revocable_expires_and_disables_when_buyer_email_changes():void
    {
        Mail::fake();
        $c=$this->setupCompany('FIRST');
        $this->as($c,'owner')->post(route('business.policies.update'),[
            'dispatch_approval_required'=>0,'buyer_link_days'=>1,
        ])->assertSessionHasNoErrors();
        $this->as($c,'sales')->post(route('sales-orders.buyer-link.share',$c['order']->id))
            ->assertSessionHasNoErrors();
        Mail::assertSentCount(1); // delivery remains simulated in local tests
        $mail=Mail::sent(BuyerOrderTrackingMail::class)->first();
        $plain=$mail->plainToken;
        $this->assertSame($c['business']->id,$c['order']->fresh()->business_id);
        $link=BuyerOrderLink::firstOrFail();
        $this->assertTrue($link->expires_at->isBefore(now()->addDays(2)));
        $this->as($c,'sales')->post(route('sales-orders.buyer-link.revoke',[
            'order'=>$c['order']->id,'link'=>$link->id,
        ]))->assertSessionHasNoErrors();
        $this->get(route('buyer-order.show',$plain))->assertNotFound();

        $this->as($c,'sales')->post(route('sales-orders.buyer-link.share',$c['order']->id))
            ->assertSessionHasNoErrors();
        $second=Mail::sent(BuyerOrderTrackingMail::class)->last()->plainToken;
        $this->get(route('buyer-order.show',$second))->assertOk();
        $c['customer']->update(['email'=>'different@example.test']);
        $this->get(route('buyer-order.show',$second))->assertNotFound();
        $c['customer']->update(['email'=>'buyer-FIRST@example.test']);
        $this->travel(2)->days();
        try{
            $this->get(route('buyer-order.show',$second))->assertNotFound();
        }finally{
            $this->travelBack();
        }
    }

    public function test_team_role_changes_are_audited_and_access_is_revoked_immediately():void
    {
        $a=$this->setupCompany('FIRST');$b=$this->setupCompany('SECOND');
        $target=BusinessMembership::query()->where('business_id',$a['business']->id)
            ->where('user_id',$a['warehouse']->id)->firstOrFail();
        $payload=['role'=>'sales','active'=>1,
            'reason'=>'Moved from warehouse to the customer team after training.'];
        $this->as($a,'warehouse')->put(route('team.members.update',$target->id),$payload)
            ->assertForbidden();
        $this->as($b,'owner')->put(route('team.members.update',$target->id),$payload)
            ->assertNotFound();
        $this->as($a,'owner')->put(route('team.members.update',$target->id),$payload)
            ->assertSessionHasNoErrors();
        $this->assertSame('sales',$target->fresh()->role);
        $this->assertDatabaseHas('team_membership_events',[
            'business_id'=>$a['business']->id,'membership_id'=>$target->id,
            'previous_role'=>'warehouse','new_role'=>'sales',
        ]);
        $this->as($a,'warehouse')->get(route('packing.index'))->assertForbidden();
        $this->get(route('sales-requests.index'))->assertOk();

        $this->as($a,'owner')->put(route('team.members.update',$target->id),[
            'role'=>'sales','active'=>0,
            'reason'=>'Company access suspended after handover of issued devices.',
        ])->assertSessionHasNoErrors();
        $this->as($a,'warehouse')->get(route('workspace'))
            ->assertRedirect(route('businesses.choose'));
        $this->as($a,'owner')->put(route('team.members.update',$target->id),[
            'role'=>'warehouse','active'=>1,
            'reason'=>'Account reactivated following independent employee review.',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('team_membership_events',3);
    }

    public function test_admin_cannot_elevate_self_or_manage_company_administrators():void
    {
        $c=$this->setupCompany('SECURITY');
        $target=BusinessMembership::query()->where('business_id',$c['business']->id)
            ->where('user_id',$c['admin']->id)->firstOrFail();
        $this->as($c,'admin')->put(route('team.members.update',$target->id),[
            'role'=>'sales','active'=>1,'reason'=>'Self changes must be forbidden.',
        ])->assertForbidden();
        $this->as($c,'owner')->put(route('team.members.update',
            BusinessMembership::where('business_id',$c['business']->id)
                ->where('user_id',$c['owner']->id)->firstOrFail()->id),[
            'role'=>'sales','active'=>0,
            'reason'=>'Even an owner must not demote themselves here.',
        ])->assertForbidden();
        $this->as($c,'admin')->put(route('team.members.update',
            BusinessMembership::where('business_id',$c['business']->id)
                ->where('user_id',$c['warehouse']->id)->firstOrFail()->id),[
            'role'=>'admin','active'=>1,
            'reason'=>'Only company owners can promote people to administrators.',
        ])->assertForbidden();
        $this->assertDatabaseCount('team_membership_events',0);
    }
}
