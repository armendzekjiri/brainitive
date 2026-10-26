<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Support\LeadForms\LeadFormOptions;
use App\Support\Legal\PublicOperator;
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

    /**
     * The visitor's name goes into an email to an address they typed: short, and never a link
     * or a host name (phishing text through our sender).
     */
    public static function safeName(?string $name): string
    {
        $name = NewLeadNotification::plain((string) $name);
        if ($name === '' || mb_strlen($name) > 60 || preg_match('#://|www\.|@|\w\.[a-z]{2,}#i', $name)) {
            return 'there';
        }

        return $name;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $form = $this->lead->form;
        $settings = LeadFormOptions::settings($form->settings);
        $vars = [
            '{name}' => self::safeName($this->lead->name),
            '{form}' => NewLeadNotification::plain($form->name),
        ];

        $mail = (new MailMessage)
            ->subject(NewLeadNotification::plain(strtr($settings['confirmation_subject'] ?: 'Thanks for reaching out', $vars)));

        foreach (preg_split('/\n\s*\n/', strtr((string) $settings['confirmation_body'], $vars)) as $paragraph) {
            if (trim($paragraph) !== '') {
                $mail->line(trim($paragraph));
            }
        }

        // who sends it (BRAI-156): the workspace running the form, not the platform
        return $mail->line(PublicOperator::mailLine($form->project?->workspace, nameFallback: true));
    }
}
