<?php

namespace App\Support\Calendar;

use App\Models\CalendarConnection;
use App\Models\User;

/**
 * Defaults for CalendarProvider: available, no OAuth route, no form, no picker, and write
 * methods that throw CalendarReadOnly. A read-only provider (an ICS link) only implements
 * key/label/badge/connectMode/formFields/connectWithForm/listEvents/getEvent; a writable one
 * returns ['write' => true] from capabilities() and overrides create/update/deleteEvent.
 */
abstract class BaseCalendarProvider implements CalendarProvider
{
    public function badge(): string
    {
        return mb_strtoupper(mb_substr($this->label(), 0, 1));
    }

    public function defaultColor(): string
    {
        return '#0E83DC';
    }

    public function available(): bool
    {
        return true;
    }

    public function redirectRoute(): ?string
    {
        return null;
    }

    public function formFields(): array
    {
        return [];
    }

    public function capabilities(): array
    {
        return ['write' => false, 'tasks' => false];
    }

    /**
     * The provider writes, and this calendar isn't marked read-only: the picker stores
     * `credentials.read_only = true` for calendars whose `read_only` was true in listCalendars().
     */
    public function isWritable(CalendarConnection $connection): bool
    {
        return $this->capabilities()['write'] && empty(((array) $connection->credentials)['read_only']);
    }

    public function connectWithForm(User $user, array $input): array|PendingConnection
    {
        throw new CalendarSyncFailed($this->label().' is connected through its sign-in page.');
    }

    public function listCalendars(CalendarConnection $connection): array
    {
        return [];
    }

    public function createEvent(CalendarConnection $connection, array $data): array
    {
        throw new CalendarReadOnly;
    }

    public function updateEvent(CalendarConnection $connection, string $eventId, array $data): array
    {
        throw new CalendarReadOnly;
    }

    public function deleteEvent(CalendarConnection $connection, string $eventId): void
    {
        throw new CalendarReadOnly;
    }

    /** Throw CalendarReadOnly unless this connection accepts writes. Call first in write methods. */
    protected function assertWritable(CalendarConnection $connection): void
    {
        if (! $this->isWritable($connection)) {
            throw new CalendarReadOnly;
        }
    }
}
