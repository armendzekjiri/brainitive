<?php

namespace App\Notifications\Messages;

use App\Notifications\NotificationCatalog;
use App\Support\Mail\BrandedMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * A notification mail on the Brainitive layout (BRAI-155). Renders through the published
 * vendor/notifications/email template; the extra keys below reach it through data().
 *
 *     BrandedMailMessage::member($user, 'item_assigned')   // footer: "Manage notifications"
 *     BrandedMailMessage::guest($contact, 'You get this email because you contacted Acme support.')
 *     BrandedMailMessage::account($user, 'You get this email because …')   // transactional, no preferences
 *
 * then the usual MailMessage calls plus headline() / eyebrow() / quote() / preheader() / signOff().
 * Text passed here is echoed escaped; Markdown links in it are neutralised (Markdown::withSecuredEncoding).
 */
class BrandedMailMessage extends MailMessage
{
    public const MEMBER = 'member';

    public const GUEST = 'guest';

    public const ACCOUNT = 'account';

    public string $audience = self::ACCOUNT;

    public ?string $preheader = null;

    public ?string $headline = null;

    public ?string $eyebrow = null;

    public ?string $quote = null;

    /** Shown after "Brainitive — " in the footer (workspace, helpdesk, portal); null = the instance name. */
    public ?string $context = null;

    /** "Why you got this" line in the footer. */
    public ?string $reason = null;

    /** @var list<array{label: string, url: string}> */
    public array $footerLinks = [];

    /**
     * Linked list lines (notification email digest, BRAI-429), rendered by `mail::entries`.
     *
     * @var list<array{label: string, time: ?string, link: string, title: string, context: ?string, body: ?string, url: string}>
     */
    public array $entries = [];

    /** A member's notification (one of their channels on Profile → Notifications). */
    public static function member(object $notifiable, ?string $type = null): static
    {
        $category = $type !== null ? (NotificationCatalog::CATEGORIES[NotificationCatalog::TYPES[$type] ?? ''] ?? null) : null;

        $mail = static::greeted($notifiable);
        $mail->audience = self::MEMBER;
        $mail->reason = $category
            ? "You get this email because email is on for “{$category['label']}” in your notification settings."
            : 'You get this email because of your notification settings.';

        return $mail;
    }

    /** A customer, client or visitor: no preferences link, a reason instead. */
    public static function guest(object $notifiable, string $reason): static
    {
        $mail = static::greeted($notifiable);
        $mail->audience = self::GUEST;
        $mail->reason = $reason;

        return $mail;
    }

    /** Transactional mail to an account holder or invitee (verification, password, invitation). */
    public static function account(object $notifiable, string $reason): static
    {
        $mail = static::greeted($notifiable);
        $mail->audience = self::ACCOUNT;
        $mail->reason = $reason;

        return $mail;
    }

    protected static function greeted(object $notifiable): static
    {
        return (new static)->greeting(BrandedMail::greeting(BrandedMail::nameOf($notifiable)));
    }

    /** For text that brings its own greeting (lead confirmations: the form owner's "Hi {name},"). */
    public function withoutGreeting(): static
    {
        $this->greeting = false;

        return $this;
    }

    public function preheader(?string $text): static
    {
        $this->preheader = $text === null ? null : BrandedMail::line($text, 150);

        return $this;
    }

    public function headline(?string $text): static
    {
        $this->headline = $text === null ? null : BrandedMail::line($text, 200);

        return $this;
    }

    public function eyebrow(?string $text): static
    {
        $this->eyebrow = blank($text) ? null : BrandedMail::line($text, 120);

        return $this;
    }

    /** User-written text (comment, reply, note) in a quote panel, as plain text. */
    public function quote(?string $text, int $limit = 1000): static
    {
        $text = $text === null ? '' : trim(preg_replace("/[ \t]+/u", ' ', strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', $text))));
        $this->quote = $text === '' ? null : Str::limit(html_entity_decode($text, ENT_QUOTES | ENT_HTML5), $limit);

        return $this;
    }

    public function context(?string $name): static
    {
        $this->context = blank($name) ? null : BrandedMail::line($name, 80);

        return $this;
    }

    public function reason(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function footerLink(string $label, string $url): static
    {
        $this->footerLinks[] = ['label' => $label, 'url' => $url];

        return $this;
    }

    /**
     * A list of linked lines (the notification email digest): each one a small label + time, the linked
     * subject ("WEB-42 · Fix the login redirect"), what happened, its context and a short preview. Plain
     * text only — the `mail::entries` component escapes it; links must be app URLs.
     *
     * @param  list<array{label: string, time?: ?string, link: string, title: string, context?: ?string, body?: ?string, url: string}>  $entries
     */
    public function entries(array $entries): static
    {
        $this->entries = array_map(fn (array $e) => [
            'label' => BrandedMail::line((string) $e['label'], 40),
            'time' => isset($e['time']) ? BrandedMail::line((string) $e['time'], 20) : null,
            'link' => BrandedMail::line((string) $e['link'], 160),
            'title' => BrandedMail::line((string) $e['title'], 200),
            'context' => blank($e['context'] ?? null) ? null : BrandedMail::line((string) $e['context'], 120),
            'body' => blank($e['body'] ?? null) ? null : BrandedMail::line(strip_tags((string) $e['body']), 140),
            'url' => (string) $e['url'],
        ], array_values($entries));

        return $this;
    }

    /** "— Acme Support" instead of "— Brainitive". */
    public function signOff(string $name): static
    {
        return $this->salutation('— '.BrandedMail::line($name, 80));
    }

    public function data()
    {
        return array_merge(parent::data(), [
            'audience' => $this->audience,
            'preheader' => $this->preheader ?? BrandedMail::preheaderFrom([$this->headline, ...$this->introLines, $this->quote]),
            'headline' => $this->headline,
            'eyebrow' => $this->eyebrow,
            'quote' => $this->quote,
            'context' => $this->context,
            'reason' => $this->reason,
            'footerLinks' => $this->footerLinks,
            'entries' => $this->entries,
        ]);
    }
}
