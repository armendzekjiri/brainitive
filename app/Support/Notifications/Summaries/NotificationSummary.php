<?php

namespace App\Support\Notifications\Summaries;

/**
 * The bell's sentence for one notification type, server side (App API `summary` / `url`): the same wording as
 * `resources/js/Components/NotificationMessage.tsx` (`describeNotification(...).text`). One subclass per type
 * the bell words itself; `ContractSummary` for every catalog type (the server stored the sentence as `title`)
 * and any type nobody knows. `NotificationText` picks the class, makes the sentence plain text and checks the link.
 */
abstract class NotificationSummary
{
    /**
     * The sentence (may still hold Markdown / HTML from the payload; NotificationText cleans it).
     *
     * @param  array<string, mixed>  $data  the stored payload
     */
    abstract public function text(array $data): string;

    /**
     * The payload's link to open (relative or absolute, unchecked), if the type has one.
     *
     * @param  array<string, mixed>  $data
     */
    public function link(array $data): ?string
    {
        return self::value($data, 'url');
    }

    /** A string value as stored ('' stays ''), like `d.key ?? …` in the bell. */
    protected static function value(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /** A non-blank string, like the bell's `str()`. */
    protected static function str(array $data, string $key): ?string
    {
        $value = self::value($data, $key);

        return $value !== null && trim($value) !== '' ? $value : null;
    }

    /** Who did it: a user, or the customer for helpdesk tickets (the bell's `notificationActor`). */
    protected static function actor(array $data): ?string
    {
        return self::str($data, 'actor_name') ?? self::str($data, 'contact_name') ?? self::str($data, 'contact_email');
    }

    protected static function who(array $data): string
    {
        return self::actor($data) ?? 'Someone';
    }
}
