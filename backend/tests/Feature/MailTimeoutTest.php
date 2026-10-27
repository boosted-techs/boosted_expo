<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class MailTimeoutTest extends TestCase
{
    public function test_smtp_transport_has_a_bounded_socket_timeout(): void
    {
        config(['mail.mailers.smtp.url' => null, 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 2525]);
        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertGreaterThan(0, $transport->getStream()->getTimeout());
        $this->assertLessThanOrEqual(5, $transport->getStream()->getTimeout());
    }

    public function test_password_recovery_transport_failure_is_reported_without_disclosing_account_existence(): void
    {
        Exceptions::fake();
        $failure = new TransportException('SMTP greeting timed out.');
        Password::shouldReceive('sendResetLink')->once()->with(['email' => 'owner@example.test'])->andThrow($failure);
        $failed = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'OWNER@example.test']);
        $failed->assertRedirect(route('password.request'))->assertSessionHas('status');
        $message = session('status');
        Exceptions::assertReported(TransportException::class);
        Password::shouldReceive('sendResetLink')->once()->with(['email' => 'missing@example.test'])->andReturn(Password::INVALID_USER);
        $this->post(route('password.email'), ['email' => 'missing@example.test'])->assertRedirect()->assertSessionHas('status', $message);
        $this->assertStringNotContainsString('SMTP', $message);
    }
}
