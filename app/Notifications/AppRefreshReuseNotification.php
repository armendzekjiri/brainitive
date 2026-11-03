<?php

namespace App\Notifications;

use App\Models\AppDevice;
use App\Notifications\Messages\BrandedMailMessage;
use App\Support\Mail\BrandedMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A desktop / mobile app's refresh token was used a second time (BRAI-428): either a copy of it
 * was stolen or the app misbehaved. The device has already been signed out, with the web session its
 * window opened; this tells the account holder. Transactional account mail, not a preference.
 */
class AppRefreshReuseNotification extends Notification
{
    public function __construct(public readonly AppDevice $device) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $clientName = (string) (config('app-api.clients.'.$this->device->client.'.name') ?? 'Brainitive app');

        return BrandedMailMessage::account($notifiable, 'You get this email because it concerns the security of your Brainitive account.')
            ->subject('We signed out '.$clientName.' on one of your devices')
            ->headline('We signed out one of your devices')
            ->preheader('An old sign-in token of '.$clientName.' was used again.')
            ->line('An old sign-in token of '.$clientName.' on “'.$this->device->name.'” ('.$this->device->platform.') was used again. That can mean a copy of it was taken from the device, so we signed that device out to be safe.')
            ->line('If this was you, just sign in again in the app.')
            // account mail never links to the profile (that link means "manage notifications")
            ->action('Open Brainitive', BrandedMail::appRoute('dashboard'))
            ->line('If you don’t recognise this, change your password: that signs out every browser and app.');
    }
}
