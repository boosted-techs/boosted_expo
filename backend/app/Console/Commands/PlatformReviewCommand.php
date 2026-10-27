<?php

namespace App\Console\Commands;

use App\Models\BillingInvoice;
use App\Models\BillingPaymentMethod;
use App\Models\BillingSubscription;
use App\Models\Business;
use App\Models\PlatformCase;
use App\Models\PlatformDelivery;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\PlatformBilling;
use App\Services\PlatformMail;
use App\Services\PlatformTenancy;
use App\Services\PlatformUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PlatformReviewCommand extends Command
{
    protected $signature = 'platform:review {--dry-run : Inspect without writing or sending} {--notify : Deliver prepared notices through the configured transport} {--measure-media : Read sizes of legacy R2 media objects}';

    protected $description = 'Reconcile platform cover, renewals, usage warnings and assigned follow-ups without deleting tenant data';

    public function handle(PlatformBilling $billing, PlatformMail $mail, PlatformUsage $usage): int
    {
        if ($this->option('dry-run')) {
            $this->info('Read-only review: '.Business::query()->count().' businesses, '.BillingInvoice::query()->where('status', '!=', 'void')->whereRaw('total_ugx-credit_ugx-paid_ugx+refunded_ugx>0')->count().' outstanding invoices. No records changed; no messages sent.');

            return self::SUCCESS;
        }
        $today = today('Africa/Kampala');
        PlatformDelivery::query()->where('status', 'processing')->where('lease_until', '<', now())->update(['status' => 'uncertain', 'failure_note' => 'Delivery acknowledgement expired. Review before retrying.']);
        BillingSubscription::query()->whereNull('cancelled_at')->whereNull('paused_at')->chunkById(100, function ($subscriptions) use ($billing, $today) {
            foreach ($subscriptions as $s) {
                $billing->applyScheduled($s);
                $s->refresh();
                if ($s->next_invoice_on && $s->billing_cycle !== 'one_time' && $s->next_invoice_on->lte($today->copy()->addDays(14))) {
                    $previous = $s->invoices()->latest('id')->first();
                    $method = $previous ? BillingPaymentMethod::find($previous->billing_payment_method_id) : null;
                    if ($method?->is_active && ! $s->invoices()->where('status', '!=', 'void')->whereRaw('total_ugx-credit_ugx-paid_ugx+refunded_ugx>0')->exists()) {
                        $billing->invoice($s, $method, ['due_on' => $s->next_invoice_on->toDateString(), 'notes' => 'Agreed recurring renewal; no automatic payment collection.'], null);
                    }
                }
                $billing->reconcile($s->fresh());
            }
        });
        BillingInvoice::query()->where('status', '!=', 'void')->whereRaw('total_ugx-credit_ugx-paid_ugx+refunded_ugx>0')->whereDate('due_on', '<=', $today->copy()->addDays(7))->chunkById(100, function ($invoices) use ($today, $mail) {
            $assigned = User::query()->where('is_platform_admin', true)->where('platform_role', 'superadmin')->value('id');
            foreach ($invoices as $invoice) {
                if ($invoice->due_on->lt($today)) {
                    $invoice->update(['status' => 'overdue']);
                }
                $case = PlatformCase::query()->firstOrCreate(['deduplication_key' => 'renewal:'.$invoice->id], ['business_id' => $invoice->business_id, 'kind' => 'renewal', 'title' => 'Review renewal '.$invoice->invoice_number, 'details' => 'Outstanding platform invoice; review payment evidence and arrange renewal with the business owner.', 'assigned_to' => $assigned, 'due_at' => $invoice->due_on->copy()->endOfDay(), 'priority' => $invoice->due_on->lt($today) ? 'high' : 'normal']);
                $d = $mail->prepare($invoice->business_id, 'invoice_reminder', $invoice->id, $invoice->billing_email, ['message' => 'Your platform invoice has an outstanding balance. Review the invoice and contact the service team if you dispute it.'], 'reminder:'.$invoice->id.':'.$today->copy()->startOfWeek()->toDateString());
                if ($this->option('notify')) {
                    $mail->deliver($d);
                }
            }
        });
        Business::query()->where('is_active', true)->chunkById(100, function ($businesses) use ($today, $usage, $mail) {
            foreach ($businesses as $business) {
                if ($this->option('measure-media')) {
                    ProductImage::query()->where('business_id', $business->id)->whereNull('stored_bytes')->eachById(function ($image) {
                        try {
                            $bytes = Storage::disk('r2')->size($image->original_key) + Storage::disk('r2')->size($image->thumbnail_key);
                            $image->update(['stored_bytes' => $bytes]);
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    });
                }
                $snapshot = $usage->snapshot($business, $today->copy()->startOfMonth()->toDateString(), $today->toDateString());
                $state = app(PlatformTenancy::class)->state($business);
                $messages = [];
                if (in_array($state['state'], ['trial', 'legacy_review', 'exempt'], true) && $state['until']?->lte($today->copy()->addDays(7))) {
                    $messages[] = 'Your '.$state['state'].' access ends on '.$state['until']->toDateString().'. Please review your subscription with the service team.';
                }
                foreach (['seat_limit' => 'active_seats', 'storage_limit_bytes' => 'media_bytes'] as $allowance => $counter) {
                    $limit = app(PlatformTenancy::class)->allowance($business, $allowance);
                    if ($limit !== null && $snapshot->counters[$counter] >= ($limit * .8)) {
                        $messages[] = 'Your business is near or at its '.$allowance.' allowance. Existing records remain available; review unused capacity or a dated allowance change.';
                    }
                }
                $owners = $business->memberships()->where('active', true)->where('role', 'owner')->with('user')->get();
                foreach ($messages as $message) {
                    foreach ($owners as $owner) {
                        $d = $mail->prepare($business->id, 'usage_or_access', null, $owner->user->email, ['title' => 'Boosted One account review', 'message' => $message], 'usage:'.$business->id.':'.$owner->user_id.':'.substr(hash('sha256', $message), 0, 16).':'.$today->copy()->startOfWeek()->toDateString());
                        if ($this->option('notify')) {
                            $mail->deliver($d);
                        }
                    }
                }
            }
        });
        $this->info('Platform source counts, renewal follow-ups and access warnings reconciled. '.($this->option('notify') ? 'Configured delivery attempted; inspect recorded results.' : 'No email sent; notices are prepared for explicit delivery.'));

        return self::SUCCESS;
    }
}
