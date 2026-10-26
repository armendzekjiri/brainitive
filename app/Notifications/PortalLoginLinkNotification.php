<?php

namespace App\Notifications;

use App\Support\Portal\PortalAuth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one-time sign-in link for a portal guest (v1.42.0). Mail only, outside the catalog:
 * guests have no preferences. The link opens a confirm page, so mail scanners that follow
 * links cannot spend it.
 */
class PortalLoginLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');

        return (new MailMessage)
            ->subject("Your sign-in link — {$app}")
            ->greeting('Hi '.$notifiable->displayName().',')
            ->line('Use this button to sign in. It works once and for '.PortalAuth::LINK_MINUTES.' minutes.')
            ->action('Sign in', route('guest.login.show', ['token' => $this->token], true))
            ->line("If you didn't ask for it, ignore this email: nobody can sign in without the link.")
            // the guest sign-in is run by the platform; the portals by the organisations that share them (BRAI-156)
            ->line("{$app} keeps your email address to sign you in to portals shared with you. Privacy policy: ".route('privacy'));
    }
}
