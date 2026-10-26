<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\BaseCalendarProvider;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\EventInput;
use App\Support\Calendar\NormalizedEvent;
use App\Support\Calendar\PendingConnection;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Test double for the calendar provider contract: a 'form' provider (server + username +
 * app password, like CalDAV) whose behaviour the test switches with the static flags.
 * Register with CalendarProviders::fake('fake', FakeCalendarProvider::class); call reset().
 */
class FakeCalendarProvider extends BaseCalendarProvider
{
    public static bool $writable = true;

    /** Message of a CalendarSyncFailed to throw from listEvents, or 'crash' for a RuntimeException. */
    public static ?string $fail = null;

    /** connectWithForm returns a PendingConnection (picker) instead of creating rows. */
    public static bool $pick = false;

    /** @var list<array<string, mixed>> normalized events listEvents returns */
    public static array $events = [];

    /** @var list<array{0: string, 1: mixed}> calls to write methods / listEvents */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$writable = true;
        self::$fail = null;
        self::$pick = false;
        self::$events = [];
        self::$calls = [];
    }

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake DAV';
    }

    public function connectMode(): string
    {
        return 'form';
    }

    public function formFields(): array
    {
        return [
            ['name' => 'server_url', 'label' => 'Server URL', 'type' => 'url', 'help' => 'The CalDAV address.', 'placeholder' => 'https://dav.example.com'],
            ['name' => 'username', 'label' => 'Username', 'type' => 'text'],
            ['name' => 'password', 'label' => 'App password', 'type' => 'password'],
        ];
    }

    public function capabilities(): array
    {
        return ['write' => self::$writable, 'tasks' => false];
    }

    public function connectWithForm(User $user, array $input): array|PendingConnection
    {
        if ($input['password'] === 'wrong') {
            throw ValidationException::withMessages(['password' => 'The server refused this username or password.']);
        }
        if ($input['password'] === 'down') {
            throw new CalendarSyncFailed('The server did not answer.');
        }

        $calendars = [
            ['id' => '/cal/home/', 'name' => 'Home', 'color' => '#FF0000', 'primary' => true, 'read_only' => false],
            ['id' => '/cal/work/', 'name' => 'Work', 'color' => null, 'primary' => false, 'read_only' => false],
        ];
        $credentials = ['username' => $input['username'], 'password' => $input['password']];

        if (self::$pick) {
            return new PendingConnection('fake', $input['username'], null, $calendars, [], ['credentials' => $credentials], $input['server_url']);
        }

        return [CalendarConnection::upsertFor($user->id, 'fake', 'calendar', $input['username'], '/cal/home/', [
            'external_calendar_name' => 'Home',
            'server_url' => $input['server_url'],
            'credentials' => $credentials,
            'is_active' => true,
        ])];
    }

    public function listEvents(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end, ?string $timezone = null): array
    {
        self::$calls[] = ['list', $timezone];
        if (self::$fail === 'crash') {
            throw new \RuntimeException('socket closed: secret internals');
        }
        if (self::$fail !== null) {
            throw new CalendarSyncFailed(self::$fail);
        }

        return self::$events;
    }

    public function getEvent(CalendarConnection $connection, string $eventId, ?string $timezone = null): ?array
    {
        foreach (self::$events as $event) {
            if ($event['id'] === $eventId) {
                return $event;
            }
        }

        return null;
    }

    public function createEvent(CalendarConnection $connection, array $data): array
    {
        $this->assertWritable($connection);
        self::$calls[] = ['create', $data];
        $input = EventInput::from($data);

        return $input->isAllDay()
            ? NormalizedEvent::allDay('new-1', $data['title'], $input->startDate(), $input->endDate())
            : NormalizedEvent::timed('new-1', $data['title'], $input->start(), $input->end(), $input->timezone());
    }

    public function updateEvent(CalendarConnection $connection, string $eventId, array $data): array
    {
        $this->assertWritable($connection);
        self::$calls[] = ['update', ['id' => $eventId] + $data];

        return NormalizedEvent::allDay($eventId, $data['title'] ?? 'Updated', $data['start_date'] ?? '2026-11-05');
    }

    public function deleteEvent(CalendarConnection $connection, string $eventId): void
    {
        $this->assertWritable($connection);
        self::$calls[] = ['delete', $eventId];
    }
}
