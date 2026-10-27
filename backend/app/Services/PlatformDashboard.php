<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Business;
use App\Models\OnboardingRequest;
use App\Models\PlatformCase;
use App\Models\PlatformDelivery;
use App\Models\PlatformHealthEvidence;
use App\Models\PlatformUsageActivity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformDashboard
{
    public function overview(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.today('Africa/Kampala')->toDateString()]]);
        $from = Carbon::parse($data['from'] ?? today('Africa/Kampala')->subDays(29)->toDateString(), 'Africa/Kampala')->startOfDay();
        $to = Carbon::parse($data['to'] ?? today('Africa/Kampala')->toDateString(), 'Africa/Kampala')->endOfDay();
        abort_if($from->gt($to) || $from->diffInDays($to) > 366, 422, 'Select an ordered reporting period of up to one year.');
        $days = (int) $from->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $from->copy()->subDays($days);
        $paymentQuery = fn ($start, $end) => BillingPayment::query()->whereDate('received_on', '>=', $start->toDateString())->whereDate('received_on', '<=', $end->toDateString());
        $stats = [
            'businesses' => Business::query()->count(), 'security_suspended' => Business::query()->whereNotNull('security_suspended_at')->count(),
            'closed' => Business::query()->where('lifecycle_status', 'closed')->count(), 'onboarding' => Business::query()->where('lifecycle_status', 'onboarding')->count(),
            'pending_requests' => OnboardingRequest::query()->whereIn('status', ['new', 'contacted'])->count(),
            'trial' => Business::query()->where('billing_mode', 'trial')->whereDate('trial_ends_on', '>=', today('Africa/Kampala'))->count(),
            'legacy_review' => Business::query()->where('billing_mode', 'legacy_review')->count(),
            'collected_ugx' => (int) $paymentQuery($from, $to)->sum('amount_ugx'),
            'previous_collected_ugx' => (int) $paymentQuery($previousFrom, $previousTo)->sum('amount_ugx'),
            'outstanding_ugx' => (int) BillingInvoice::query()->where('status', '!=', 'void')->selectRaw('COALESCE(SUM(total_ugx-credit_ugx-paid_ugx+refunded_ugx),0) as total')->value('total'),
            'overdue_invoices' => BillingInvoice::query()->where('status', '!=', 'void')->whereDate('due_on', '<', today('Africa/Kampala'))->whereRaw('total_ugx-credit_ugx-paid_ugx+refunded_ugx>0')->count(),
            'active_users' => PlatformUsageActivity::query()->whereBetween('activity_on', [$from->toDateString(), $to->toDateString()])->distinct()->count('user_id'),
            'active_businesses' => PlatformUsageActivity::query()->whereBetween('activity_on', [$from->toDateString(), $to->toDateString()])->distinct()->count('business_id'),
            'unresolved_cases' => PlatformCase::query()->where('status', '!=', 'resolved')->count(),
        ];
        $recurring = 0;
        $restricted = 0;
        $active = 0;
        Business::query()->with('billingSubscriptions.plan')->chunkById(200, function ($businesses) use (&$recurring, &$restricted, &$active) {
            foreach ($businesses as $business) {
                $state = app(PlatformTenancy::class)->state($business);
                if ($state['state'] === 'restricted') {
                    $restricted++;
                }
                if ($state['state'] === 'active') {
                    $active++;
                }
                $s = $state['subscription'];
                if ($s && $state['state'] === 'active' && $s->billing_cycle !== 'one_time') {
                    $recurring += (int) round(((int) ($s->scheduled_change && $s->scheduled_change['effective_on'] <= today('Africa/Kampala')->toDateString() ? $s->scheduled_change['price_ugx'] : $s->price_ugx)) / match ($s->billing_cycle) {
                        'monthly' => 1,'quarterly' => 3,'annual' => 12,default => 1
                    });
                }
            }
        });
        $stats += ['monthly_recurring_ugx' => $recurring, 'billing_restricted' => $restricted, 'active_paid' => $active];
        $collections = app(PlatformAccess::class)->allows($request->user(), 'billing') ? $paymentQuery($from, $to)->with('invoice')->latest('id')->paginate(15) : collect();

        return ['stats' => $stats, 'from' => $from, 'to' => $to, 'previousFrom' => $previousFrom, 'previousTo' => $previousTo, 'measuredAt' => now('Africa/Kampala'),
            'cases' => PlatformCase::query()->where('status', '!=', 'resolved')->orderBy('due_at')->limit(10)->get(), 'collections' => $collections, ...$this->health(null)];
    }

    public function health(?int $business): array
    {
        $records = collect();
        foreach (['backup', 'restore', 'worker', 'storage', 'errors', 'release', 'service_cost'] as $metric) {
            $record = PlatformHealthEvidence::query()->where('business_id', $business)->where('metric', $metric)->orderByDesc('observed_at')->orderByDesc('id')->first();
            if ($record) {
                $records[$metric] = $record;
            }
        }
        $health = [];
        foreach (['backup', 'restore', 'worker', 'storage', 'errors', 'release', 'service_cost'] as $metric) {
            $e = $records->get($metric);
            $health[$metric] = ['status' => $e && $e->valid_until->isFuture() ? $e->status : 'unknown', 'evidence' => $e];
        }
        $scope = fn ($q) => $q->when($business !== null, fn ($q) => $q->where('business_id', $business));

        return ['health' => $health,
            'deliveryFailures' => $scope(PlatformDelivery::query())->whereIn('status', ['failed', 'uncertain'])->count(),
            'printerIssues' => $scope(DB::table('label_print_jobs'))->where(fn ($q) => $q->whereIn('status', ['failed', 'uncertain'])->orWhere(fn ($q) => $q->where('status', 'leased')->where('lease_until', '<', now())))->count(),
            'offlinePrinters' => $scope(DB::table('label_print_agents'))->where('active', true)->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes(5)))->count(),
            'queueFailures' => $business === null ? DB::table('failed_jobs')->count() : null];
    }
}
