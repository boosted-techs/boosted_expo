<?php

namespace Tests\Feature;

use App\Mail\BoostedOnePackagesMail;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BoostedOneCommercialTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_site_and_legal_pages_render(): void
    {
        $this->get('/')->assertOk()->assertSee('first purchase')->assertSee('Request onboarding');
        $this->get('/privacy-policy')->assertOk()->assertSee('Privacy Policy');
        $this->get('/data-retention')->assertOk()->assertSee('Data Retention Policy');
        $this->get('/terms-of-service')->assertOk()->assertSee('Terms of Service');
    }

    public function test_public_request_does_not_create_an_account(): void
    {
        Mail::fake();
        $this->post('/onboarding/request', [
            'business_name' => 'Sunshine Exports', 'contact_name' => 'Alice',
            'contact_email' => 'alice@example.test', 'contact_phone' => '+256700100200',
            'industry' => 'export', 'business_size' => 'small',
            'privacy_agreement' => '1',
        ])->assertRedirect();
        $this->assertDatabaseHas('onboarding_requests', [
            'business_name' => 'Sunshine Exports', 'status' => 'new',
            'requested_plan' => 'to_discuss', 'billing_cycle' => 'to_discuss',
        ]);
        Mail::assertSent(BoostedOnePackagesMail::class);
        $this->assertDatabaseCount('businesses', 0);
    }

    public function test_public_form_requires_privacy_consent(): void
    {
        $this->post('/onboarding/request', [
            'business_name' => 'Sunshine Exports', 'contact_name' => 'Alice',
            'contact_email' => 'alice@example.test', 'contact_phone' => '+256700100200',
            'industry' => 'export', 'business_size' => 'small',
            'requested_plan' => 'business', 'billing_cycle' => 'quarterly',
        ])->assertSessionHasErrors('privacy_agreement');
    }

    public function test_billing_is_platform_admin_only(): void
    {
        $this->get('/platform/billing')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/platform/billing')->assertForbidden();
    }

    public function test_customer_must_not_access_other_company_invoice(): void
    {
        $a = Business::create(['name' => 'A', 'code' => 'A', 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'batch']);
        $b = Business::create(['name' => 'B', 'code' => 'B', 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'batch']);
        $user = User::factory()->create();
        BusinessMembership::create(['business_id' => $a->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true]);
        $plan = BillingPlan::create(['name' => 'Business', 'code' => 'business']);
        $sub = BillingSubscription::create(['business_id' => $b->id, 'billing_plan_id' => $plan->id, 'billing_email' => 'b@example.test', 'billing_cycle' => 'annual', 'price_ugx' => 100000, 'starts_on' => '2026-10-24', 'status' => 'pending']);
        $invoice = BillingInvoice::create(['business_id' => $b->id, 'billing_subscription_id' => $sub->id, 'business_name' => 'B', 'billing_email' => 'b@example.test', 'plan_name' => 'Business', 'billing_cycle' => 'annual', 'period_start' => '2026-10-24', 'due_on' => '2026-11-01', 'payment_details' => ['label' => 'Test'], 'subtotal_ugx' => 100000, 'total_ugx' => 100000, 'issued_at' => now()]);
        $this->actingAs($user)->withSession(['active_business_id' => $a->id])->get('/my-subscription/invoices/'.$invoice->id)->assertNotFound();
    }
}
