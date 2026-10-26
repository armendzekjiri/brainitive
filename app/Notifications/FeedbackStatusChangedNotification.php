<?php

namespace App\Notifications;

use App\Models\FeedbackPortal;
use App\Models\FeedbackPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * To the followers (portal identities) of a feedback post when its public status changes
 * (v1.42.0, BRAI-60). Mail only, outside the catalog: guests have no preferences. Each follower
 * gets it once per post and status (FeedbackNotifier, feedback_status_mails). Carries a signed
 * unfollow link that works without signing in.
 */
class FeedbackStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly FeedbackPost $post,
        public readonly FeedbackPortal $portal,
        public readonly string $status,
        public readonly ?string $note,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function unfollowUrl(FeedbackPortal $portal, FeedbackPost $post, string $identityUuid): string
    {
        return URL::signedRoute('feedback.unsubscribe', ['portalSlug' => $portal->slug, 'number' => $post->number, 'identity' => $identityUuid]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = FeedbackPost::STATUS_LABELS[$this->status] ?? $this->status;
        $title = self::plain(Str::limit($this->post->title, 100));

        $mail = (new MailMessage)
            ->subject("“{$title}” is now {$label}")
            ->greeting('Hi '.self::plain($notifiable->displayName()).',')
            ->line("An idea you follow on {$this->plainPortal()} changed its status to **{$label}**: “{$title}”.");

        if ($this->note) {
            $mail->line(self::plain(Str::limit($this->note, 1000)));
        }

        return $mail
            ->action('View the idea', route('feedback.post', [$this->portal->slug, $this->post->number]))
            ->line('Don’t want these emails? [Stop following this idea]('.self::unfollowUrl($this->portal, $this->post, $notifiable->uuid).').');
    }

    private function plainPortal(): string
    {
        return self::plain($this->portal->title);
    }

    /** User text in a Markdown mail: no links, images or formatting. */
    private static function plain(string $text): string
    {
        return trim(preg_replace('/[\[\]()<>*_`#!|\\\\]/', '', str_replace(["\r", "\n"], ' ', $text)));
    }
}
