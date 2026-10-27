<?php
namespace App\Http\Controllers;

use App\Models\{BillingInvoice,BillingPayment,BillingPaymentMethod,BillingPlan,BillingSubscription,Business,OnboardingRequest};
use App\Services\InviteBusinessUser;
use App\Services\OnboardingPackageMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BoostedOneBillingController extends Controller
{
    public function index()
    {
        return view('platform.billing', [
            'requests'=>OnboardingRequest::latest()->limit(40)->get(),
            'plans'=>BillingPlan::orderBy('id')->get(),
            'businesses'=>Business::orderBy('name')->get(),
            'methods'=>BillingPaymentMethod::latest()->get(),
            'subscriptions'=>BillingSubscription::with(['business','plan'])->latest()->limit(80)->get(),
            'invoices'=>BillingInvoice::latest()->limit(80)->get(),
        ]);
    }

    public function sendPackages(OnboardingRequest $application, OnboardingPackageMailer $mailer)
    {
        try {
            $mailer->send($application);
            return back()->with('status', 'Package information emailed to '.$application->contact_email.'.');
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withErrors(['email' => 'Package information could not be emailed. The application is saved; please check the mail service and retry.']);
        }
    }

    public function requestStatus(Request $request, OnboardingRequest $application, InviteBusinessUser $invites)
    {
        $data=$request->validate(['status'=>'required|in:contacted,declined,approved','admin_notes'=>'nullable|string|max:3000']);
        if ($application->business_id || $application->status==='approved') abort(409);
        if ($data['status']==='approved') {
            $business=DB::transaction(function () use ($application,$request,$data) {
                $code='BO'.strtoupper(Str::random(10));
                $business=Business::create(['name'=>$application->business_name,'code'=>$code,'country_code'=>'UG','base_currency'=>'UGX','timezone'=>'Africa/Kampala','default_qr_level'=>'batch']);
                $application->update(['business_id'=>$business->id,'status'=>'approved','reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),'admin_notes'=>$data['admin_notes'] ?? null]);
                return $business;
            });
            $invites->send($business,$application->contact_email,'owner',$request->user()->id);
        } else {
            $application->update(['status'=>$data['status'],'admin_notes'=>$data['admin_notes'] ?? null,'reviewed_by'=>$request->user()->id,'reviewed_at'=>now()]);
        }
        return back()->with('status','Onboarding request updated.');
    }

    public function plan(Request $request)
    {
        $data=$request->validate([
            'code'=>'required|alpha_dash|max:40|unique:billing_plans,code',
            'name'=>'required|string|max:120','description'=>'nullable|string|max:1500',
            'monthly_price_ugx'=>'nullable|integer|min:0','quarterly_price_ugx'=>'nullable|integer|min:0',
            'annual_price_ugx'=>'nullable|integer|min:0',
        ]);
        BillingPlan::create($data+['is_public'=>true,'is_active'=>true]);
        return back()->with('status','Subscription plan created.');
    }

    public function method(Request $request)
    {
        $data=$request->validate([
            'label'=>'required|string|max:120','type'=>'required|in:bank,mobile_money,other',
            'provider'=>'required|string|max:120','account_name'=>'required|string|max:150',
            'account_number'=>'required|string|max:120','instructions'=>'nullable|string|max:1500',
        ]);
        BillingPaymentMethod::create($data);
        return back()->with('status','Payment instructions saved.');
    }

    public function subscribe(Request $request)
    {
        $data=$request->validate([
            'business_id'=>'required|exists:businesses,id','billing_plan_id'=>'required|exists:billing_plans,id',
            'billing_email'=>'required|email|max:255',
            'billing_cycle'=>'required|in:monthly,quarterly,annual,one_time',
            'price_ugx'=>'required|integer|min:0|max:999999999999',
            'tax_rate'=>'required|integer|min:0|max:100',
            'starts_on'=>'required|date',
            'agreement_notes'=>'nullable|string|max:3000',
        ]);
        $data['billing_email']=strtolower($data['billing_email']);
        $data['status']='pending';
        $data['next_invoice_on']=$data['starts_on'];
        BillingSubscription::create($data);
        return back()->with('status','Commercial agreement created. Issue the first invoice after confirming terms.');
    }

    public function invoice(Request $request, BillingSubscription $subscription)
    {
        $data=$request->validate([
            'billing_payment_method_id'=>'required|exists:billing_payment_methods,id',
            'due_on'=>'required|date','notes'=>'nullable|string|max:3000',
        ]);
        $method=BillingPaymentMethod::findOrFail($data['billing_payment_method_id']);
        abort_unless($method->is_active,422);
        abort_if($subscription->status==='cancelled',409);
        if (! $subscription->next_invoice_on) abort(409);
        if ($subscription->invoices()->where('status','!=','void')->where('paid_ugx','<',DB::raw('total_ugx'))->exists()) {
            throw ValidationException::withMessages(['subscription'=>'A previous invoice is still outstanding. Settle it before issuing the next period.']);
        }
        $start=$subscription->billing_cycle==='one_time'
            ? $subscription->starts_on->copy()
            : $subscription->next_invoice_on->copy();
        $months=match($subscription->billing_cycle) {
            'monthly'=>1, 'quarterly'=>3, 'annual'=>12, default=>0,
        };
        $end=$months ? $start->copy()->addMonthsNoOverflow($months)->subDay() : null;
        $subtotal=(int)$subscription->price_ugx;
        $tax=(int)intdiv($subtotal*(int)$subscription->tax_rate,100);
        $invoice=DB::transaction(function () use ($subscription,$method,$data,$start,$end,$months,$subtotal,$tax,$request) {
            $invoice=BillingInvoice::create([
                'business_id'=>$subscription->business_id,'billing_subscription_id'=>$subscription->id,
                'issued_by'=>$request->user()->id,'billing_payment_method_id'=>$method->id,
                'business_name'=>$subscription->business->name,'billing_email'=>$subscription->billing_email,
                'plan_name'=>$subscription->plan->name,'billing_cycle'=>$subscription->billing_cycle,
                'period_start'=>$start,'period_end'=>$end,'due_on'=>$data['due_on'],
                'payment_details'=>$method->snapshot(),'subtotal_ugx'=>$subtotal,'tax_ugx'=>$tax,
                'total_ugx'=>$subtotal+$tax,'issued_at'=>now(),'notes'=>$data['notes'] ?? null,
            ]);
            $invoice->update(['invoice_number'=>'BO-'.now()->format('Y').'-'.str_pad($invoice->id,6,'0',STR_PAD_LEFT)]);
            $subscription->update(['next_invoice_on'=>$months ? $start->copy()->addMonthsNoOverflow($months)->toDateString() : null]);
            return $invoice;
        });
        return redirect()->route('platform.billing.invoice.show',$invoice)->with('status','Invoice issued. Send it to the client after verifying the payment details.');
    }

    public function show(BillingInvoice $invoice)
    {
        $invoice->load(['business','subscription.plan','payments']);
        return view('billing.invoice',compact('invoice'));
    }

    public function email(BillingInvoice $invoice)
    {
        abort_if($invoice->voided_at,409);
        Mail::to($invoice->billing_email)->send(new \App\Mail\BoostedOneInvoiceMail($invoice));
        $invoice->update(['sent_at'=>now()]);
        return back()->with('status','Invoice emailed.');
    }

    public function pay(Request $request, BillingInvoice $invoice)
    {
        $data=$request->validate([
            'billing_payment_method_id'=>'required|exists:billing_payment_methods,id',
            'amount_ugx'=>'required|integer|min:1',
            'reference'=>'required|string|min:3|max:150',
            'received_on'=>'required|date|before_or_equal:today',
            'notes'=>'nullable|string|max:2000',
        ]);
        DB::transaction(function () use ($invoice,$data,$request) {
            $locked=BillingInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status==='void' || $locked->status==='paid' || $locked->remaining()<$data['amount_ugx']) {
                throw ValidationException::withMessages(['amount_ugx'=>'Payment exceeds the outstanding amount or this invoice is void.']);
            }
            BillingPayment::create($data+['billing_invoice_id'=>$locked->id,'verified_by'=>$request->user()->id]);
            $locked->paid_ugx+=$data['amount_ugx'];
            $locked->status=$locked->remaining()===0 ? 'paid' : 'partial';
            $locked->save();
            $subscription=BillingSubscription::whereKey($locked->billing_subscription_id)->lockForUpdate()->firstOrFail();
            if ($locked->status==='paid') {
                $paidThrough=$locked->period_end ?? $locked->period_start;
                if (! $subscription->paid_through || $paidThrough->greaterThan($subscription->paid_through)) {
                    $subscription->paid_through=$paidThrough;
                }
                $subscription->status='active';
                $subscription->save();
            }
        });
        return back()->with('status','Verified payment recorded.');
    }

    public function customer()
    {
        $business=request()->attributes->get('activeBusiness');
        $membership=request()->attributes->get('membership');
        abort_unless(in_array($membership->role,['owner','admin','accountant'],true),403);
        return view('billing.customer',[
            'subscriptions'=>BillingSubscription::with('plan')->where('business_id',$business->id)->latest()->get(),
            'invoices'=>BillingInvoice::where('business_id',$business->id)->latest()->get(),
        ]);
    }

    public function customerInvoice(BillingInvoice $invoice)
    {
        $business=request()->attributes->get('activeBusiness');
        $membership=request()->attributes->get('membership');
        abort_unless(in_array($membership->role,['owner','admin','accountant'],true),403);
        abort_unless($invoice->business_id===$business->id,404);
        return $this->show($invoice);
    }
}
