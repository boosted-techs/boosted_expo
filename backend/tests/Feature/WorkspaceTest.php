<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $business = Business::create([
            'name' => 'Sample Exporter', 'code' => 'SAMPLE', 'country_code' => 'UG',
            'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'batch',
        ]);
        $user = User::factory()->create();
        BusinessMembership::create([
            'business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true,
        ]);
        $this->actingAs($user);
    }

    public function test_overview_renders_for_member(): void
    {
        $this->get('/workspace')->assertOk()->assertSee('Boosted One')
            ->assertSee('Sample Exporter');
    }

    public function test_qr_and_printing_preview_renders_for_owner(): void
    {
        $this->get('/workspace/templates')->assertOk()->assertSee('carton QR');
    }

    public function test_unknown_section_returns_not_found_for_signed_in_user(): void
    {
        $this->get('/workspace/nonexistent')->assertNotFound();
    }
}
