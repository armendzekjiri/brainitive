<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Support\LeadForms\LeadFormOptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The confirmation email a lead gets after submitting a form (subject and body from the form's
 * settings; `{name}` and `{form}` are replaced).
 */
class LeadConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Lead $lead) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $form = $this->lead->form;
        $settings = LeadFormOptions::settings($form->settings);
        $vars = [
            '{name}' => NewLeadNotification::plain($this->lead->name ?: 'there'),
            '{form}' => NewLeadNotification::plain($form->name),
        ];

        $mail = (new MailMessage)
            ->subject(NewLeadNotification::plain(strtr($settings['confirmation_subject'] ?: 'Thanks for reaching out', $vars)));

        foreach (preg_split('/\n\s*\n/', strtr((string) $settings['confirmation_body'], $vars)) as $paragraph) {
            if (trim($paragraph) !== '') {
                $mail->line(trim($paragraph));
            }
        }

        return $mail;
    }
}
