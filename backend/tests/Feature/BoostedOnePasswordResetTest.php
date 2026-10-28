<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\BoostedOnePasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BoostedOnePasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function requestToken(User $user): string
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo(
            $user,
            BoostedOnePasswordResetNotification::class,
            function (BoostedOnePasswordResetNotification $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            }
        );

        $this->assertIsString($token);

        return $token;
    }

    public function test_login_has_show_password_button_and_forgot_password_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('type="password"', false)
            ->assertSee('id="login-password"', false)
            ->assertSee('type="button"', false)
            ->assertSee('data-password-toggle', false)
            ->assertSee('aria-controls="login-password"', false)
            ->assertSee(route('password.request'), false)
            ->assertSee('/assets/password-visibility.js', false);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Email password reset link')
            ->assertSee('Enter your account email');
    }

    public function test_password_reset_email_has_a_usable_link_to_the_new_password_form(): void
    {
        config(['app.url' => 'https://boostedone.online']);

        $user = User::factory()->create(['email' => 'owner@example.test']);
        $token = $this->requestToken($user);

        Notification::assertSentTo($user, BoostedOnePasswordResetNotification::class,
            function (BoostedOnePasswordResetNotification $notice) use ($user, $token): bool {
                $mail = $notice->toMail($user);
                $url = $mail->actionUrl;

                $this->assertSame('Reset your Boosted One password', $mail->subject);
                $this->assertStringStartsWith('https://boostedone.online/reset-password/', $url);
                $this->assertStringContainsString($token, $url);
                $this->assertStringContainsString('email=owner%40example.test', $url);

                return true;
            }
        );

        $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))
            ->assertOk()
            ->assertSee('Set a new password')
            ->assertSee('name="token"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('aria-controls="new-password"', false)
            ->assertSee('aria-controls="confirm-password"', false)
            ->assertSee('/assets/password-visibility.js', false);
    }

    public function test_password_reset_updates_credentials_and_invalidates_the_single_use_token(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $token = $this->requestToken($user);
        $newPassword = 'MyNewSecurePassword!2026';

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => $newPassword,
        ])->assertRedirect(route('workspace'));

        $this->post(route('logout'))->assertRedirect(route('login'));

        // A consumed token cannot be used again, even after signing out.
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'YetAnotherSecurePassword!2026',
            'password_confirmation' => 'YetAnotherSecurePassword!2026',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
    }

    public function test_invalid_expired_and_weak_tokens_cannot_reset_the_password(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->post(route('password.update'), [
            'token' => 'not-a-valid-token',
            'email' => $user->email,
            'password' => 'StrongPasswordButInvalidToken!2',
            'password_confirmation' => 'StrongPasswordButInvalidToken!2',
        ])->assertSessionHasErrors('email');

        $token = $this->requestToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertSessionHasErrors('password');

        $this->travel(61)->minutes();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'StrongButExpiredPassword!2026',
            'password_confirmation' => 'StrongButExpiredPassword!2026',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_unknown_email_does_not_reveal_account_existence(): void
    {
        Notification::fake();

        $this->post(route('password.email'), [
            'email' => 'nonexistent@example.test',
        ])->assertRedirect()->assertSessionHas('status');

        Notification::assertNothingSent();
    }
}
