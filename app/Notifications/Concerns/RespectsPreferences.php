<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Notifications\Channels\DigestMailChannel;
use App\Notifications\Contracts\DigestibleMail;
use App\Notifications\Messages\BrandedMailMessage;
use App\Notifications\NotificationCatalog;
use App\Support\Notifications\MailDigest;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * via() for catalog notifications (v1.39.0): the user's channel choice for the type's category,
 * limited to the channels this class can actually render (toMail / toWebPush).
 *
 *     public function via(object $notifiable): array
 *     {
 *         return $this->channelsFor($notifiable, 'item_assigned');
 *     }
 */
trait RespectsPreferences
{
    protected function channelsFor(object $notifiable, string $type): array
    {
        $channels = array_values(array_filter(
            NotificationCatalog::channels($notifiable, $type),
            fn (string $channel) => match ($channel) {
                'mail' => method_exists($this, 'toMail'),
                WebPushChannel::class => method_exists($this, 'toWebPush'),
                default => true,
            },
        ));

        // Activity emails wait for the notification email digest (BRAI-429); bell and push stay instant.
        if ($this instanceof DigestibleMail && MailDigest::batches($notifiable, $type)) {
            $channels = array_map(fn (string $channel) => $channel === 'mail' ? DigestMailChannel::class : $channel, $channels);
        }

        return $channels;
    }

    /**
     * The branded mail for this notification (BRAI-155): a member's mail ("Manage notifications" in the
     * footer) when it goes to a user, else a guest mail with $guestReason (ticket contacts, routed mail).
     */
    protected function brandedMail(object $notifiable, string $type, ?string $guestReason = null): BrandedMailMessage
    {
        return $notifiable instanceof User
            ? BrandedMailMessage::member($notifiable, $type)
            : BrandedMailMessage::guest($notifiable, $guestReason ?? 'You get this email because your address was added to this conversation.');
    }
}
