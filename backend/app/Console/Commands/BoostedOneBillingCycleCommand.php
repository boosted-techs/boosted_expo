<?php
namespace App\Console\Commands;
use App\Models\{BillingInvoice,BillingPaymentMethod,BillingSubscription};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
class BoostedOneBillingCycleCommand extends Command {
 protected $signature='boostedone:billing-cycle {--dry-run}';
 protected $description='Reconcile subscriptions, issue safe renewals and notify clients about unpaid invoices';
 public function handle():int {
  $today=today();
  BillingSubscription::whereNotIn('status',['cancelled'])->chunkById(100,function($subscriptions) use($today) {
   foreach($subscriptions as $s) {
    if($s->billing_cycle!=='one_time' && $s->next_invoice_on && $s->next_invoice_on->lte($today->copy()->addDays(14))) {
      $previous=BillingInvoice::where('billing_subscription_id',$s->id)->latest('id')->first();
      $hasDebt=BillingInvoice::where('billing_subscription_id',$s->id)->whereIn('status',['issued','partial','overdue'])->exists();
      $method=$previous?BillingPaymentMethod::find($previous->billing_payment_method_id):null;
      if(!$hasDebt && $method?->is_active) {
       if($this->option('dry-run')) {$this->line('Would generate renewal for agreement '.$s->id);continue;}
       DB::transaction(function() use($s,$method,$today) {
        $locked=BillingSubscription::whereKey($s->id)->lockForUpdate()->firstOrFail();
        $start=$locked->next_invoice_on;
        if(!$start || $locked->status==='cancelled' || BillingInvoice::where('billing_subscription_id',$locked->id)->whereIn('status',['issued','partial','overdue'])->exists()) return;
        $months=match($locked->billing_cycle){'monthly'=>1,'quarterly'=>3,'annual'=>12,default=>0};
        if(!$months) return;
        $end=$start->copy()->addMonthsNoOverflow($months)->subDay();
        if(BillingInvoice::where('billing_subscription_id',$locked->id)->whereDate('period_start',$start)->exists())return;
        $tax=intdiv($locked->price_ugx*$locked->tax_rate,100);
        $i=BillingInvoice::create([
         'business_id'=>$locked->business_id,'billing_subscription_id'=>$locked->id,
         'billing_payment_method_id'=>$method->id,'business_name'=>$locked->business->name,
         'billing_email'=>$locked->billing_email,'plan_name'=>$locked->plan->name,
         'billing_cycle'=>$locked->billing_cycle,'period_start'=>$start,'period_end'=>$end,
         'due_on'=>$start,'payment_details'=>$method->snapshot(),
         'subtotal_ugx'=>$locked->price_ugx,'tax_ugx'=>$tax,
         'total_ugx'=>$locked->price_ugx+$tax,'issued_at'=>now()
        ]);
        $i->update(['invoice_number'=>'BO-'.now()->format('Y').'-'.str_pad($i->id,6,'0',STR_PAD_LEFT)]);
        $locked->update(['next_invoice_on'=>$start->copy()->addMonthsNoOverflow($months)->toDateString()]);
       });
      }
    }
    $expiry=$s->paid_through;
    $state=($s->billing_cycle==='one_time') ? ($expiry?'active':'pending')
      : ($expiry && $expiry->gte($today) ? 'active'
      : ($expiry && $expiry->copy()->addDays($s->grace_days)->gte($today) ? 'grace'
      : ($expiry ? 'expired':'pending')));
    if($state!==$s->status && !$this->option('dry-run'))$s->update(['status'=>$state]);
   }
  });
  BillingInvoice::whereIn('status',['issued','partial'])->whereDate('due_on','<',$today)
    ->chunkById(100,function($invoices){foreach($invoices as $invoice) {
      if(!$this->option('dry-run'))$invoice->update(['status'=>'overdue']);
    }});
  return self::SUCCESS;
 }
}
