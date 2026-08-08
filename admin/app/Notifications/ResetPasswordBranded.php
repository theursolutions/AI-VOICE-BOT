<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;

/**
 * Branded password-reset email.
 *
 * Laravel's built-in ResetPassword notification sends generic framework copy
 * ("You are receiving this email because we received a password reset request")
 * with no logo, no contact details and a tone that doesn't match the
 * verification email. This subclass keeps the framework's token handling and
 * URL signing untouched — only the presentation changes.
 *
 * Wired up in App\Models\User::sendPasswordResetNotification().
 */
class ResetPasswordBranded extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        // resetUrl() is the parent's — it applies the configured route and the
        // signed token, so the link stays valid exactly as before.
        $url = $this->resetUrl($notifiable);

        // Expiry in minutes, from config/auth.php passwords.*.expire.
        $ttl = Config::get('auth.passwords.' . Config::get('auth.defaults.passwords') . '.expire', 60);

        $name = trim((string) ($notifiable->name ?? ''));
        $first = $name !== '' ? explode(' ', $name)[0] : '';

        return (new MailMessage)
            ->subject('Reset your password')
            ->view('emails.auth.reset-password', [
                'url'  => $url,
                'name' => $first,
                'ttl'  => $ttl,
            ]);
    }
}
