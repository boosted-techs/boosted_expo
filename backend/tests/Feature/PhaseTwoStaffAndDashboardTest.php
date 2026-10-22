<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\ExportShipment;
use App\Models\FinancePostingRequest;
use App\Models\SalesOrder;
use App\Models\StaffAttendance;
use App\Models\StaffShift;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseTwoStaffAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $code): Business
    {
        return Business::create([
            'name'=>$code.' Exports','code'=>$code,'country_code'=>'UG',
            'base_currency'=>'UGX','timezone'=>'Africa/Kampala',
            'default_qr_level'=>'batch',
        ]);
    }

    private function join(Business $business,string $role):User
    {
        $user=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$business->id,'user_id'=>$user->id,
            'role'=>$role,'active'=>true,
        ]);
        return $user;
    }

    private function as(Business $b,User $u):self
    {
        return $this->actingAs($u)->withSession(['active_business_id'=>$b->id]);
    }

    public function test_shift_schedule_rejects_overlaps_and_cross_business_assignment():void
    {
        $a=$this->business('A');$b=$this->business('B');
        $hr=$this->join($a,'hr');$employee=$this->join($a,'warehouse');
        $outsider=$this->join($b,'warehouse');
        $start=now()->timezone('Africa/Kampala')->addDays(2)->startOfHour();
        $payload=[
            'user_id'=>$employee->id,
            'starts_at'=>$start->format('Y-m-d\TH:i'),
            'ends_at'=>$start->copy()->addHours(7)->format('Y-m-d\TH:i'),
            'location'=>'Packing floor','notes'=>'Grade and label produce for export.',
        ];
        $this->as($a,$hr)->post(route('staff.schedule'),$payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $shift=StaffShift::firstOrFail();
        $this->assertSame('scheduled',$shift->status);
        $this->assertSame($employee->id,$shift->user_id);
        $this->post(route('staff.schedule'),$payload)->assertSessionHasErrors('starts_at');
        $this->post(route('staff.schedule'),[
            ...$payload,'user_id'=>$outsider->id,
        ])->assertNotFound();
        $this->assertDatabaseCount('staff_shifts',1);

        $this->as($a,$employee)->post(route('staff.schedule'),$payload)
            ->assertForbidden();
        $this->as($b,$outsider)->post(route('staff.cancel',$shift->id),[
            'reason'=>'Cross-tenant management must be rejected.',
        ])->assertForbidden();
    }

    public function test_employee_can_clock_in_once_clock_out_and_cannot_edit_another_worker():void
    {
        $a=$this->business('A');$b=$this->business('B');
        $staff=$this->join($a,'warehouse');$other=$this->join($a,'packing');
        $outsider=$this->join($b,'warehouse');
        $this->as($a,$staff)->get(route('staff.index'))->assertOk()
            ->assertSee('My attendance');
        $this->post(route('staff.clock-in'),[])->assertRedirect()
            ->assertSessionHasNoErrors();
        $record=StaffAttendance::firstOrFail();
        $this->assertNull($record->clocked_out_at);
        $this->post(route('staff.clock-in'),[])
            ->assertSessionHasErrors('attendance');
        $this->as($a,$other)->post(route('staff.clock-out',$record->id),[
            'work_note'=>'Cannot close another employee attendance.',
        ])->assertNotFound();
        $this->as($b,$outsider)->post(route('staff.clock-out',$record->id),[])
            ->assertNotFound();
        $this->as($a,$staff)->post(route('staff.clock-out',$record->id),[
            'work_note'=>'Received the crates and cleaned the packing floor.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($record->fresh()->clocked_out_at);
        $this->post(route('staff.clock-out',$record->id),[])
            ->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('staff_attendance_events',2);
        $this->assertDatabaseCount('finance_payroll_runs',0);
    }

    public function test_cancelled_shift_does_not_allow_check_in_and_cannot_cancel_used_shift():void
    {
        $a=$this->business('A');$owner=$this->join($a,'owner');
        $staff=$this->join($a,'sales');
        $start=now()->timezone('Africa/Kampala')->startOfMinute();
        $data=[
            'user_id'=>$staff->id,
            'starts_at'=>$start->format('Y-m-d\TH:i'),
            'ends_at'=>$start->copy()->addHours(3)->format('Y-m-d\TH:i'),
        ];
        $this->as($a,$owner)->post(route('staff.schedule'),$data)
            ->assertSessionHasNoErrors();
        $shift=StaffShift::firstOrFail();
        $this->as($a,$staff)->post(route('staff.clock-in'),[
            'shift_id'=>$shift->id,
        ])->assertSessionHasNoErrors();
        $this->as($a,$owner)->post(route('staff.cancel',$shift->id),[
            'reason'=>'Shift was already used by an employee.',
        ])->assertSessionHasErrors('shift');
        $this->as($a,$staff)->post(route('staff.clock-out',StaffAttendance::firstOrFail()->id),[])
            ->assertSessionHasNoErrors();
        $this->assertSame('completed',$shift->fresh()->status);

        $this->as($a,$owner)->post(route('staff.schedule'),[
            ...$data,
            'starts_at'=>$start->copy()->addDay()->format('Y-m-d\TH:i'),
            'ends_at'=>$start->copy()->addDay()->addHours(3)->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();
        $next=StaffShift::orderByDesc('id')->firstOrFail();
        $this->post(route('staff.cancel',$next->id),[
            'reason'=>'Transport was rearranged, so this shift is cancelled.',
        ])->assertSessionHasNoErrors();
        $this->as($a,$staff)->post(route('staff.clock-in'),[
            'shift_id'=>$next->id,
        ])->assertSessionHasErrors('shift_id');
    }

    public function test_metrics_are_real_and_no_sales_or_finance_data_leaks_to_viewer():void
    {
        $a=$this->business('A');$b=$this->business('B');
        $owner=$this->join($a,'owner');$viewer=$this->join($a,'viewer');
        $otherOwner=$this->join($b,'owner');
        $customer=\App\Models\Customer::create([
            'business_id'=>$a->id,'customer_code'=>'ONE','name'=>'Buyer A',
            'country_code'=>'AE','currency'=>'USD','active'=>true,
        ]);
        $product=\App\Models\Product::create([
            'business_id'=>$a->id,'sku'=>'TEST','name'=>'Pineapple','base_unit'=>'kg',
        ]);
        $request=\App\Models\SalesRequest::create([
            'business_id'=>$a->id,'reference'=>'REQ-'.Str::uuid(),
            'customer_id'=>$customer->id,'status'=>'open','created_by'=>$owner->id,
        ]);
        $quotation=\App\Models\SalesQuotation::create([
            'business_id'=>$a->id,'reference'=>'QUO-'.Str::uuid(),
            'sales_request_id'=>$request->id,
            'status'=>'confirmed','revision'=>1,'currency'=>'USD','incoterm'=>'FOB',
            'subtotal'=>'50','discount_percent'=>'0','discount_amount'=>'0',
            'tax_percent'=>'0','tax_amount'=>'0','grand_total'=>'50',
            'created_by'=>$owner->id,
        ]);
        SalesOrder::create([
            'business_id'=>$a->id,'reference'=>'SO-ONE',
            'sales_request_id'=>$request->id,'sales_quotation_id'=>$quotation->id,
            'customer_id'=>$customer->id,'status'=>'confirmed','currency'=>'USD',
            'incoterm'=>'FOB','discount_percent'=>'0','tax_percent'=>'0',
            'subtotal'=>'50','discount_amount'=>'0','tax_amount'=>'0',
            'grand_total'=>'50','buyer_confirmation'=>'Test confirmation with buyer',
            'confirmed_by'=>$owner->id,'confirmed_at'=>now(),
        ]);
        $batch=StockBatch::create([
            'business_id'=>$a->id,'product_id'=>$product->id,
            'batch_code'=>'BATCH-TEST','received_on'=>now()->toDateString(),
        ]);
        StockMovement::create([
            'business_id'=>$a->id,'batch_id'=>$batch->id,'movement_type'=>'receipt',
            'quantity'=>'20','unit'=>'kg','source_type'=>'test_receipt',
            'source_id'=>1,'created_by'=>$owner->id,
        ]);
        $this->as($a,$owner)->get(route('workspace'))
            ->assertOk()->assertSee('Confirmed customer orders')->assertSee('Batches ready for packing')
            ->assertSee('Finance reviews pending');
        $this->as($a,$viewer)->get(route('workspace'))
            ->assertOk()->assertDontSee('Confirmed customer orders')
            ->assertDontSee('Finance reviews pending')
            ->assertDontSee('Batches ready for packing');
        $this->as($b,$otherOwner)->get(route('workspace'))
            ->assertOk()->assertSee('Confirmed customer orders')
            ->assertSee('Shipments preparing');
    }
}
