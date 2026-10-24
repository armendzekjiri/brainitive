<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Base for every notification type added in v1.39.0 (BRAI-103). Subclasses only say what happened
 * (message()); this class turns it into the payload contract of docs/ai/tasks/release-v1.39.0.md:
 *
 *     type, title, body, url, actor_name, actor_avatar, project_name, subject_key
 *
 * and delivers it on the channels the user picked for the type's category (in-app, push, email).
 * The bell renders these generically from those keys, so a new type needs no frontend change.
 */
abstract class CatalogNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences, SendsWebPush;

    /** The catalog type (a key of NotificationCatalog::TYPES). */
    abstract public function catalogType(): string;

    /**
     * What happened, worded for the recipient.
     *
     * @return array{title: string, url: string, body?: string|null, actor?: User|null, project_name?: string|null, subject_key?: string|null}
     */
    abstract protected function message(object $notifiable): array;

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, $this->catalogType());
    }

    public function toDatabase(object $notifiable): array
    {
        $m = $this->message($notifiable);
        $actor = $m['actor'] ?? null;
        $body = isset($m['body']) ? self::excerpt($m['body']) : null;

        return [
            'type' => $this->catalogType(),
            'title' => $m['title'],
            'body' => $body === '' ? null : $body,
            'url' => $m['url'],
            'actor_name' => $actor?->name,
            'actor_avatar' => $actor?->avatar_display,
            'project_name' => $m['project_name'] ?? null,
            'subject_key' => $m['subject_key'] ?? null,
        ];
    }

    public function toWebPush(object $notifiable, $notification)
    {
        $d = $this->toDatabase($notifiable);

        return $this->webPush($d['title'], $d['body'] ?? $d['project_name'] ?? '', $d['url']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->toDatabase($notifiable);
        $mail = (new MailMessage)->subject($d['title'])->line(self::plain($d['title']));

        if ($d['body']) {
            $mail->line(self::plain($d['body']));
        }
        if ($d['project_name']) {
            $mail->line(self::plain($d['project_name']));
        }

        return $mail->action('Open in Brainitive', $d['url']);
    }

    /** One line of plain text from HTML / rich text, at most 200 characters. */
    public static function excerpt(?string $text): string
    {
        $plain = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', (string) $text)), ENT_QUOTES | ENT_HTML5);

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $plain)), 200);
    }

    /** User text in a Markdown mail: no links, images or formatting. */
    protected static function plain(string $text): string
    {
        return trim(preg_replace('/[\[\]()<>*_`#!|\\\\]/', '', str_replace(["\r", "\n"], ' ', $text)));
    }
}
