<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use App\Support\LeadForms\LeadFormDefinition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A new lead on a lead form's board (BRAI-93) — for the members picked in the form's settings.
 */
class NewLeadNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences, SendsWebPush;

    public function __construct(public readonly Lead $lead) {}

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'lead_created');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'lead_created',
            'form_name' => $this->lead->form->name,
            'lead_number' => $this->lead->number,
            'lead_name' => $this->title(),
            'lead_url' => $this->url(),
        ];
    }

    public function toWebPush(object $notifiable, $notification)
    {
        return $this->webPush("New lead in {$this->lead->form->name}", $this->title(), $this->url());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $form = $this->lead->form;
        $mail = $this->brandedMail($notifiable, 'lead_created')
            ->subject("New lead #{$this->lead->number} — {$form->name}")
            ->eyebrow($form->project?->name)
            ->headline("New lead #{$this->lead->number} in {$form->name}")
            ->line("{$this->title()} filled in **".self::plain($form->name).'**.');

        $answers = LeadFormDefinition::readable($form->definition ?? [], $this->lead->answers ?? []);
        foreach (array_slice($answers, 0, 12) as $answer) {
            if ($answer['display'] !== '') {
                $mail->line('**'.self::plain($answer['label']).':** '.self::plain(Str::limit($answer['display'], 300)));
            }
        }

        return $mail->action('Open lead', $this->url());
    }

    private function title(): string
    {
        return self::plain($this->lead->name ?: ($this->lead->email ?: "Lead #{$this->lead->number}"));
    }

    private function url(): string
    {
        $form = $this->lead->form;
        $project = $form->project;

        return route('lead-forms.show', [$project->workspace->slug, $project->slug, $form->slug]).'?lead='.$this->lead->uuid;
    }

    /** Visitor input in a Markdown mail: no links, images or formatting. */
    public static function plain(string $text): string
    {
        return trim(preg_replace('/[\[\]()<>*_`#!|\\\\]/', '', str_replace(["\r", "\n"], ' ', $text)));
    }
}
