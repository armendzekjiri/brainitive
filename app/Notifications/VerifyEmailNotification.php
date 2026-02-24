<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailBase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends VerifyEmailBase
{
    use Queueable;

    /**
     * Get the verification URL for the given notifiable.
     * Uses a plain URL (no signature) so it works regardless of APP_URL/host/port.
     * Security: id + hash(email) + expiry are validated in the controller.
     */
    protected function verificationUrl(mixed $notifiable): string
    {
        $expires = Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60))->timestamp;

        $rootUrl = Config::get('app.url');
        if ($rootUrl) {
            URL::forceRootUrl($rootUrl);
        }

        try {
            return URL::route('verification.verify', [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
                'expires' => $expires,
            ], true);
        } finally {
            if ($rootUrl) {
                URL::forceRootUrl(null);
            }
        }
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);
        $appName = config('app.name');

        return (new MailMessage)
            ->subject("Verify your email — {$appName}")
            ->view('emails.verify-email', [
                'url' => $url,
                'user' => $notifiable,
                'appName' => $appName,
            ]);
    }
}
