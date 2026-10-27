<?php

namespace App\Services;

use App\Models\BillingAdjustment;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPaymentMethod;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Business;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformBilling
{
    public function planTerms(BillingPlan $plan): array
    {
        return $plan->only(['id', 'code', 'family_code', 'version', 'name', 'modules', 'seat_limit', 'storage_limit_bytes']);
    }

    public function invoice(BillingSubscription $subscription, BillingPaymentMethod $method, array $data, ?int $actor): BillingInvoice
    {
        return DB::transaction(function () use ($subscription, $method, $data, $actor) {
            Business::query()->lockForUpdate()->findOrFail($subscription->business_id);
            $s = BillingSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if (isset($data['period_start'])) {
                $existing = BillingInvoice::query()->where('billing_subscription_id', $s->id)->whereDate('period_start', $data['period_start'])->first();
                if ($existing) {
                    return $existing;
                }
                abort_unless($s->next_invoice_on?->toDateString() === $data['period_start'], 409, 'The next invoice period changed. Refresh the agreement before issuing.');
            }
            abort_if($s->cancelled_at || $s->paused_at || ! $method->fresh()->is_active || ! $s->next_invoice_on, 409, 'This agreement is paused, cancelled, complete or has no active payment instructions.');
            $start = $s->next_invoice_on->copy();
            $existing = BillingInvoice::query()->where('billing_subscription_id', $s->id)->whereDate('period_start', $start)->first();
            if ($existing) {
                return $existing;
            }
            if ($s->invoices()->where('status', '!=', 'void')->whereRaw('total_ugx - credit_ugx - paid_ugx + refunded_ugx > 0')->exists()) {
                throw ValidationException::withMessages(['subscription' => 'Settle or correct the earlier outstanding invoice before issuing the next period.']);
            }
            $terms = $s->terms_snapshot ?? $this->planTerms($s->plan);
            $price = (int) $s->price_ugx;
            $taxRate = (int) $s->tax_rate;
            $cycle = $s->billing_cycle;
            if ($s->scheduled_change && Carbon::parse($s->scheduled_change['effective_on'])->lte($start)) {
                $terms = $s->scheduled_change['terms'];
                $price = (int) $s->scheduled_change['price_ugx'];
                $taxRate = (int) $s->scheduled_change['tax_rate'];
            }
            $months = match ($cycle) {
                'monthly' => 1,'quarterly' => 3,'annual' => 12,default => 0
            };
            $end = $months ? $start->copy()->addMonthsNoOverflow($months)->subDay() : null;
            $tax = intdiv($price * $taxRate, 100);
            $invoice = BillingInvoice::create(['business_id' => $s->business_id, 'billing_subscription_id' => $s->id, 'issued_by' => $actor, 'billing_payment_method_id' => $method->id,
                'business_name' => $s->business->name, 'billing_email' => $s->billing_email, 'plan_name' => $terms['name'], 'billing_cycle' => $cycle, 'period_start' => $start, 'period_end' => $end,
                'due_on' => $data['due_on'], 'payment_details' => $method->snapshot(), 'subtotal_ugx' => $price, 'tax_ugx' => $tax, 'total_ugx' => $price + $tax, 'issued_at' => now(), 'notes' => $data['notes'] ?? null,
                'terms_snapshot' => [...$terms, 'price_ugx' => $price, 'tax_rate' => $taxRate, 'billing_cycle' => $cycle]]);
            $invoice->update(['invoice_number' => 'BO-'.now('Africa/Kampala')->format('Y').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)]);
            $s->update(['next_invoice_on' => $months ? $start->copy()->addMonthsNoOverflow($months)->toDateString() : null]);
            app(PlatformAccess::class)->audit($s->business_id, $actor, 'invoice.issued', $data['notes'] ?? 'Agreed period invoice issued.', [], ['invoice_id' => $invoice->id, 'period_start' => $start->toDateString(), 'total_ugx' => $invoice->total_ugx]);
            if ($invoice->total_ugx === 0) {
                $invoice->update(['status' => 'paid']);
                $this->reconcile($s);
            }

            return $invoice;
        }, 3);
    }

    public function reconcile(BillingSubscription $s): void
    {
        $paid = $s->invoices()->where('status', '!=', 'void')->whereRaw('total_ugx - credit_ugx - paid_ugx + refunded_ugx <= 0')->orderByDesc('period_start')->first();
        $through = $paid?->period_end ?? $paid?->period_start;
        if ($s->legacy_paid_through && (! $through || $s->legacy_paid_through->gt($through))) {
            $through = $s->legacy_paid_through;
        }
        $status = 'pending';
        $today = today('Africa/Kampala');
        if ($s->cancelled_at) {
            $status = 'cancelled';
        } elseif ($s->paused_at) {
            $status = 'paused';
        } elseif ($through && $s->starts_on->toDateString() <= $today->toDateString()) {
            $status = $s->billing_cycle === 'one_time' || $through->gte($today) ? 'active' : ($through->copy()->addDays($s->grace_days)->gte($today) ? 'grace' : 'expired');
        }
        $s->update(['paid_through' => $through, 'status' => $status]);
        if ($paid && $s->business->billing_mode === 'trial') {
            $s->business->update(['billing_mode' => 'subscribed']);
        }
    }

    public function pay(BillingInvoice $invoice, array $data, int $actor): void
    {
        DB::transaction(function () use ($invoice, $data, $actor) {
            Business::query()->lockForUpdate()->findOrFail($invoice->business_id);
            $s = BillingSubscription::query()->lockForUpdate()->findOrFail($invoice->billing_subscription_id);
            $i = BillingInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $duplicate = BillingPayment::query()->where('billing_payment_method_id', $data['billing_payment_method_id'])->where('reference', $data['reference'])->first();
            if ($duplicate) {
                if ($duplicate->billing_invoice_id !== $i->id || (int) $duplicate->amount_ugx !== (int) $data['amount_ugx']) {
                    throw ValidationException::withMessages(['reference' => 'This payment reference belongs to another payment.']);
                }

                return;
            }
            if ($i->status === 'void' || $i->remaining() < (int) $data['amount_ugx']) {
                throw ValidationException::withMessages(['amount_ugx' => 'The amount exceeds the outstanding balance or the invoice is void.']);
            }
            abort_unless(BillingPaymentMethod::query()->whereKey($data['billing_payment_method_id'])->where('is_active', true)->exists(), 422);
            BillingPayment::create([...$data, 'billing_invoice_id' => $i->id, 'verified_by' => $actor]);
            $i->paid_ugx += (int) $data['amount_ugx'];
            $i->status = $i->remaining() === 0 ? 'paid' : 'partial';
            $i->save();
            $this->reconcile($s);
            app(PlatformAccess::class)->audit($i->business_id, $actor, 'invoice.payment', $data['notes'] ?? 'Manual payment evidence verified.', [], ['invoice_id' => $i->id, 'amount_ugx' => (int) $data['amount_ugx'], 'reference' => $data['reference']]);
        }, 3);
    }

    public function adjust(BillingInvoice $invoice, array $data, int $actor): void
    {
        DB::transaction(function () use ($invoice, $data, $actor) {
            Business::query()->lockForUpdate()->findOrFail($invoice->business_id);
            $s = BillingSubscription::query()->lockForUpdate()->findOrFail($invoice->billing_subscription_id);
            $i = BillingInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $existing = BillingAdjustment::query()->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->billing_invoice_id !== $i->id || $existing->kind !== $data['kind'] || (int) $existing->amount_ugx !== (int) $data['amount_ugx']) {
                    throw ValidationException::withMessages(['request_key' => 'This correction key belongs to different evidence.']);
                }

                return;
            }
            $amount = (int) $data['amount_ugx'];
            if ($i->status === 'void' || ($data['kind'] === 'credit' && $amount > $i->remaining()) || ($data['kind'] === 'refund' && $amount > $i->paid_ugx - $i->refunded_ugx) || ($data['kind'] === 'void' && ($i->paid_ugx > 0 || $amount !== 0))) {
                throw ValidationException::withMessages(['amount_ugx' => 'This correction exceeds the eligible balance. Only unpaid invoices can be voided.']);
            }
            BillingAdjustment::create([...$data, 'billing_invoice_id' => $i->id, 'recorded_by' => $actor]);
            if ($data['kind'] === 'credit') {
                $i->credit_ugx += $amount;
            } elseif ($data['kind'] === 'refund') {
                $i->refunded_ugx += $amount;
            } else {
                $i->status = 'void';
                $i->voided_at = now();
            }
            if ($data['kind'] !== 'void') {
                $i->status = $i->remaining() === 0 ? 'paid' : ($i->paid_ugx - $i->refunded_ugx > 0 ? 'partial' : 'issued');
            }
            $i->save();
            $this->reconcile($s);
            app(PlatformAccess::class)->audit($i->business_id, $actor, 'invoice.'.$data['kind'], $data['reason'], [], ['invoice_id' => $i->id, 'amount_ugx' => $amount, 'evidence' => $data['evidence_reference']]);
        }, 3);
    }

    public function applyScheduled(BillingSubscription $s): void
    {
        DB::transaction(function () use ($s) {
            Business::query()->lockForUpdate()->findOrFail($s->business_id);
            $s = BillingSubscription::query()->lockForUpdate()->findOrFail($s->id);
            $change = $s->scheduled_change;
            if (! $change || $s->cancelled_at || $s->paused_at || Carbon::parse($change['effective_on'], 'Africa/Kampala')->isFuture()) {
                return;
            }
            $before = $s->only(['billing_plan_id', 'price_ugx', 'tax_rate']);
            $s->update(['billing_plan_id' => $change['terms']['id'], 'terms_snapshot' => $change['terms'], 'price_ugx' => $change['price_ugx'], 'tax_rate' => $change['tax_rate'], 'scheduled_change' => null]);
            app(PlatformAccess::class)->audit($s->business_id, null, 'subscription.change_effective', $change['reason'], $before, $s->only(array_keys($before)));
        }, 3);
    }
}
