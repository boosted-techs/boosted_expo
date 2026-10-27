<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class BoostedOnePasswordResetNotification extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        // Use the canonical URL instead of an untrusted request Host header.
        // APP_URL must use HTTPS and the correct customer domain in production.
        $root = rtrim((string) config('app.url'), '/');

        return $root.route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false);
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your Boosted One password')
            ->greeting('Hello,')
            ->line('We received a request to reset the password for your Boosted One account.')
            ->action('Set a new password', $url)
            ->line('This secure link expires after 60 minutes and can be used only once.')
            ->line('If you did not request a reset, you can ignore this email. Your existing password will remain unchanged.')
            ->salutation('Boosted One · Boosted Technologies Limited');
    }
}
