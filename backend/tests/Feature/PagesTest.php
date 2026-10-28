<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_see_sign_in_page(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in')->assertDontSee('Company accounts are created')->assertDontSee('From local markets');
    }

    public function test_authenticated_owner_can_reach_catalogue_import_team_and_settings(): void
    {
        $business = Business::create([
            'name' => 'Sample Trader', 'code' => 'SAMPLE', 'country_code' => 'UG', 'base_currency' => 'UGX',
            'timezone' => 'Africa/Kampala', 'default_qr_level' => 'batch',
        ]);
        $user = User::factory()->create();
        BusinessMembership::create([
            'business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true,
        ]);
        $product = Product::create([
            'business_id' => $business->id, 'sku' => 'AVO-1', 'name' => 'Avocado', 'base_unit' => 'kg',
        ]);

        $this->actingAs($user)->get(route('products.index'))->assertOk()->assertSee('Avocado');
        $this->get(route('products.edit', $product->id))->assertOk()->assertSee('Product QR');
        $this->get(route('products.import'))->assertOk()->assertSee('Preview products');
        $this->get(route('team.index'))->assertOk()->assertSee('Team &amp; Access', false);
        $this->get(route('business.settings'))->assertOk()->assertSee('Default QR level');
    }

    public function test_platform_administrator_can_open_business_admin(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->get(route('platform.businesses.index'))
            ->assertOk()->assertSee('Register a business');
    }
}
