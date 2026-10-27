<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('platform_role', 20)->default('superadmin');
        });
        Schema::table('businesses', function (Blueprint $t) {
            $t->string('lifecycle_status', 20)->default('operating');
            $t->timestamp('security_suspended_at')->nullable();
            $t->string('billing_mode', 20)->default('legacy_review');
            $t->date('billing_review_due_on')->nullable();
            $t->date('trial_ends_on')->nullable();
            $t->date('billing_exempt_until')->nullable();
            $t->string('owner_contact_email')->nullable();
            $t->index(['lifecycle_status', 'is_active']);
        });
        DB::table('businesses')->update(['billing_review_due_on' => now('Africa/Kampala')->addDays(30)->toDateString()]);
        DB::table('businesses')->where('is_active', false)->update(['security_suspended_at' => now()]);
        DB::table('businesses')->whereIn('id', DB::table('billing_subscriptions')->select('business_id'))->update(['billing_mode' => 'subscribed']);
        Schema::table('billing_plans', function (Blueprint $t) {
            $t->string('family_code', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->json('modules')->nullable();
            $t->unsignedInteger('seat_limit')->nullable();
            $t->unsignedBigInteger('storage_limit_bytes')->nullable();
        });
        DB::table('billing_plans')->update(['family_code' => DB::raw('code')]);
        Schema::table('billing_subscriptions', function (Blueprint $t) {
            $t->json('terms_snapshot')->nullable();
            $t->json('scheduled_change')->nullable();
            $t->timestamp('paused_at')->nullable();
            $t->date('legacy_paid_through')->nullable();
        });
        DB::table('billing_subscriptions')->whereNotNull('paid_through')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('billing_invoices')
                ->whereColumn('billing_subscription_id', 'billing_subscriptions.id')->where('status', '!=', 'void')
                ->whereColumn('paid_ugx', '>=', 'total_ugx')
                ->whereRaw('COALESCE(period_end,period_start) >= billing_subscriptions.paid_through'))
            ->update(['legacy_paid_through' => DB::raw('paid_through')]);
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->json('terms_snapshot')->nullable();
            $t->unsignedBigInteger('credit_ugx')->default(0);
            $t->unsignedBigInteger('refunded_ugx')->default(0);
        });
        Schema::table('business_memberships', fn (Blueprint $t) => $t->unique(['business_id', 'id'], 'membership_business_identity'));
        Schema::table('business_invitations', function (Blueprint $t) {
            $t->timestamp('revoked_at')->nullable();
            $t->string('purpose', 30)->default('membership');
            $t->unsignedBigInteger('replaces_membership_id')->nullable();
            $t->foreign(['business_id', 'replaces_membership_id'])->references(['business_id', 'id'])->on('business_memberships')->restrictOnDelete();
        });
        Schema::table('product_images', function (Blueprint $t) {
            $t->unsignedBigInteger('stored_bytes')->nullable();
        });
        Schema::create('platform_audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 80);
            $t->text('reason');
            $t->json('before_data')->nullable();
            $t->json('after_data')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'created_at']);
        });
        Schema::create('platform_limit_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->string('metric', 30);
            $t->unsignedBigInteger('allowance');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['business_id', 'metric', 'ends_on']);
        });
        Schema::create('platform_usage_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('activity_on');
            $t->timestamp('last_seen_at');
            $t->unique(['business_id', 'user_id', 'activity_on'], 'platform_usage_user_day');
        });
        Schema::create('platform_usage_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->date('period_start');
            $t->date('period_end');
            $t->json('counters');
            $t->timestamp('measured_at');
            $t->unique(['business_id', 'period_start', 'period_end'], 'platform_usage_period');
        });
        Schema::create('platform_support_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->foreignId('platform_user_id')->constrained('users')->restrictOnDelete();
            $t->string('scope', 30)->default('operations_read');
            $t->text('reason');
            $t->string('status', 20)->default('requested');
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('expires_at');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'status', 'expires_at']);
        });
        Schema::create('platform_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('kind', 30);
            $t->string('title', 150);
            $t->text('details');
            $t->string('status', 20)->default('open');
            $t->string('priority', 15)->default('normal');
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('due_at');
            $t->text('resolution')->nullable();
            $t->string('deduplication_key', 100)->nullable()->unique();
            $t->timestamps();
            $t->index(['status', 'due_at']);
        });
        Schema::create('platform_health_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('metric', 40);
            $t->string('status', 20);
            $t->text('evidence');
            $t->unsignedBigInteger('value_ugx')->nullable();
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->timestamp('observed_at');
            $t->timestamp('valid_until');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['business_id', 'metric', 'observed_at'], 'platform_health_scope');
        });
        Schema::create('platform_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->string('kind', 35);
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('recipient', 255);
            $t->text('payload');
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('attempted_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->string('failure_note', 255)->nullable();
            $t->string('deduplication_key', 150)->unique();
            $t->timestamps();
            $t->index(['business_id', 'status']);
        });
        Schema::create('billing_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('billing_invoice_id')->constrained()->restrictOnDelete();
            $t->string('kind', 15);
            $t->unsignedBigInteger('amount_ugx');
            $t->uuid('request_key')->unique();
            $t->text('reason');
            $t->string('evidence_reference', 200);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['billing_adjustments', 'platform_deliveries', 'platform_health_evidence', 'platform_cases', 'platform_support_grants', 'platform_usage_snapshots', 'platform_usage_activities', 'platform_limit_overrides', 'platform_audit_events'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('product_images', fn (Blueprint $t) => $t->dropColumn('stored_bytes'));
        Schema::table('business_invitations', function (Blueprint $t) {
            $t->dropForeign(['business_id', 'replaces_membership_id']);
            $t->dropColumn(['revoked_at', 'purpose', 'replaces_membership_id']);
        });
        Schema::table('business_memberships', fn (Blueprint $t) => $t->dropUnique('membership_business_identity'));
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropColumn(['terms_snapshot', 'credit_ugx', 'refunded_ugx']));
        Schema::table('billing_subscriptions', fn (Blueprint $t) => $t->dropColumn(['terms_snapshot', 'scheduled_change', 'paused_at', 'legacy_paid_through']));
        Schema::table('billing_plans', fn (Blueprint $t) => $t->dropColumn(['family_code', 'version', 'modules', 'seat_limit', 'storage_limit_bytes']));
        Schema::table('businesses', function (Blueprint $t) {
            $t->dropIndex(['lifecycle_status', 'is_active']);
            $t->dropColumn(['lifecycle_status', 'security_suspended_at', 'billing_mode', 'billing_review_due_on', 'trial_ends_on', 'billing_exempt_until', 'owner_contact_email']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('platform_role'));
    }
};
