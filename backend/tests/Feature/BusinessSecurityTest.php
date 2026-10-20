<?php

namespace Tests\Feature;

use App\Mail\BusinessInvitationMail;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\BusinessMembership;
use App\Models\Product;
use App\Models\User;
use App\Services\InviteBusinessUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BusinessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $code): Business
    {
        return Business::create([
            'name' => $code.' Exports', 'code' => $code,
            'country_code' => 'UG', 'base_currency' => 'UGX',
            'timezone' => 'Africa/Kampala', 'default_qr_level' => 'batch',
        ]);
    }

    private function join(User $user, Business $business, string $role): void
    {
        BusinessMembership::create([
            'user_id' => $user->id, 'business_id' => $business->id,
            'role' => $role, 'active' => true,
        ]);
    }

    public function test_anonymous_access_is_blocked_and_there_is_no_registration_route(): void
    {
        $this->get('/workspace')->assertRedirect('/login');
        $this->get('/products')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
    }

    public function test_only_platform_admin_can_register_exporting_company(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $data = [
            'name' => 'Another Exporter', 'code' => 'ACME', 'country_code' => 'UG',
            'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala',
            'default_qr_level' => 'carton', 'owner_email' => 'owner@export.test',
        ];
        $this->actingAs($user)->post(route('platform.businesses.store'), $data)->assertForbidden();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->post(route('platform.businesses.store'), $data)
            ->assertRedirect(route('platform.businesses.index'));
        $this->assertDatabaseHas('businesses', ['code' => 'ACME', 'default_qr_level' => 'carton']);
        Mail::assertSent(BusinessInvitationMail::class, fn ($mail) => $mail->invitation->role === 'owner');
    }

    public function test_new_user_redeems_single_use_invitation_and_is_verified(): void
    {
        Mail::fake();
        $business = $this->business('FRESH');
        app(InviteBusinessUser::class)->send($business, 'starter@example.test', 'owner');
        $token = null;
        Mail::assertSent(BusinessInvitationMail::class, function ($mail) use (&$token) {
            $token = $mail->plainToken;
            return true;
        });
        $this->assertNotNull($token);
        $this->get(route('invitations.show', $token))->assertOk();
        $this->post(route('invitations.accept', $token), [
            'name' => 'New Export Owner', 'password' => 'A-strong-test-passphrase-123',
            'password_confirmation' => 'A-strong-test-passphrase-123',
        ])->assertRedirect(route('workspace'));
        $user = User::where('email', 'starter@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('business_memberships', [
            'user_id' => $user->id, 'business_id' => $business->id, 'role' => 'owner',
        ]);
        $this->post(route('invitations.accept', $token))->assertNotFound();
    }

    public function test_expired_invitation_cannot_be_redeemed(): void
    {
        $business = $this->business('FRESH');
        BusinessInvitation::create([
            'business_id' => $business->id, 'email' => 'old@example.test',
            'role' => 'viewer', 'token_hash' => hash('sha256', 'expired-token'),
            'expires_at' => now()->subMinute(),
        ]);
        $this->get(route('invitations.show', 'expired-token'))->assertNotFound();
    }

    public function test_user_cannot_select_company_without_membership(): void
    {
        $one = $this->business('ONE');
        $two = $this->business('TWO');
        $user = User::factory()->create();
        $this->join($user, $one, 'owner');
        $this->actingAs($user)->post(route('businesses.activate'), [
            'business_id' => $two->id,
        ])->assertNotFound();
        $this->assertNotEquals($two->id, session('active_business_id'));
    }

    public function test_company_products_are_separated_in_every_read_and_write(): void
    {
        $one = $this->business('ONE');
        $two = $this->business('TWO');
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->join($first, $one, 'owner');
        $this->join($second, $two, 'owner');

        $this->actingAs($first)->withSession(['active_business_id' => $one->id])
            ->post(route('products.store'), [
                'sku' => 'AVO-001', 'name' => 'Avocado', 'base_unit' => 'kg',
            ])->assertRedirect(route('products.index'));
        $product = Product::query()->where('business_id', $one->id)->firstOrFail();

        $this->actingAs($second)->withSession(['active_business_id' => $two->id])
            ->get(route('products.index'))->assertDontSee('AVO-001');
        $this->get(route('products.edit', $product->id))->assertNotFound();
        $this->put(route('products.update', $product->id), [
            'sku' => 'STOLEN', 'name' => 'Other', 'base_unit' => 'kg',
        ])->assertNotFound();
        $this->post(route('products.store'), [
            'sku' => 'AVO-001', 'name' => 'Second exporter avocado', 'base_unit' => 'kg',
        ])->assertRedirect(route('products.index'));
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Avocado']);
    }

    public function test_read_only_employee_cannot_write_products_or_settings(): void
    {
        $business = $this->business('ONE');
        $user = User::factory()->create();
        $this->join($user, $business, 'viewer');
        $this->actingAs($user)->get(route('products.index'))->assertOk();
        $this->post(route('products.store'), [
            'sku' => 'AVO-002', 'name' => 'Avocado', 'base_unit' => 'kg',
        ])->assertForbidden();
        $this->put(route('business.settings.update'), [
            'default_qr_level' => 'carton',
        ])->assertForbidden();
        $this->get('/workspace/finance')->assertForbidden();
    }

    public function test_suspended_company_members_cannot_access_workspace(): void
    {
        $business = $this->business('ONE');
        $business->update(['is_active' => false]);
        $user = User::factory()->create();
        $this->join($user, $business, 'owner');

        $this->actingAs($user)->get('/workspace')->assertRedirect(route('businesses.choose'));
    }

    public function test_owner_can_update_qr_default_without_changing_other_companies(): void
    {
        $one = $this->business('ONE');
        $two = $this->business('TWO');
        $user = User::factory()->create();
        $this->join($user, $one, 'owner');
        $this->actingAs($user)->put(route('business.settings.update'), [
            'default_qr_level' => 'carton',
        ])->assertRedirect();
        $this->assertSame('carton', $one->fresh()->default_qr_level);
        $this->assertSame('batch', $two->fresh()->default_qr_level);
    }

    public function test_team_administrator_cannot_invite_another_administrator(): void
    {
        Mail::fake();
        $business = $this->business('ONE');
        $user = User::factory()->create();
        $this->join($user, $business, 'admin');
        $this->actingAs($user)->post(route('team.invite'), [
            'email' => 'admin2@example.test', 'role' => 'admin',
        ])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_invalid_password_sign_in_does_not_authenticate(): void
    {
        $user = User::factory()->create(['email' => 'hello@example.test']);
        $this->post(route('login.store'), [
            'email' => $user->email, 'password' => 'incorrect-secret',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}