<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\BusinessMembership;
use App\Models\PlatformUsageActivity;
use App\Models\PlatformUsageSnapshot;
use App\Models\ProductImage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformUsage
{
    public function seats(Business $business): array
    {
        $active = BusinessMembership::query()->where('business_id', $business->id)->where('active', true)->count();
        $pending = BusinessInvitation::query()->where('business_id', $business->id)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('business_memberships')->join('users', 'users.id', '=', 'business_memberships.user_id')
                ->whereColumn('business_memberships.business_id', 'business_invitations.business_id')->whereColumn('users.email', 'business_invitations.email')->where('business_memberships.active', true))
            ->distinct()->count('email');

        return ['active_seats' => $active, 'reserved_seats' => $pending];
    }

    public function assertSeat(Business $business, string $email, ?int $excludeInvitation = null, ?int $replaceId = null): void
    {
        $existing = BusinessMembership::query()->where('business_id', $business->id)->where('active', true)->whereHas('user', fn ($q) => $q->where('email', $email))->exists();
        if ($existing) {
            return;
        }
        $limit = app(PlatformTenancy::class)->allowance($business, 'seat_limit');
        if ($limit === null) {
            return;
        }
        $seats = $this->seats($business);
        $reserved = BusinessInvitation::query()->where('business_id', $business->id)->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
        $needed = $seats['active_seats'] + $seats['reserved_seats'] + ($reserved ? 0 : 1);
        if ($excludeInvitation) {
            $replaceId = BusinessInvitation::query()->where('business_id', $business->id)->whereKey($excludeInvitation)->value('replaces_membership_id');
        }
        if ($replaceId && BusinessMembership::query()->where('business_id', $business->id)->whereKey($replaceId)->where('active', true)->where('role', 'owner')->exists()) {
            $needed--;
        }
        if ($needed > $limit) {
            throw ValidationException::withMessages(['email' => 'The business has reached its staff-seat allowance, including pending invitations. Ask the owner to remove unused access or arrange a dated allowance change.']);
        }
    }

    public function media(Business $business): array
    {
        return ['media_bytes' => (int) ProductImage::query()->where('business_id', $business->id)->sum('stored_bytes'),
            'unknown_media_files' => ProductImage::query()->where('business_id', $business->id)->whereNull('stored_bytes')->count()];
    }

    public function assertMedia(Business $business, int $extra): void
    {
        $limit = app(PlatformTenancy::class)->allowance($business, 'storage_limit_bytes');
        if ($limit === null) {
            return;
        }
        $media = $this->media($business);
        if ($media['unknown_media_files'] > 0 || $limit < $media['media_bytes'] + $extra) {
            throw ValidationException::withMessages(['photo' => 'Product-media storage is at its allowance or older media still needs measurement. Existing records remain available; ask the owner or support to review usage.']);
        }
    }

    public function counters(Business $business, string $from, string $to): array
    {
        $start = Carbon::parse($from, 'Africa/Kampala')->startOfDay()->utc();
        $end = Carbon::parse($to, 'Africa/Kampala')->endOfDay()->utc();
        $counts = [...$this->seats($business), ...$this->media($business)];
        $counts['active_users'] = PlatformUsageActivity::query()->where('business_id', $business->id)->whereBetween('activity_on', [$from, $to])->distinct()->count('user_id');
        $counts['activity_tracking_since'] = PlatformUsageActivity::query()->where('business_id', $business->id)->min('activity_on');
        foreach (['orders' => ['sales_orders', 'confirmed_at'], 'receipts' => ['goods_receipts', 'created_at'], 'cartons' => ['cartons', 'created_at'], 'shipments' => ['export_shipments', 'created_at'], 'integration_events' => ['external_provider_events', 'created_at']] as $key => [$table,$date]) {
            $counts[$key] = DB::table($table)->where('business_id', $business->id)->whereBetween($date, [$start, $end])->count();
        }
        $driver = DB::connection()->getDriverName();
        $counts['generated_artifact_bytes'] = 0;
        foreach (['label_generations' => ['zpl_snapshot', 'artwork_png'], 'export_documents' => ['html_snapshot'], 'commercial_invoices' => ['html_snapshot']] as $table => $columns) {
            foreach ($columns as $column) {
                $expression = $driver === 'pgsql' ? 'OCTET_LENGTH('.$column.')' : 'LENGTH(CAST('.$column.' AS BLOB))';
                $counts['generated_artifact_bytes'] += (int) DB::table($table)->where('business_id', $business->id)->selectRaw('COALESCE(SUM('.$expression.'),0) as bytes')->value('bytes');
            }
        }
        $counts['invitation_delivery_states'] = DB::table('platform_deliveries')->where('business_id', $business->id)->whereBetween('created_at', [$start, $end])->selectRaw('status,COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $counts['measured_at'] = now()->toIso8601String();

        return $counts;
    }

    public function snapshot(Business $business, string $from, string $to): PlatformUsageSnapshot
    {
        return DB::transaction(function () use ($business, $from, $to) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $snapshot = PlatformUsageSnapshot::query()->where('business_id', $business->id)->whereDate('period_start', $from)->whereDate('period_end', $to)->first() ?? new PlatformUsageSnapshot(['business_id' => $business->id, 'period_start' => $from, 'period_end' => $to]);
            $snapshot->fill(['counters' => $this->counters($business, $from, $to), 'measured_at' => now()])->save();

            return $snapshot;
        }, 3);
    }

    public function record(Business $business, int $userId): void
    {
        DB::table('platform_usage_activities')->upsert([['business_id' => $business->id, 'user_id' => $userId, 'activity_on' => today('Africa/Kampala')->toDateString(), 'last_seen_at' => now()]],['business_id', 'user_id', 'activity_on'],['last_seen_at']);
    }
}
