<?php

namespace App\Notifications;

use App\Notifications\Messages\BrandedMailMessage;
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
     * Get the verification URL for the given notifiable: a temporary signed URL whose signature
     * covers only the path + query (`signed:relative`), so the link keeps working whatever
     * host/port the app is reached on, while the host itself always comes from APP_URL.
     */
    protected function verificationUrl(mixed $notifiable): string
    {
        $path = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
            absolute: false,
        );

        return rtrim((string) (Config::get('app.url') ?: url('/')), '/').$path;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);

        return BrandedMailMessage::account($notifiable, 'You get this email because this address was used to create a Brainitive account.')
            ->subject('Verify your email — Brainitive')
            ->headline('Verify your email address')
            ->preheader('One click and your Brainitive account is ready.')
            ->line('Thanks for signing up! Please click the button below to verify your email address and get started.')
            ->action('Verify email address', $url)
            ->line('If you didn’t create an account, you can safely ignore this email.');
    }
}
