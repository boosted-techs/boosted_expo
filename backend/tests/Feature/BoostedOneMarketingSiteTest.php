<?php

namespace Tests\Feature;

use App\Mail\BoostedOnePackagesMail;
use App\Models\BillingPlan;
use App\Models\OnboardingRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BoostedOneMarketingSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_navigation_links_to_independent_pages(): void
    {
        $homepage = $this->get('/')->assertOk()->assertSee('Every export has a story');

        foreach ([
            'site.solutions', 'site.features', 'site.workflow', 'site.pricing',
            'site.about', 'site.contact', 'site.onboarding', 'login',
        ] as $name) {
            $homepage->assertSee('href="'.route($name).'"', false);
        }

        foreach ([
            '/solutions', '/features', '/how-it-works', '/pricing',
            '/about', '/contact', '/get-started',
        ] as $path) {
            $this->get($path)->assertOk()->assertSee('Boosted One', false);
        }
    }

    public function test_each_feature_has_its_own_explanatory_page(): void
    {
        $overview = $this->get('/features')->assertOk()->assertSee('Built around the export journey');

        foreach (config('boostedone_features') as $slug => $feature) {
            $overview->assertSee(route('site.feature', $slug), false);
            $page = $this->get('/features/'.$slug)->assertOk()
                ->assertSee($feature['title'], false)
                ->assertSee($feature['scenario_title'], false)
                ->assertSee('How this works in practice');

            foreach ($feature['capabilities'] as $capability) {
                $page->assertSee($capability['title'], false);
                $page->assertSee($capability['body'], false);
            }
        }
        $this->get('/features/not-a-real-module')->assertNotFound();
    }

    public function test_each_solution_has_a_unique_workflow_page(): void
    {
        $overview = $this->get('/solutions')->assertOk()->assertSee('Export operations remain our primary focus');

        foreach (config('boostedone_solutions') as $slug => $solution) {
            $overview->assertSee(route('site.solution', $slug), false);
            $this->get('/solutions/'.$slug)->assertOk()
                ->assertSee($solution['title'], false)
                ->assertSee($solution['story_title'], false)
                ->assertSee($solution['why'], false);
        }
        $this->get('/solutions/nonexistent')->assertNotFound();
    }

    public function test_public_pricing_uses_only_public_active_plans(): void
    {
        BillingPlan::create([
            'code' => 'active-starter', 'name' => 'Open Business Package',
            'monthly_price_ugx' => 145000, 'is_active' => true, 'is_public' => true,
        ]);
        BillingPlan::create([
            'code' => 'private', 'name' => 'Private Bespoke License',
            'monthly_price_ugx' => 990000, 'is_active' => true, 'is_public' => false,
        ]);
        BillingPlan::create([
            'code' => 'retired', 'name' => 'Old Retired Package',
            'monthly_price_ugx' => 65000, 'is_active' => false, 'is_public' => true,
        ]);
        $this->get('/pricing')->assertOk()
            ->assertSee('Open Business Package')
            ->assertSee('145,000')
            ->assertDontSee('Private Bespoke License')
            ->assertDontSee('Old Retired Package')
            ->assertSee('Monthly')
            ->assertSee('Quarterly')
            ->assertSee('Annually');
    }

    public function test_onboarding_route_keeps_existing_request_flow(): void
    {
        Mail::fake();
        $this->get('/get-started')->assertOk()
            ->assertSee('Submit onboarding request')
            ->assertSee('privacy_agreement')
            ->assertSee('We will email available package information')
            ->assertDontSee('name="requested_plan"', false)
            ->assertDontSee('Suggested package')
            ->assertDontSee('name="billing_cycle"', false)
            ->assertDontSee('Billing preference')
            ->assertSee(route('onboarding.apply'), false);
        $this->post('/onboarding/request', [
            'business_name' => 'Kampala Fresh Exporters',
            'contact_name' => 'Procurement Lead',
            'contact_email' => 'hello@example.test',
            'contact_phone' => '+256701000000',
            'industry' => 'export',
            'business_size' => 'growing',
            'privacy_agreement' => '1',
        ])->assertRedirect(route('site.onboarding'));
        $this->assertDatabaseHas('onboarding_requests', [
            'business_name' => 'Kampala Fresh Exporters',
            'requested_plan' => 'to_discuss',
            'billing_cycle' => 'to_discuss',
        ]);
        $this->assertNotNull(OnboardingRequest::query()->firstOrFail()->packages_sent_at);
        Mail::assertSent(BoostedOnePackagesMail::class, fn ($mail) => $mail->hasTo('hello@example.test')
            && $mail->isIndicative
            && count($mail->packages) === 3
        );
    }

    public function test_package_and_billing_cycle_cannot_be_chosen_through_the_public_onboarding_form(): void
    {
        Mail::fake();
        $this->post('/onboarding/request', [
            'business_name' => 'Uganda Export Hub',
            'contact_name' => 'Company Owner',
            'contact_email' => 'owner@example.test',
            'contact_phone' => '+256700123456',
            'industry' => 'export',
            'business_size' => 'small',
            'requested_plan' => 'enterprise',
            'billing_cycle' => 'annual',
            'privacy_agreement' => '1',
        ])->assertRedirect(route('site.onboarding'));

        $this->assertDatabaseHas('onboarding_requests', [
            'business_name' => 'Uganda Export Hub',
            'requested_plan' => 'to_discuss',
            'billing_cycle' => 'to_discuss',
        ]);
    }

    public function test_package_email_uses_only_active_public_offers(): void
    {
        Mail::fake();
        BillingPlan::create([
            'code' => 'starter-offer',
            'name' => 'Boosted One Starter',
            'description' => 'Basic procurement and stock',
            'monthly_price_ugx' => 180000,
            'is_public' => true,
            'is_active' => true,
        ]);
        BillingPlan::create([
            'code' => 'private-offer',
            'name' => 'Confidential One-Time License',
            'monthly_price_ugx' => 5000000,
            'is_public' => false,
            'is_active' => true,
        ]);
        BillingPlan::create([
            'code' => 'retired-offer',
            'name' => 'Discontinued Plan',
            'monthly_price_ugx' => 20000,
            'is_public' => true,
            'is_active' => false,
        ]);
        $this->post('/onboarding/request', [
            'business_name' => 'Fresh Produce Exporters',
            'contact_name' => 'Business Owner',
            'contact_email' => 'packages@example.test',
            'contact_phone' => '+256700123456',
            'industry' => 'export',
            'business_size' => 'growing',
            'privacy_agreement' => '1',
        ])->assertRedirect(route('site.onboarding'));

        Mail::assertSent(BoostedOnePackagesMail::class, function (BoostedOnePackagesMail $mail) {
            if (! $mail->hasTo('packages@example.test') || $mail->isIndicative) {
                return false;
            }
            $body = $mail->render();

            return count($mail->packages) === 1
                && $mail->packages[0]['name'] === 'Boosted One Starter'
                && $mail->packages[0]['monthly_price_ugx'] === 180000
                && str_contains($body, 'UGX 180,000')
                && ! str_contains($body, 'Confidential One-Time License')
                && ! str_contains($body, 'Discontinued Plan');
        });
    }

    public function test_platform_admin_can_resend_package_email_but_ordinary_users_cannot(): void
    {
        Mail::fake();
        $application = OnboardingRequest::create([
            'business_name' => 'Uganda Fresh',
            'contact_name' => 'Company Owner',
            'contact_email' => 'owner@example.test',
            'contact_phone' => '+256700123456',
            'industry' => 'export',
            'business_size' => 'small',
            'requested_plan' => 'to_discuss',
            'billing_cycle' => 'to_discuss',
            'privacy_accepted_at' => now(),
        ]);
        $route = route('platform.billing.request.packages', $application);
        $this->post($route)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post($route)->assertForbidden();

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->post($route)->assertRedirect();
        Mail::assertSent(BoostedOnePackagesMail::class, fn ($mail) => $mail->hasTo('owner@example.test'));
        $this->assertNotNull($application->fresh()->packages_sent_at);
    }

    public function test_sitemap_includes_each_public_product_page(): void
    {
        $sitemap = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $sitemap->assertSee(route('site.features'), false)
            ->assertSee(route('site.solutions'), false)
            ->assertSee(route('site.pricing'), false);

        foreach (array_keys(config('boostedone_features')) as $slug) {
            $sitemap->assertSee(route('site.feature', $slug), false);
        }
        foreach (array_keys(config('boostedone_solutions')) as $slug) {
            $sitemap->assertSee(route('site.solution', $slug), false);
        }
        $sitemap->assertDontSee(route('login'), false);
    }

    public function test_website_and_erp_assets_have_readability_rules(): void
    {
        $marketingCss = file_get_contents(public_path('assets/boosted-one.css'));
        $systemCss = file_get_contents(public_path('assets/modules.css'));
        $this->assertStringContainsString('body { font-size:17px;', $marketingCss);
        $this->assertStringContainsString('.data-table td { font-size:16px;', $systemCss);
        $this->assertStringContainsString('.sidebar nav a { font-size:16px;', $systemCss);
        $this->assertStringContainsString('font-size:16px;', $systemCss);
    }

    public function test_published_policies_are_indexable_and_included_in_the_sitemap(): void
    {
        $sitemap = $this->get('/sitemap.xml')->assertOk();

        foreach (['privacy' => 'Privacy Policy', 'retention' => 'Data Retention Policy', 'terms' => 'Terms of Service'] as $name => $title) {
            $this->get(route($name))->assertOk()
                ->assertSee($title)
                ->assertSee('Effective date: 24 October 2026')
                ->assertSee('content="index,follow"', false)
                ->assertDontSee('Draft:')
                ->assertDontSee('Confirm official contact');
            $sitemap->assertSee(route($name), false);
        }
    }
}
