<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\Contracts\DigestibleMail;
use App\Support\Notifications\MailDigest;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/**
 * `mail` for batched activity notifications (BRAI-429): stores the email as a digest line
 * (MailDigest::capture) instead of sending it. When no line can be built (no workspace / subject)
 * the normal email goes out at once — an email is never dropped here.
 */
class DigestMailChannel
{
    public function __construct(private readonly MailChannel $mail) {}

    public function send(object $notifiable, Notification $notification): mixed
    {
        $entry = $notifiable instanceof User && $notification instanceof DigestibleMail
            ? $notification->digestEntry($notifiable)
            : null;

        if ($entry === null) {
            return $this->mail->send($notifiable, $notification);
        }

        MailDigest::capture($notifiable, $entry);

        return null;
    }
}
