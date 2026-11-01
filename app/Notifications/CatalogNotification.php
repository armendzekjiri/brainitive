<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use App\Notifications\Contracts\DigestibleMail;
use App\Support\Notifications\DigestEntry;
use App\Support\Notifications\MailDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
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
abstract class CatalogNotification extends Notification implements DigestibleMail, ShouldQueue
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

    /**
     * The branded mail (BRAI-155): context as the eyebrow, the title as the headline, the body (a comment,
     * note, reason …) quoted, one button. Members get "Manage notifications" in the footer.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->toDatabase($notifiable);
        $workspace = property_exists($this, 'workspace') && $this->workspace instanceof Workspace ? $this->workspace->name : null;

        return $this->brandedMail($notifiable, $this->catalogType())
            ->subject($d['title'])
            ->eyebrow($d['project_name'])
            ->headline($d['title'])
            ->quote($d['body'])
            ->context($workspace)
            ->action('Open in Brainitive', $d['url']);
    }

    /**
     * The model the notification is about, for the notification email digest (BRAI-429): its `view`
     * policy is checked again before the digest goes out. Only types in MailDigest::TYPES use it;
     * null = never batched.
     */
    protected function digestSubject(): ?Model
    {
        return null;
    }

    /** The subject's own title in the digest list ("WEB-42 · <this>"), if the title doesn't carry it. */
    protected function digestSubjectTitle(): ?string
    {
        return null;
    }

    /** The digest line: the same title, context, preview and link as the bell and the single email. */
    public function digestEntry(object $notifiable): ?DigestEntry
    {
        $subject = $this->digestSubject();
        $workspace = $subject ? MailDigest::workspaceOf($subject) : null;
        if (! $subject || ! $workspace) {
            return null;
        }
        $d = $this->toDatabase($notifiable);

        return new DigestEntry(
            type: $this->catalogType(),
            workspace: $workspace,
            subject: $subject,
            title: $d['title'],
            url: $d['url'],
            mailSubject: $d['title'],
            subjectKey: $d['subject_key'],
            subjectTitle: $this->digestSubjectTitle(),
            context: $d['project_name'],
            body: $d['body'],
        );
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
