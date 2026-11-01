<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Messages\BrandedMailMessage;
use App\Support\Mail\BrandedMail;
use App\Support\Notifications\MailDigest;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The notification email digest (BRAI-429): the activity emails of one person in one workspace from
 * one window, as one email. Sent by SendNotificationDigest (mail only — the bell already has every
 * line). One line looks like the normal email of that notification; several become a linked list.
 */
class NotificationDigestNotification extends Notification
{
    use RespectsPreferences;

    /**
     * @param  list<array<string, mixed>>  $lines  MailDigest::deliverable() output, oldest first
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly array $lines,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): BrandedMailMessage
    {
        return count($this->lines) === 1
            ? $this->single($notifiable, $this->lines[0])
            : $this->digest($notifiable);
    }

    /** "5 updates in Northwind Studio: 3 assignments, 2 comments" */
    public function subjectLine(): string
    {
        return $this->headlineText().': '.$this->summary();
    }

    private function headlineText(): string
    {
        return count($this->lines).' updates in '.BrandedMail::line($this->workspace->name, 60);
    }

    private function summary(): string
    {
        return collect($this->lines)
            ->countBy(fn (array $line) => MailDigest::TYPES[$line['type']]['noun'] ?? 'update')
            ->sortDesc()
            ->map(fn (int $count, string $noun) => $count.' '.Str::plural($noun, $count))
            ->take(3)
            ->implode(', ');
    }

    /** @param array<string, mixed> $line */
    private function single(object $notifiable, array $line): BrandedMailMessage
    {
        return $this->brandedMail($notifiable, $line['type'])
            ->subject((string) $line['mail_subject'])
            ->eyebrow($line['context'] ?? null)
            ->headline((string) $line['title'])
            ->when(filled($line['subject_title'] ?? null), fn (BrandedMailMessage $mail) => $mail->line((string) $line['subject_title']))
            ->quote($line['body'] ?? null)
            ->context($this->workspace->name)
            ->action((string) ($line['action'] ?? 'Open in Brainitive'), (string) $line['url']);
    }

    private function digest(object $notifiable): BrandedMailMessage
    {
        $zone = $notifiable instanceof User ? $notifiable->localTimezone() : config('app.timezone');

        return BrandedMailMessage::member($notifiable)
            ->subject($this->subjectLine())
            ->headline($this->headlineText())
            ->preheader($this->summary())
            ->line('Here is what happened, newest last:')
            ->entries(array_map(fn (array $line) => [
                'label' => (string) $line['label'],
                'time' => self::time($line['at'] ?? null, $zone),
                'link' => trim(implode(' · ', array_filter([$line['subject_key'] ?? null, $line['subject_title'] ?? null]))) ?: (string) $line['title'],
                'title' => (string) $line['title'],
                'context' => $line['context'] ?? null,
                'body' => $line['body'] ?? null,
                'url' => (string) $line['url'],
            ], $this->lines))
            ->context($this->workspace->name)
            ->action('Open notifications', BrandedMail::appRoute('notifications.page'));
    }

    private static function time(mixed $at, string $zone): ?string
    {
        if ($at === null) {
            return null;
        }
        $at = $at instanceof CarbonInterface ? $at : Carbon::parse((string) $at);

        return $at->copy()->setTimezone($zone)->format('H:i');
    }
}
