<?php

namespace App\Notifications;

use App\Models\ClientDeliverable;
use App\Models\ClientPortal;
use App\Support\ClientPortal\ClientPortalLinks;
use App\Support\Legal\PublicOperator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Mail to a client of a portal (v1.42.0, BRAI-73): mail only, outside the catalog (guests have no
 * preferences), like TicketContact mail. Three kinds:
 *
 * - invited: someone invited you (a separate one-time sign-in link comes from PortalAuth::sendLink);
 * - published: a new deliverable / a new version waits for your review;
 * - replied: the team answered on a deliverable.
 *
 * Team text is plain text in the mail (no Markdown links).
 */
class ClientPortalGuestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const INVITED = 'invited';

    public const PUBLISHED = 'published';

    public const REPLIED = 'replied';

    public function __construct(
        public readonly string $kind,
        public readonly ClientPortal $portal,
        public readonly ?ClientDeliverable $deliverable = null,
        public readonly ?string $actorName = null,
        public readonly ?string $text = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $portal = self::plain($this->portal->title);
        $actor = self::plain($this->actorName ?: 'The team');
        $mail = (new MailMessage)->greeting('Hi '.self::plain($notifiable->displayName()).',');

        if ($this->kind === self::INVITED) {
            $mail->subject("You're invited to {$portal}")
                ->line("{$actor} invited you to the client portal “{$portal}”, where you can review deliverables, approve them or ask for changes.")
                ->line('We also sent you a one-time sign-in link. If it has expired, open the portal and enter your email to get a new one.')
                ->action('Open the portal', route('client-portal.show', $this->portal->slug));
        } elseif ($this->kind === self::PUBLISHED && $this->deliverable) {
            $title = self::plain($this->deliverable->title);
            $mail->subject("Ready for your review: {$title}")
                ->line("{$actor} shared “{$title}” (version {$this->deliverable->version}) in {$portal}.")
                ->action('Review it', ClientPortalLinks::client($this->deliverable));
            if ($this->text) {
                $mail->line(self::plain($this->text));
            }
        } elseif ($this->deliverable) {
            $title = self::plain($this->deliverable->title);
            $mail->subject("New reply on {$title}")
                ->line("{$actor} replied on “{$title}”:")
                ->line(self::plain((string) $this->text))
                ->action('Open the conversation', ClientPortalLinks::client($this->deliverable));
        }

        // who runs the portal (BRAI-156)
        return $mail->line('You get this email because you were invited to this client portal.')
            ->line(PublicOperator::mailLine($this->portal->project?->workspace));
    }

    /** User text in a Markdown mail: no links, images or formatting, at most 500 characters. */
    private static function plain(string $text): string
    {
        return Str::limit(trim(preg_replace('/[\[\]()<>*_`#!|\\\\]/', '', str_replace(["\r", "\n"], ' ', $text))), 500);
    }
}
