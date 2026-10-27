<?php

namespace Tests\Feature;

use App\Mail\BusinessInvitationMail;
use App\Models\BillingInvoice;
use App\Models\BillingPaymentMethod;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PlatformCase;
use App\Models\PlatformDelivery;
use App\Models\PlatformHealthEvidence;
use App\Models\PlatformSupportGrant;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierRequestLink;
use App\Models\SupplyRequest;
use App\Models\User;
use App\Services\InviteBusinessUser;
use App\Services\PlatformBilling;
use App\Services\PlatformDashboard;
use App\Services\PlatformMail;
use App\Services\PlatformTenancy;
use App\Services\PlatformUsage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformControlCentreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-27 09:00:00', 'UTC'));
        Mail::fake();
    }

    private function operator(string $role = 'superadmin'): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
    }

    private function business(string $code = 'KLA', array $attributes = []): Business
    {
        return Business::create([...['name' => $code.' Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton', 'billing_mode' => 'trial', 'trial_ends_on' => today('Africa/Kampala')->addDays(14)], ...$attributes]);
    }

    private function member(Business $b, string $role = 'owner'): User
    {
        $u = User::factory()->create();
        BusinessMembership::create(['business_id' => $b->id, 'user_id' => $u->id, 'role' => $role, 'active' => true]);

        return $u;
    }

    private function agreement(Business $b, array $terms = []): array
    {
        $plan = BillingPlan::create(['name' => 'Exporter', 'code' => 'export-'.Str::lower(Str::random(8)), 'family_code' => 'export-'.Str::lower(Str::random(8)), 'version' => 1, 'monthly_price_ugx' => 100000, 'modules' => ['core', 'sales', 'packing', 'people'], 'seat_limit' => 3, 'storage_limit_bytes' => 1000000, ...$terms]);
        $s = BillingSubscription::create(['business_id' => $b->id, 'billing_plan_id' => $plan->id, 'billing_email' => 'accounts@example.test', 'billing_cycle' => 'monthly', 'price_ugx' => 100000, 'tax_rate' => 0, 'starts_on' => '2026-10-27', 'next_invoice_on' => '2026-10-27', 'status' => 'pending', 'grace_days' => 7, 'terms_snapshot' => app(PlatformBilling::class)->planTerms($plan)]);
        $method = BillingPaymentMethod::create(['label' => 'Official bank', 'type' => 'bank', 'provider' => 'Bank', 'account_name' => 'Boosted', 'account_number' => '001', 'is_active' => true]);

        return [$s, $method, $plan];
    }

    private function issue(BillingSubscription $s, BillingPaymentMethod $m): BillingInvoice
    {
        return app(PlatformBilling::class)->invoice($s, $m, ['due_on' => '2026-10-27'], null);
    }

    private function payment(BillingInvoice $i, BillingPaymentMethod $m, User $u, int $amount = 100000): array
    {
        $data = ['billing_payment_method_id' => $m->id, 'amount_ugx' => $amount, 'reference' => 'BANK-'.$i->id.'-'.$amount, 'received_on' => '2026-10-27'];
        $this->actingAs($u)->post(route('platform.billing.invoice.pay', $i), $data)->assertRedirect()->assertSessionHasNoErrors();

        return $data;
    }

    public function test_platform_landing_and_all_business_tabs_render_with_sources(): void
    {
        $b = $this->business();
        $this->member($b);
        [$s,$m] = $this->agreement($b);
        $i = $this->issue($s, $m);
        $this->actingAs($this->operator());
        foreach (['platform.overview', 'platform.businesses.index', 'platform.billing.index', 'platform.health', 'platform.support', 'platform.settings', 'platform.onboarding'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach (['overview', 'subscription', 'usage', 'access', 'health', 'history'] as $tab) {
            $this->get(route('platform.businesses.show', [$b, 'tab' => $tab]))->assertOk()->assertSee($b->name);
        }
        $this->get(route('platform.billing.invoice.show', $i))->assertOk()->assertSee($i->invoice_number);
    }

    public function test_platform_roles_enforce_mutation_and_sensitive_page_boundaries(): void
    {
        $b = $this->business();
        $this->actingAs($this->operator('support'))->get(route('platform.billing.index'))->assertForbidden();
        $this->get(route('platform.businesses.show', [$b, 'tab' => 'access']))->assertForbidden();
        $this->post(route('platform.businesses.lifecycle', $b), ['action' => 'suspend', 'reason' => 'Investigating this account', 'confirmed' => 1])->assertForbidden();
        $this->get(route('platform.settings'))->assertForbidden();
        $this->actingAs($this->operator('billing'))->get(route('platform.health'))->assertForbidden();
        $this->get(route('platform.onboarding'))->assertForbidden();
        $this->actingAs($this->operator('business'))->get(route('platform.onboarding'))->assertOk();
        $this->get(route('platform.billing.index'))->assertForbidden();
        $this->actingAs($this->member($b))->get(route('platform.overview'))->assertForbidden();
    }

    public function test_superadmin_can_change_other_operator_access_with_audit_but_not_their_own(): void
    {
        $admin = $this->operator();
        $target = $this->operator('billing');
        $this->actingAs($admin)->put(route('platform.settings.role'), ['email' => $target->email, 'role' => 'support', 'reason' => 'Approved support-only operator access'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('support', $target->fresh()->platform_role);
        $this->assertDatabaseHas('platform_audit_events', ['actor_id' => $admin->id, 'action' => 'platform.role']);
        $this->put(route('platform.settings.role'), ['email' => $admin->email, 'role' => 'remove', 'reason' => 'Attempting to remove this own account'])->assertForbidden();
        $this->actingAs($target)->get(route('platform.billing.index'))->assertForbidden();
    }

    public function test_trial_expiry_and_missing_agreement_cannot_grant_unlimited_writes(): void
    {
        $b = $this->business('A', ['trial_ends_on' => '2026-10-26']);
        $tenancy = app(PlatformTenancy::class);
        $this->assertSame('restricted', $tenancy->state($b)['state']);
        $b->update(['billing_mode' => 'subscribed']);
        $this->assertFalse($tenancy->state($b)['can_write']);
        $b->update(['billing_mode' => 'legacy_review', 'billing_review_due_on' => '2026-10-26']);
        $this->assertFalse($tenancy->state($b)['can_write']);
    }

    public function test_restricted_staff_can_read_and_find_owner_but_cannot_write(): void
    {
        $b = $this->business('A', ['billing_mode' => 'subscribed']);
        $owner = $this->member($b);
        $staff = $this->member($b, 'viewer');
        $this->actingAs($staff)->withSession(['active_business_id' => $b->id])->get(route('products.index'))->assertOk();
        $this->get(route('business.subscription-status'))->assertOk()->assertSee($owner->email);
        $this->postJson(route('products.store'), ['sku' => 'NEW', 'name' => 'New produce', 'base_unit' => 'kg'])->assertStatus(402);
        $this->assertDatabaseCount('products', 0);
        $this->get(route('business.billing'))->assertForbidden();
    }

    public function test_security_suspension_is_separate_from_billing_and_closure_preserves_data(): void
    {
        $b = $this->business();
        $owner = $this->member($b);
        $admin = $this->operator();
        $lifecycle = route('platform.businesses.lifecycle', $b);
        $this->actingAs($admin)->post($lifecycle, ['action' => 'suspend', 'reason' => 'Reviewing reported account compromise', 'confirmed' => 1])->assertRedirect();
        $this->post(route('platform.businesses.billing-access', $b), ['mode' => 'exempt', 'until' => '2027-01-01', 'reason' => 'Approved dated service exception'])->assertRedirect();
        $this->assertFalse($b->fresh()->is_active);
        $this->assertFalse(app(PlatformTenancy::class)->state($b->fresh())['can_write']);
        $this->post($lifecycle, ['action' => 'close', 'reason' => 'Closure requested with retained records', 'confirmed' => 1])->assertRedirect();
        $this->post($lifecycle, ['action' => 'reopen', 'reason' => 'Owner asked to resume business access', 'confirmed' => 1])->assertRedirect();
        $this->assertFalse($b->fresh()->is_active);
        $this->post($lifecycle, ['action' => 'reinstate', 'reason' => 'Owner identity and account security reviewed', 'confirmed' => 1])->assertRedirect();
        $this->assertTrue($b->fresh()->is_active);
        $this->assertDatabaseHas('business_memberships', ['business_id' => $b->id, 'user_id' => $owner->id, 'active' => true]);
    }

    public function test_seat_reservations_block_extra_invitations_and_revocation_releases_capacity(): void
    {
        $b = $this->business();
        $owner = $this->member($b);
        $this->agreement($b, ['seat_limit' => 2]);
        $first = app(InviteBusinessUser::class)->send($b, 'staff1@example.test', 'viewer', $owner->id);
        $this->actingAs($owner)->withSession(['active_business_id' => $b->id])->post(route('team.invite'), ['email' => 'staff2@example.test', 'role' => 'viewer'])->assertSessionHasErrors('email');
        $this->assertSame(['active_seats' => 1, 'reserved_seats' => 1], app(PlatformUsage::class)->seats($b));
        $first->update(['revoked_at' => now()]);
        $this->post(route('team.invite'), ['email' => 'staff2@example.test', 'role' => 'viewer'])->assertSessionHasNoErrors();
        Mail::assertSent(BusinessInvitationMail::class, 2);
    }

    public function test_owner_transfer_waits_for_acceptance_and_superseded_link_cannot_work(): void
    {
        $b = $this->business();
        $owner = $this->member($b);
        $this->agreement($b, ['seat_limit' => 1]);
        $admin = $this->operator();
        $old = app(InviteBusinessUser::class)->send($b, 'next@example.test', 'owner', $admin->id, 'owner_transfer', $b->memberships()->where('user_id', $owner->id)->value('id'));
        $oldToken = PlatformDelivery::where('source_id', $old->id)->firstOrFail()->payload['token'];
        $new = app(InviteBusinessUser::class)->send($b, 'next@example.test', 'owner', $admin->id, 'owner_transfer', $old->replaces_membership_id);
        $delivery = PlatformDelivery::where('source_id', $new->id)->firstOrFail();
        $token = $delivery->payload['token'];
        $this->assertStringNotContainsString($token, DB::table('platform_deliveries')->where('id', $delivery->id)->value('payload'));
        $this->assertTrue($b->memberships()->where('user_id', $owner->id)->first()->active);
        $this->get(route('invitations.show', $oldToken))->assertNotFound();
        $this->post(route('invitations.accept', $token), ['name' => 'New Owner', 'password' => 'safe-long-password-123', 'password_confirmation' => 'safe-long-password-123'])->assertRedirect(route('workspace'));
        $this->assertFalse($b->memberships()->where('user_id', $owner->id)->first()->active);
        $this->assertDatabaseHas('business_memberships', ['business_id' => $b->id, 'role' => 'owner', 'active' => true, 'user_id' => User::where('email', 'next@example.test')->value('id')]);
        $this->post(route('invitations.accept', $token))->assertNotFound();
    }

    public function test_platform_owner_invitation_requires_identity_evidence_and_tenant_match(): void
    {
        $a = $this->business();
        $b = $this->business('OTHER');
        $admin = $this->operator();
        $invite = app(InviteBusinessUser::class)->send($b, 'owner@example.test', 'owner', $admin->id);
        $this->actingAs($admin)->post(route('platform.businesses.invitation', $a), ['action' => 'revoke', 'invitation_id' => $invite->id, 'reason' => 'Owner identity verified through approved process', 'identity_verified' => 1])->assertNotFound();
        $this->post(route('platform.businesses.invitation', $b), ['action' => 'revoke', 'invitation_id' => $invite->id, 'reason' => 'Owner identity verified through approved process'])->assertSessionHasErrors('identity_verified');
        $this->assertNull($invite->fresh()->revoked_at);
    }

    public function test_delivery_failure_is_recorded_and_manual_retry_is_deduplicated(): void
    {
        $b = $this->business();
        $mail = app(PlatformMail::class);
        $d = $mail->prepare($b->id, 'usage_or_access', null, 'owner@example.test', ['title' => 'Review', 'message' => 'A dated account review is due.'], 'unique-review');
        $manager = app('mail.manager');
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Transport unavailable'));
        $this->assertFalse($mail->deliver($d));
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame(1, $d->fresh()->attempts);
        Mail::swap($manager);
        Mail::fake();
        $this->actingAs($this->operator('billing'))->post(route('platform.deliveries.retry', $d), ['reason' => 'Mail transport repaired and checked'])->assertRedirect();
        $this->assertSame('sent', $d->fresh()->status);
        $this->assertSame(2, $d->fresh()->attempts);
        $this->assertFalse($mail->deliver($d));
        $this->assertSame($d->id, $mail->prepare($b->id, 'usage_or_access', null, 'owner@example.test', [], 'unique-review')->id);
    }

    public function test_plan_versions_leave_existing_agreement_and_invoice_unchanged(): void
    {
        $b = $this->business();
        [$s,$m,$p] = $this->agreement($b);
        $i = $this->issue($s, $m);
        $this->actingAs($this->operator('billing'))->post(route('platform.billing.plans.revise', $p), ['name' => 'Exporter Plus', 'monthly_price_ugx' => 200000, 'modules' => ['core', 'sales'], 'seat_limit' => 10, 'reason' => 'New commercial terms for future agreements'])->assertRedirect()->assertSessionHasNoErrors();
        $next = BillingPlan::latest('id')->first();
        $this->assertSame(2, $next->version);
        $this->assertFalse($p->fresh()->is_active);
        $this->assertSame(3, $s->fresh()->terms_snapshot['seat_limit']);
        $this->assertSame('Exporter', $i->fresh()->plan_name);
        $this->assertSame(100000, $i->fresh()->total_ugx);
    }

    public function test_partial_payment_is_idempotent_and_credit_refund_history_reconciles_cover(): void
    {
        $b = $this->business();
        [$s,$m] = $this->agreement($b);
        $i = $this->issue($s, $m);
        $admin = $this->operator('billing');
        $data = $this->payment($i, $m, $admin, 60000);
        $this->post(route('platform.billing.invoice.pay', $i), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertSame(40000, $i->fresh()->remaining());
        $credit = ['kind' => 'credit', 'amount_ugx' => 40000, 'reason' => 'Agreed introductory discount adjustment', 'evidence_reference' => 'AGREEMENT-01', 'request_key' => (string) Str::uuid()];
        $this->post(route('platform.billing.invoices.adjustment', $i), $credit)->assertSessionHasNoErrors();
        $this->post(route('platform.billing.invoices.adjustment', $i), $credit)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('billing_adjustments', 1);
        $this->assertSame('active', $s->fresh()->status);
        $this->assertSame('subscribed', $b->fresh()->billing_mode);
        $this->post(route('platform.billing.invoices.adjustment', $i), ['kind' => 'refund', 'amount_ugx' => 10000, 'reason' => 'Verified returned cash to client bank', 'evidence_reference' => 'REFUND-BANK-01', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(60000, $i->fresh()->paid_ugx);
        $this->assertSame(10000, $i->fresh()->remaining());
        $this->assertSame('pending', $s->fresh()->status);
        $this->post(route('platform.billing.invoice.pay', $i), [...$data, 'amount_ugx' => 70000])->assertSessionHasErrors('reference');
    }

    public function test_pause_cancel_and_resume_do_not_delete_data_or_revive_trial(): void
    {
        $b = $this->business();
        [$s,$m] = $this->agreement($b);
        $i = $this->issue($s, $m);
        $admin = $this->operator();
        $this->payment($i, $m, $admin);
        $route = route('platform.billing.subscriptions.change', $s);
        $this->post($route, ['action' => 'pause', 'reason' => 'Owner requested temporary service pause'])->assertSessionHasNoErrors();
        $this->assertSame('restricted', app(PlatformTenancy::class)->state($b->fresh())['state']);
        $this->post($route, ['action' => 'resume', 'reason' => 'Owner asked to resume the service'])->assertSessionHasNoErrors();
        $this->assertSame('active', app(PlatformTenancy::class)->state($b->fresh())['state']);
        $this->post($route, ['action' => 'cancel', 'reason' => 'Owner approved cancellation with retained data'])->assertSessionHasNoErrors();
        $this->assertSame('restricted', app(PlatformTenancy::class)->state($b->fresh())['state']);
        $this->post($route, ['action' => 'resume', 'reason' => 'Attempting to resume cancelled agreement'])->assertStatus(409);
        $this->assertDatabaseHas('billing_invoices', ['id' => $i->id]);
    }

    public function test_scheduled_plan_change_applies_only_at_next_period_with_frozen_terms(): void
    {
        $b = $this->business();
        [$s,$m] = $this->agreement($b);
        $i = $this->issue($s, $m);
        $this->payment($i, $m, $this->operator());
        $next = BillingPlan::create(['name' => 'Next plan', 'code' => 'next', 'modules' => ['core'], 'seat_limit' => 1]);
        $data = ['action' => 'schedule', 'billing_plan_id' => $next->id, 'effective_on' => '2026-11-27', 'price_ugx' => 150000, 'tax_rate' => 0, 'reason' => 'Customer approved change at next renewal'];
        $this->post(route('platform.billing.subscriptions.change', $s), [...$data, 'effective_on' => '2026-11-10'])->assertSessionHasErrors('effective_on');
        $this->post(route('platform.billing.subscriptions.change', $s), $data)->assertSessionHasNoErrors();
        $this->assertSame(3, app(PlatformTenancy::class)->allowance($b, 'seat_limit'));
        $future = $this->issue($s->fresh(), $m);
        $this->assertSame(150000, $future->total_ugx);
        $this->assertSame('Next plan', $future->plan_name);
        $this->travelTo(Carbon::parse('2026-11-27 09:00:00', 'UTC'));
        $this->assertSame(1, app(PlatformTenancy::class)->allowance($b->fresh(), 'seat_limit'));
        app(PlatformBilling::class)->applyScheduled($s->fresh());
        $this->assertNull($s->fresh()->scheduled_change);
        $this->assertSame($next->id, $s->fresh()->billing_plan_id);
    }

    public function test_usage_counts_and_period_snapshots_are_tenant_bound_and_idempotent(): void
    {
        $a = $this->business();
        $b = $this->business('B');
        $owner = $this->member($a);
        $other = $this->member($b);
        $usage = app(PlatformUsage::class);
        $usage->record($a, $owner->id);
        $usage->record($a, $owner->id);
        $usage->record($b, $other->id);
        $first = $usage->snapshot($a, '2026-10-01', '2026-10-27');
        $again = $usage->snapshot($a, '2026-10-01', '2026-10-27');
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $again->counters['active_users']);
        $this->assertSame(1, $again->counters['active_seats']);
        $this->assertSame(0, $again->counters['shipments']);
        $this->assertDatabaseCount('platform_usage_activities', 2);
        $this->assertDatabaseCount('platform_usage_snapshots', 1);
    }

    public function test_capped_media_rejects_upload_without_writing_objects(): void
    {
        Storage::fake('r2');
        foreach (['key', 'secret', 'bucket', 'endpoint'] as $key) {
            config()->set('filesystems.disks.r2.'.$key, 'test');
        }
        $b = $this->business();
        $owner = $this->member($b);
        $this->agreement($b, ['storage_limit_bytes' => 1]);
        $p = Product::create(['business_id' => $b->id, 'sku' => 'AVO', 'name' => 'Avocado', 'base_unit' => 'kg']);
        $this->actingAs($owner)->withSession(['active_business_id' => $b->id])->post(route('products.images.store', $p), ['photo' => UploadedFile::fake()->image('produce.jpg', 100, 100)])->assertSessionHasErrors('photo');
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_dated_allowance_override_expires_without_changing_plan(): void
    {
        $b = $this->business();
        $this->agreement($b, ['seat_limit' => 1]);
        $this->actingAs($this->operator('billing'))->post(route('platform.businesses.allowance', $b), ['metric' => 'seat_limit', 'allowance' => 4, 'starts_on' => '2026-10-27', 'ends_on' => '2026-10-28', 'reason' => 'Temporary migration access approved'])->assertSessionHasNoErrors();
        $this->assertSame(4, app(PlatformTenancy::class)->allowance($b, 'seat_limit'));
        $this->travel(2)->days();
        $this->assertSame(1, app(PlatformTenancy::class)->allowance($b, 'seat_limit'));
    }

    public function test_support_requires_owner_approval_is_read_only_and_can_be_revoked(): void
    {
        $b = $this->business();
        $owner = $this->member($b);
        $admin = $this->operator('support');
        $this->actingAs($admin)->post(route('platform.support.request', $b), ['reason' => 'Diagnosing a reported printer queue issue', 'expires_at' => '2026-10-27T13:00'])->assertSessionHasNoErrors();
        $grant = PlatformSupportGrant::firstOrFail();
        $this->assertSame('2026-10-27 10:00:00', $grant->expires_at->format('Y-m-d H:i:s'));
        $this->get(route('platform.support.view', $grant))->assertNotFound();
        $this->actingAs($owner)->withSession(['active_business_id' => $b->id])->post(route('business.platform.grant', $grant), ['action' => 'approve', 'reason' => 'Please investigate our printer queue'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get(route('platform.support.view', $grant))->assertOk()->assertSee('Read-only')->assertDontSee('total_ugx');
        $this->get(route('products.index'))->assertRedirect(route('platform.overview'));
        $this->actingAs($owner)->get(route('business.subscription-status'))->assertOk()->assertSee('support.viewed');
        $this->post(route('business.platform.grant', $grant), ['action' => 'revoke', 'reason' => 'The printer issue is now resolved'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get(route('platform.support.view', $grant))->assertNotFound();
    }

    public function test_support_grants_cannot_be_approved_by_other_tenants_or_used_by_other_operators(): void
    {
        $a = $this->business();
        $b = $this->business('B');
        $support = $this->operator('support');
        $g = PlatformSupportGrant::create(['business_id' => $a->id, 'platform_user_id' => $support->id, 'reason' => 'Diagnose an operational issue', 'expires_at' => now()->addHour(), 'status' => 'approved']);
        $this->actingAs($this->member($b))->withSession(['active_business_id' => $b->id])->post(route('business.platform.grant', $g), ['action' => 'approve', 'reason' => 'Wrong tenant must not approve'])->assertNotFound();
        $this->actingAs($this->operator())->get(route('platform.support.view', $g))->assertNotFound();
        $this->travel(2)->hours();
        $this->actingAs($support)->get(route('platform.support.view', $g))->assertNotFound();
    }

    public function test_health_is_unknown_without_fresh_evidence_and_limits_evidence_lifetime(): void
    {
        $dashboard = app(PlatformDashboard::class);
        $this->assertSame('unknown', $dashboard->health(null)['health']['backup']['status']);
        $this->actingAs($this->operator('support'));
        $data = ['metric' => 'backup', 'status' => 'ok', 'evidence' => 'Verified offsite artifact and checksum in backup log', 'observed_at' => '2026-10-27T11:00', 'valid_until' => '2026-10-28T11:00'];
        $this->post(route('platform.health.evidence'), $data)->assertSessionHasNoErrors();
        $this->assertSame('2026-10-27 08:00:00', PlatformHealthEvidence::firstOrFail()->observed_at->format('Y-m-d H:i:s'));
        $this->assertSame('ok', $dashboard->health(null)['health']['backup']['status']);
        $this->post(route('platform.health.evidence'), [...$data, 'valid_until' => '2027-01-01T11:00'])->assertSessionHasErrors('valid_until');
        $this->travel(2)->days();
        $this->assertSame('unknown', $dashboard->health(null)['health']['backup']['status']);
    }

    public function test_cases_require_assignment_due_time_and_resolution_evidence(): void
    {
        $admin = $this->operator();
        $b = $this->business();
        $data = ['business_id' => $b->id, 'kind' => 'offboarding', 'title' => 'Arrange retained export', 'details' => 'Owner requests a complete data and retention review', 'status' => 'open', 'priority' => 'normal', 'assigned_to' => $admin->id, 'due_at' => '2026-10-28T12:00'];
        $this->actingAs($admin)->post(route('platform.cases.store'), $data)->assertSessionHasNoErrors();
        $case = PlatformCase::firstOrFail();
        $this->assertSame('2026-10-28 09:00:00', $case->due_at->format('Y-m-d H:i:s'));
        $this->put(route('platform.cases.update', $case), [...$data, 'status' => 'resolved'])->assertSessionHasErrors('resolution');
        $this->put(route('platform.cases.update', $case), [...$data, 'status' => 'resolved', 'resolution' => 'Export delivered privately and retention agreed'])->assertSessionHasNoErrors();
        $this->assertSame('resolved', $case->fresh()->status);
        $this->assertDatabaseHas('businesses', ['id' => $b->id]);
    }

    public function test_owner_export_contains_only_own_structured_records_and_excludes_credentials(): void
    {
        $a = $this->business();
        $b = $this->business('B');
        $owner = $this->member($a);
        Product::create(['business_id' => $a->id, 'sku' => 'OWN', 'name' => 'Own avocado', 'base_unit' => 'kg']);
        Product::create(['business_id' => $b->id, 'sku' => 'OTHER', 'name' => 'Other tenant pineapple', 'base_unit' => 'kg']);
        $response = $this->actingAs($owner)->withSession(['active_business_id' => $a->id])->post(route('business.platform.export'), ['reason' => 'Owner requested a retained business copy'])->assertOk()->assertDownload('KLA-business-records.json');
        $contents = $response->streamedContent();
        $json = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Own avocado', $json['records']['products'][0]['name']);
        $this->assertCount(1, $json['records']['products']);
        $this->assertStringNotContainsString('Other tenant pineapple', $contents);
        $this->assertArrayNotHasKey('users', $json['records']);
        $this->assertDatabaseHas('platform_audit_events', ['business_id' => $a->id, 'action' => 'business.export']);
    }

    public function test_review_dry_run_has_no_writes_and_repeated_renewals_do_not_duplicate(): void
    {
        $b = $this->business();
        $this->member($b);
        $this->operator();
        [$s,$m] = $this->agreement($b);
        $i = $this->issue($s, $m);
        $this->artisan('platform:review', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('platform_usage_snapshots', 0);
        $this->assertDatabaseCount('platform_deliveries', 0);
        $this->assertDatabaseCount('platform_cases', 0);
        $this->artisan('platform:review')->assertSuccessful();
        $this->artisan('platform:review')->assertSuccessful();
        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertDatabaseCount('platform_cases', 1);
        $this->assertDatabaseCount('platform_usage_snapshots', 1);
        $this->assertDatabaseCount('platform_deliveries', 1);
        Mail::assertNothingSent();
        $this->assertNotNull(PlatformCase::firstOrFail()->assigned_to);
    }

    public function test_dashboard_period_collections_match_receipts_and_linked_directory_filters(): void
    {
        $b = $this->business();
        [$s,$m] = $this->agreement($b);
        $i = $this->issue($s, $m);
        $this->payment($i, $m, $this->operator());
        $this->business('OLD', ['trial_ends_on' => '2026-10-26']);
        $this->get(route('platform.overview', ['from' => '2026-10-27', 'to' => '2026-10-27']))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['collected_ugx'] === 100000 && $stats['previous_collected_ugx'] === 0 && $stats['active_paid'] === 1 && $stats['billing_restricted'] === 1);
        $this->get(route('platform.businesses.index', ['cover' => 'restricted']))->assertOk()->assertSee('OLD Exporters')->assertDontSee('KLA Exporters');
        $this->get(route('platform.overview', ['from' => '2026-10-28', 'to' => '2026-10-27']))->assertSessionHasErrors('to');
    }

    public function test_plan_module_writes_and_reactivation_are_enforced_beyond_the_menu(): void
    {
        $b = $this->business();
        $owner = $this->member($b);
        $staff = $this->member($b, 'viewer');
        $target = $b->memberships()->where('user_id', $staff->id)->first();
        $target->update(['active' => false]);
        [$s] = $this->agreement($b, ['seat_limit' => 1]);
        $this->actingAs($owner)->withSession(['active_business_id' => $b->id]);
        $this->put(route('team.members.update', $target), ['role' => 'viewer', 'active' => true, 'reason' => 'Restore former staff account access'])->assertSessionHasErrors('email');
        $this->assertFalse($target->fresh()->active);
        $s->update(['terms_snapshot' => [...$s->terms_snapshot, 'modules' => ['core']]]);
        $this->get(route('suppliers.index'))->assertOk();
        $this->post(route('suppliers.store'), ['name' => 'New supplier'])->assertForbidden();
        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_coverage_respects_first_day_grace_end_and_future_start(): void
    {
        $b = $this->business('COVER', ['billing_mode' => 'subscribed']);
        [$s] = $this->agreement($b);
        $s->update(['paid_through' => '2026-10-20', 'status' => 'active']);
        $tenancy = app(PlatformTenancy::class);
        $this->assertSame('grace', $tenancy->state($b)['state']);
        $this->travel(1)->days();
        $this->assertSame('restricted', $tenancy->state($b->fresh())['state']);
        $s->update(['starts_on' => '2026-10-29', 'paid_through' => '2026-11-28']);
        $this->assertSame('restricted', $tenancy->state($b->fresh())['state']);
        $this->travel(1)->days();
        $this->assertSame('active', $tenancy->state($b->fresh())['state']);
    }

    public function test_paid_renewal_is_prepared_once_without_collecting_or_sending_money(): void
    {
        $b = $this->business();
        [$s,$m] = $this->agreement($b);
        $invoice = $this->issue($s, $m);
        $this->payment($invoice, $m, $this->operator());
        $this->travelTo(Carbon::parse('2026-11-15 09:00:00', 'UTC'));
        $this->artisan('platform:review')->assertSuccessful();
        $this->artisan('platform:review')->assertSuccessful();
        $this->assertDatabaseCount('billing_invoices', 2);
        $this->assertDatabaseCount('billing_payments', 1);
        $next = BillingInvoice::latest('id')->first();
        $this->assertSame('2026-11-27', $next->period_start->toDateString());
        $this->assertSame(100000, $next->remaining());
        $this->assertSame('2026-11-26', $s->fresh()->paid_through->toDateString());
        Mail::assertNothingSent();
    }

    public function test_daily_activity_uses_kampala_midnight_without_counting_retries(): void
    {
        $b = $this->business();
        $owner = $this->member($b);
        $usage = app(PlatformUsage::class);
        $this->travelTo(Carbon::parse('2026-10-27 20:59:00', 'UTC'));
        $usage->record($b, $owner->id);
        $usage->record($b, $owner->id);
        $this->travel(2)->minutes();
        $usage->record($b, $owner->id);
        $this->assertDatabaseCount('platform_usage_activities', 2);
        $this->assertDatabaseHas('platform_usage_activities', ['activity_on' => '2026-10-27']);
        $this->assertDatabaseHas('platform_usage_activities', ['activity_on' => '2026-10-28']);
    }

    public function test_invoice_void_and_excessive_corrections_preserve_original_history(): void
    {
        $b = $this->business();
        [$s,$m] = $this->agreement($b);
        $i = $this->issue($s, $m);
        $this->actingAs($this->operator('billing'));
        $route = route('platform.billing.invoices.adjustment', $i);
        $data = ['kind' => 'credit', 'amount_ugx' => 100001, 'reason' => 'A disputed correction exceeds the balance', 'evidence_reference' => 'REVIEW-123', 'request_key' => (string) Str::uuid()];
        $this->post($route, $data)->assertSessionHasErrors('amount_ugx');
        $this->post($route, [...$data, 'kind' => 'void', 'amount_ugx' => 0])->assertSessionHasNoErrors();
        $this->assertSame('void', $i->fresh()->status);
        $this->assertSame(100000, $i->fresh()->total_ugx);
        $this->post(route('platform.billing.invoice.pay', $i), ['billing_payment_method_id' => $m->id, 'amount_ugx' => 1, 'reference' => 'NEW-RECEIPT', 'received_on' => '2026-10-27'])->assertSessionHasErrors('amount_ugx');
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertDatabaseCount('billing_adjustments', 1);
    }

    public function test_public_supplier_link_cannot_bypass_expired_billing(): void
    {
        $b = $this->business('EXPIRED', ['billing_mode' => 'subscribed']);
        $owner = $this->member($b);
        $product = Product::create(['business_id' => $b->id, 'sku' => 'AVO', 'name' => 'Avocado', 'base_unit' => 'kg']);
        $supplier = Supplier::create(['business_id' => $b->id, 'supplier_code' => 'FARM', 'name' => 'Farm', 'kind' => 'company', 'active' => true]);
        $rfq = SupplyRequest::create(['business_id' => $b->id, 'reference' => 'RFQ-PUBLIC', 'product_id' => $product->id, 'quantity' => '100', 'currency' => 'UGX', 'status' => 'sent', 'response_deadline' => now()->addDay(), 'created_by' => $owner->id]);
        $token = Str::random(64);
        SupplierRequestLink::create(['business_id' => $b->id, 'supply_request_id' => $rfq->id, 'supplier_id' => $supplier->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->get(route('supplier-offer.show', $token))->assertOk();
        $this->post(route('supplier-offer.store', $token), ['quantity' => '10', 'unit_price' => '1000'])->assertStatus(402);
        $this->assertDatabaseCount('supplier_quotes', 0);
        $this->assertDatabaseCount('supplier_quote_revisions', 0);
    }

    public function test_replaying_a_paid_invoice_issue_does_not_issue_another_period(): void
    {
        $b = $this->business();
        [$s,$m] = $this->agreement($b);
        $admin = $this->operator('billing');
        $data = ['period_start' => '2026-10-27', 'billing_payment_method_id' => $m->id, 'due_on' => '2026-11-01'];
        $route = route('platform.billing.invoice.issue', $s);
        $this->actingAs($admin)->post($route, $data)->assertRedirect()->assertSessionHasNoErrors();
        $invoice = BillingInvoice::firstOrFail();
        $this->payment($invoice, $m, $admin);
        $this->post($route, $data)->assertRedirect(route('platform.billing.invoice.show', $invoice));
        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertSame('2026-11-27', $s->fresh()->next_invoice_on->toDateString());
        $this->post($route, [...$data, 'period_start' => '2026-12-27'])->assertStatus(409);
    }

    public function test_billing_date_validation_uses_uganda_date_after_utc_midnight_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 22:00:00', 'UTC'));
        $b = $this->business();
        [$s,$m] = $this->agreement($b);
        $invoice = $this->issue($s, $m);
        $admin = $this->operator('billing');
        $this->payment($invoice, $m, $admin);
        $this->get(route('platform.overview', ['from' => '2026-10-27', 'to' => '2026-10-27']))->assertOk();
        $this->post(route('platform.businesses.billing-access', $b), ['mode' => 'exempt', 'until' => '2026-10-26', 'reason' => 'Yesterday must not be a valid future exception'])->assertSessionHasErrors('until');
    }

    public function test_migration_preserves_undocumented_legacy_cover_without_masking_invoice_refunds(): void
    {
        $migration = require database_path('migrations/2026_10_27_083654_add_platform_control_centre.php');
        $migration->down();
        $businesses = [];
        foreach (['DOCUMENTED', 'MANUAL'] as $code) {
            $businesses[] = Business::create(['name' => $code, 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        }
        $plan = BillingPlan::create(['name' => 'Legacy', 'code' => 'legacy']);
        $subscriptions = [];
        foreach ($businesses as $b) {
            $subscriptions[] = BillingSubscription::create(['business_id' => $b->id, 'billing_plan_id' => $plan->id, 'billing_email' => 'owner@example.test', 'billing_cycle' => 'monthly', 'price_ugx' => 100000, 'tax_rate' => 0, 'starts_on' => '2026-10-01', 'paid_through' => '2026-10-31', 'status' => 'active', 'grace_days' => 0]);
        }
        $invoice = BillingInvoice::create(['business_id' => $businesses[0]->id, 'billing_subscription_id' => $subscriptions[0]->id, 'business_name' => 'DOCUMENTED', 'billing_email' => 'owner@example.test', 'plan_name' => 'Legacy', 'billing_cycle' => 'monthly', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_on' => '2026-10-01', 'payment_details' => [], 'subtotal_ugx' => 100000, 'total_ugx' => 100000, 'paid_ugx' => 100000, 'status' => 'paid', 'issued_at' => now()]);
        $migration->up();
        $this->assertNull($subscriptions[0]->fresh()->legacy_paid_through);
        $this->assertSame('2026-10-31', $subscriptions[1]->fresh()->legacy_paid_through->toDateString());
        $admin = $this->operator('billing');
        $this->actingAs($admin)->post(route('platform.billing.invoices.adjustment', $invoice), ['kind' => 'refund', 'amount_ugx' => 100000, 'reason' => 'Verified complete refund of original receipt', 'evidence_reference' => 'BANK-RETURN-01', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('restricted', app(PlatformTenancy::class)->state($businesses[0]->fresh())['state']);
        $this->assertSame('active', app(PlatformTenancy::class)->state($businesses[1]->fresh())['state']);
    }

    public function test_owner_can_remove_staff_access_while_billing_is_restricted_but_cannot_reactivate_it(): void
    {
        $b = $this->business('RESTRICTED', ['billing_mode' => 'subscribed']);
        $owner = $this->member($b);
        $staff = $this->member($b, 'viewer');
        $membership = $b->memberships()->where('user_id', $staff->id)->first();
        $this->actingAs($owner)->withSession(['active_business_id' => $b->id])->put(route('team.members.update', $membership), ['role' => 'viewer', 'active' => false, 'reason' => 'Remove departed staff access immediately'])->assertSessionHasNoErrors();
        $this->assertFalse($membership->fresh()->active);
        $this->putJson(route('team.members.update', $membership), ['role' => 'viewer', 'active' => true, 'reason' => 'Restore staff access without renewal'])->assertStatus(402);
        $this->assertFalse($membership->fresh()->active);
    }
}
