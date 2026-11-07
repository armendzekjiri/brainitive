<?php

namespace App\Notifications;

use App\Models\ApiKey;
use App\Notifications\Messages\BrandedMailMessage;
use App\Support\Mail\BrandedMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A personal API key was created (or got a new secret) on the account (Developer API, v1.52.0). Tells the
 * account holder, so a key made by someone else with their session doesn't go unnoticed. Transactional account
 * mail, not a preference; never contains the key.
 */
class ApiKeyCreatedNotification extends Notification
{
    public function __construct(public readonly ApiKey $key, public readonly bool $regenerated = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $what = $this->regenerated ? 'got a new secret' : 'was created';

        return BrandedMailMessage::account($notifiable, 'You get this email because it concerns the security of your Brainitive account.')
            ->subject($this->regenerated ? 'An API key of your account got a new secret' : 'A new API key was created for your account')
            ->headline($this->regenerated ? 'API key regenerated' : 'New API key')
            ->preheader('The API key “'.$this->key->name.'” '.$what.'.')
            ->line('The API key “'.$this->key->name.'” ('.$this->key->prefix.'…) '.$what.' on your Brainitive account'
                .($this->key->created_from_ip ? ' from the network '.self::coarse($this->key->created_from_ip) : '').'.')
            ->line('Scripts and apps can use it to work with your content through the Brainitive API, within the workspaces and areas you chose.')
            ->action('Review your API keys', BrandedMail::appRoute('profile.api-keys.index'))
            ->line('If you don’t recognise this, revoke the key and change your password: that also revokes every API key.');
    }

    /** 203.0.113.x / the first three groups of an IPv6 address: where, without the whole address. */
    private static function coarse(string $ip): string
    {
        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)).'::';
        }
        $parts = explode('.', $ip);

        return count($parts) === 4 ? $parts[0].'.'.$parts[1].'.'.$parts[2].'.x' : $ip;
    }
}
