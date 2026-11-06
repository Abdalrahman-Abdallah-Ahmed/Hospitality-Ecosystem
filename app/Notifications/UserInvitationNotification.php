<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A new user's first email: the link that lets them set a password. It is the
 * only way in, because the password they were created with is random and
 * nobody knows it.
 *
 * Sent during the request rather than queued, so the super admin learns at once
 * if it failed (spec 023). The link address comes from
 * ResetPassword::createUrlUsing() in AppServiceProvider.
 */
class UserInvitationNotification extends ResetPassword
{
    /**
     * The built-in message always quotes the default broker's 60 minutes.
     *
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $hours = (int) (config('auth.passwords.invitations.expire') / 60);

        return (new MailMessage)
            ->subject('Set your password for '.config('app.name'))
            ->line('An account has been created for you. Set a password to sign in.')
            ->action('Set your password', $url)
            ->line("This link will expire in {$hours} hours.")
            ->line('If you were not expecting this, you can ignore this email.');
    }
}
