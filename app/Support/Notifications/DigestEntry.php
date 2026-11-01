<?php

namespace App\Support\Notifications;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the notification email digest (BRAI-429), built by a DigestibleMail notification when
 * its email is captured. `subject` is the model whose `view` policy is checked again before the
 * digest is sent (an issue, a page, a task list, a board); `workspace` is the digest it joins.
 * Text is plain (no Markdown, no HTML): the mail templates escape it.
 */
final class DigestEntry
{
    public function __construct(
        /** the catalog type (a key of MailDigest::TYPES) */
        public readonly string $type,
        public readonly Workspace $workspace,
        public readonly Model $subject,
        /** what happened, worded for the recipient: "Sam assigned you to WEB-42" */
        public readonly string $title,
        /** absolute app URL */
        public readonly string $url,
        /** subject line when this is the only entry (the mail looks like the normal one) */
        public readonly string $mailSubject,
        /** "WEB-42" */
        public readonly ?string $subjectKey = null,
        /** "Fix the login redirect" */
        public readonly ?string $subjectTitle = null,
        /** "Website · Main board" */
        public readonly ?string $context = null,
        /** a short preview (comment, status change …) */
        public readonly ?string $body = null,
        /** the button of the single mail */
        public readonly string $action = 'Open in Brainitive',
    ) {}

    /** @return array<string, string|null> what is stored in pending_notification_emails.entry */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'mail_subject' => $this->mailSubject,
            'subject_key' => $this->subjectKey,
            'subject_title' => $this->subjectTitle,
            'context' => $this->context,
            'body' => $this->body,
            'action' => $this->action,
        ];
    }
}
