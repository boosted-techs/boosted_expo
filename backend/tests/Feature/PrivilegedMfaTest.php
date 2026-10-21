<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use App\Services\PrivilegedMfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivilegedMfaTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role='owner'):User
    {
        $business=Business::create([
            'name'=>'Secure Exporters','code'=>'SECURE','country_code'=>'UG',
            'base_currency'=>'UGX','timezone'=>'Africa/Kampala',
            'default_qr_level'=>'carton',
        ]);
        $user=User::factory()->create(['password'=>'Correct-long-Password-123']);
        BusinessMembership::create([
            'business_id'=>$business->id,'user_id'=>$user->id,
            'role'=>$role,'active'=>true,
        ]);
        $this->withSession(['active_business_id'=>$business->id]);
        return $user;
    }

    private function production():void
    {
        config()->set('app.env','production');
        config()->set('app.url','https://exports.example.test');
    }

    public function test_privileged_owner_must_enrol_before_sensitive_company_actions():void
    {
        $this->production();
        $owner=$this->account('owner');
        $this->actingAs($owner)->get('https://exports.example.test/products')
            ->assertRedirect('https://exports.example.test/security/mfa/setup');
        $this->post('https://exports.example.test/products',[
            'sku'=>'HIDDEN','name'=>'Unverified product','base_unit'=>'kg',
        ])->assertRedirect('https://exports.example.test/security/mfa/setup');
        $this->assertDatabaseCount('products',0);
        $this->get('https://exports.example.test/security/mfa/setup')
            ->assertOk()->assertSee('Secure your account');
        $secret=session('mfa_setup_secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D',$secret);
        $code=app(PrivilegedMfa::class)->oneTimeCode($secret,intdiv(time(),30));
        $this->post('https://exports.example.test/security/mfa/setup',[
            'code'=>$code,
        ])->assertRedirect(route('security.mfa.recovery'));
        $this->assertNotNull($owner->fresh()->mfa_enabled_at);
        $this->assertNotSame($secret,$owner->fresh()->getRawOriginal('mfa_secret'));
        $this->assertSame($owner->id,session('mfa_verified_user_id'));
        $codes=session('mfa_recovery_codes_display');
        $this->assertCount(10,$codes);
        $this->get('https://exports.example.test/security/mfa/recovery-codes')
            ->assertOk()->assertSee($codes[0]);
        $this->get('https://exports.example.test/security/mfa/recovery-codes')
            ->assertRedirect(route('workspace'));
        $this->get('https://exports.example.test/products')->assertOk();
    }

    public function test_recovery_codes_are_single_use_and_totp_cannot_be_replayed():void
    {
        $this->production();
        $owner=$this->account();
        $this->actingAs($owner)->get('https://exports.example.test/security/mfa/setup')
            ->assertOk();
        $secret=session('mfa_setup_secret');
        $mfa=app(PrivilegedMfa::class);
        $counter=intdiv(time(),30);
        $this->post('https://exports.example.test/security/mfa/setup',[
            'code'=>$mfa->oneTimeCode($secret,$counter),
        ])->assertRedirect(route('security.mfa.recovery'));
        $codes=session('mfa_recovery_codes_display');
        $this->withSession(['mfa_verified_user_id'=>null]);
        $this->get('https://exports.example.test/products')
            ->assertRedirect(route('security.mfa.challenge'));
        $this->post('https://exports.example.test/security/mfa/challenge',[
            'recovery_code'=>$codes[0],
        ])->assertRedirect();
        $this->assertCount(9,$owner->fresh()->mfa_recovery_codes);

        $this->withSession(['mfa_verified_user_id'=>null]);
        $this->post('https://exports.example.test/security/mfa/challenge',[
            'recovery_code'=>$codes[0],
        ])->assertSessionHasErrors('code');
        $this->assertCount(9,$owner->fresh()->mfa_recovery_codes);

        $next=$mfa->oneTimeCode($secret,$counter+1);
        $this->post('https://exports.example.test/security/mfa/challenge',[
            'code'=>$next,
        ])->assertRedirect();
        $this->withSession(['mfa_verified_user_id'=>null]);
        $this->post('https://exports.example.test/security/mfa/challenge',[
            'code'=>$next,
        ])->assertSessionHasErrors('code');
    }

    public function test_platform_admin_requires_mfa_and_viewer_still_has_scoped_access():void
    {
        $this->production();
        $admin=User::factory()->create(['is_platform_admin'=>true]);
        $this->actingAs($admin)->get('https://exports.example.test/platform/businesses')
            ->assertRedirect(route('security.mfa.setup'));

        $viewer=$this->account('viewer');
        $this->actingAs($viewer)->get('https://exports.example.test/products')
            ->assertOk();
    }
}
