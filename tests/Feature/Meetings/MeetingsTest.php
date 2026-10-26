<?php

namespace Tests\Feature\Meetings;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\CalendarConnection;
use App\Models\ContentReference;
use App\Models\Item;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Online meetings from issues and docs (BRAI-147): options, scheduling through the organiser's
 * Google calendar (Http::fake), the permission matrix, the rows, removal (unlink / cancel), the
 * notes doc, history, and the calendar's quick create with a Meet.
 */
class MeetingsTest extends TestCase
{
    use InteractsWithBoards, RefreshDatabase;

    private const CAL_LIST = 'www.googleapis.com/calendar/v3/users/me/calendarList/*';

    private const EVENTS = 'www.googleapis.com/calendar/v3/calendars/*';

    private CalendarConnection $calendar;

    private Item $issue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Http::preventStrayRequests();
        $this->calendar = CalendarConnection::factory()->create([
            'user_id' => $this->editor->id, 'account_email' => 'editor@work.test', 'external_calendar_id' => 'primary',
        ]);
        $this->issue = $this->item(['title' => 'Checkout redesign']);
    }

    /** @var list<string> what calendarList answers as allowedConferenceSolutionTypes */
    private array $allowedTypes = ['hangoutsMeet'];

    /** Google allows Meet and answers every create with a Meet link (echoing title + guests). */
    private function fakeGoogle(): void
    {
        Http::fake([
            self::CAL_LIST => fn () => Http::response(['conferenceProperties' => ['allowedConferenceSolutionTypes' => $this->allowedTypes]]),
            self::EVENTS => function (HttpRequest $r) {
                if ($r->method() === 'DELETE') {
                    return Http::response(null, 204);
                }

                return Http::response([
                    'id' => 'gev-'.substr(md5((string) $r['summary']), 0, 8),
                    'summary' => $r['summary'],
                    'htmlLink' => 'https://calendar.google.com/event?eid=1',
                    'start' => $r['start'], 'end' => $r['end'],
                    'attendees' => $r['attendees'] ?? [],
                ] + (isset($r['conferenceData']) ? [
                    'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
                    'conferenceData' => ['conferenceSolution' => ['key' => ['type' => 'hangoutsMeet']], 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc-defg-hij']]],
                ] : []));
            },
        ]);
    }

    private function url(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, ...$extra]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'subject_type' => 'item',
            'subject_id' => (string) $this->issue->id,
            'connection_id' => $this->calendar->id,
            'title' => 'Checkout redesign sync',
            'description' => 'About BRAI-1',
            'date' => '2026-11-05',
            'start_time' => '14:00',
            'duration' => 45,
            'timezone' => 'Europe/Berlin',
            'conference' => 'google_meet',
            'member_ids' => [$this->viewer->id],
            'emails' => ['client@partner.test', 'EDITOR@work.test'],
        ];
    }

    private function schedule(?User $as = null, array $overrides = []): Meeting
    {
        $this->fakeGoogle();
        $this->actingAs($as ?? $this->editor)->postJson($this->url('meetings.store'), $this->payload($overrides))->assertCreated();

        return Meeting::latest('id')->firstOrFail();
    }

    public function test_options_list_the_users_meeting_calendars_and_members_without_emails(): void
    {
        $this->fakeGoogle();
        CalendarConnection::factory()->create(['user_id' => $this->viewer->id]); // someone else's

        $response = $this->actingAs($this->editor)->getJson($this->url('meetings.options'))->assertOk();

        $response->assertJsonPath('calendars.0.id', $this->calendar->id)
            ->assertJsonPath('calendars.0.conference_providers', ['google_meet'])
            ->assertJsonCount(1, 'calendars')
            ->assertJsonPath('timezone', $this->editor->localTimezone())
            ->assertJsonPath('connect_url', route('calendar.index'))
            ->assertJsonPath('max_attendees', 50);
        $members = collect($response->json('members'));
        $this->assertTrue($members->pluck('id')->contains($this->viewer->id));
        $this->assertFalse($members->pluck('id')->contains($this->editor->id), 'the organiser is not offered');
        $this->assertFalse($members->pluck('id')->contains($this->outsider->id));
        $this->assertStringNotContainsString('@', json_encode($response->json('members')));

        $this->actingAs($this->outsider)->getJson($this->url('meetings.options'))->assertForbidden();
    }

    public function test_an_editor_schedules_a_google_meet_on_an_issue(): void
    {
        $this->fakeGoogle();

        $response = $this->actingAs($this->editor)->postJson($this->url('meetings.store'), $this->payload())->assertCreated();

        $response->assertJsonPath('meeting.join_url', 'https://meet.google.com/abc-defg-hij')
            ->assertJsonPath('meeting.conference_label', 'Google Meet')
            ->assertJsonPath('meeting.starts_at', '2026-11-05T13:00:00+00:00')
            ->assertJsonPath('meeting.can_cancel', true)
            ->assertJsonPath('warning', null);

        $meeting = Meeting::sole();
        $this->assertSame([Item::class, $this->issue->id, $this->project->id], [$meeting->subject_type, $meeting->subject_id, $meeting->project_id]);
        $this->assertSame(['google', 'google_meet', 'Europe/Berlin'], [$meeting->provider, $meeting->conference_provider, $meeting->timezone]);
        $this->assertSame('2026-11-05 13:45:00', $meeting->ends_at->utc()->format('Y-m-d H:i:s'));
        // member by id → their email; typed guest; the organiser's own address is dropped
        $this->assertSame([
            ['email' => $this->viewer->email, 'name' => $this->viewer->name, 'user_id' => $this->viewer->id],
            ['email' => 'client@partner.test', 'name' => null, 'user_id' => null],
        ], $meeting->attendees);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_contains($r->url(), 'conferenceDataVersion=1') && str_contains($r->url(), 'sendUpdates=all')
            && $r['summary'] === 'Checkout redesign sync' && $r['description'] === 'About BRAI-1'
            && $r['start'] === ['dateTime' => '2026-11-05T14:00:00+01:00', 'timeZone' => 'Europe/Berlin']
            && $r['end'] === ['dateTime' => '2026-11-05T14:45:00+01:00', 'timeZone' => 'Europe/Berlin']
            && array_column($r['attendees'], 'email') === [$this->viewer->email, 'client@partner.test']);

        $log = ActivityLog::where('loggable_type', Item::class)->where('loggable_id', $this->issue->id)->where('action', 'meeting_scheduled')->sole();
        $this->assertSame(['title' => 'Checkout redesign sync', 'conference' => 'Google Meet', 'when' => 'Thu, Nov 5 · 14:00 CET'], $log->metadata);
        $this->assertSame($this->editor->id, $log->user_id);
    }

    public function test_a_meeting_without_a_video_call_and_on_a_doc(): void
    {
        $this->fakeGoogle();
        $doc = Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Launch plan']);

        $this->actingAs($this->editor)->postJson($this->url('meetings.store'), $this->payload([
            'subject_type' => 'page', 'subject_id' => $doc->uuid, 'conference' => 'none', 'member_ids' => [], 'emails' => [],
        ]))->assertCreated()->assertJsonPath('meeting.join_url', null)->assertJsonPath('meeting.conference_label', null);

        $meeting = Meeting::sole();
        $this->assertSame([Page::class, $doc->id, null], [$meeting->subject_type, $meeting->subject_id, $meeting->conference_provider]);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && ! isset($r['conferenceData']) && ! isset($r['attendees']));
        $this->assertTrue(ActivityLog::where('loggable_type', Page::class)->where('loggable_id', $doc->id)->where('action', 'meeting_scheduled')->exists());

        $this->actingAs($this->viewer)->getJson($this->url('meetings.index').'?subject_type=page&subject_id='.$doc->uuid)
            ->assertOk()->assertJsonPath('meetings.0.title', 'Checkout redesign sync')->assertJsonPath('can_schedule', false);
    }

    public function test_the_permission_matrix_for_scheduling(): void
    {
        $this->fakeGoogle();
        $viewerCalendar = CalendarConnection::factory()->create(['user_id' => $this->viewer->id]);
        $outsiderCalendar = CalendarConnection::factory()->create(['user_id' => $this->outsider->id]);

        // viewers can't change the issue; outsiders can't see it
        $this->actingAs($this->viewer)->postJson($this->url('meetings.store'), $this->payload(['connection_id' => $viewerCalendar->id]))->assertForbidden();
        $this->actingAs($this->outsider)->postJson($this->url('meetings.store'), $this->payload(['connection_id' => $outsiderCalendar->id]))->assertForbidden();
        // outsiders can't probe membership through validation: refused before it runs
        $this->actingAs($this->outsider)->postJson($this->url('meetings.store'), $this->payload(['member_ids' => [$this->outsider->id]]))->assertForbidden();
        // only your own calendar
        $this->actingAs($this->editor)->postJson($this->url('meetings.store'), $this->payload(['connection_id' => $viewerCalendar->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('connection_id');
        // an issue of another project is not found here
        $other = Item::factory()->create(['created_by' => $this->editor->id]);
        $this->actingAs($this->editor)->postJson($this->url('meetings.store'), $this->payload(['subject_id' => (string) $other->id]))->assertNotFound();
        // switched-off calendar → policy; read-only calendar → provider layer
        $this->calendar->update(['is_active' => false]);
        $this->actingAs($this->editor)->postJson($this->url('meetings.store'), $this->payload())->assertForbidden();
        $this->calendar->update(['is_active' => true, 'credentials' => ['read_only' => true]]);
        $this->actingAs($this->editor)->postJson($this->url('meetings.store'), $this->payload())
            ->assertUnprocessable()->assertJsonPath('errors.calendar.0', 'This calendar is read-only.');

        $this->assertSame(0, Meeting::count());
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
    }

    public function test_conference_kind_and_attendees_are_validated(): void
    {
        $this->fakeGoogle();
        $as = $this->actingAs($this->editor);

        $as->postJson($this->url('meetings.store'), $this->payload(['conference' => 'teams']))
            ->assertUnprocessable()->assertJsonValidationErrors('conference');
        $as->postJson($this->url('meetings.store'), $this->payload(['emails' => ['not an email']]))->assertJsonValidationErrors('emails.0');
        $as->postJson($this->url('meetings.store'), $this->payload(['member_ids' => [$this->outsider->id]]))->assertJsonValidationErrors('member_ids.0');
        $as->postJson($this->url('meetings.store'), $this->payload(['emails' => array_map(fn ($i) => "g{$i}@example.com", range(1, 51))]))
            ->assertJsonValidationErrors('emails');
        // 50 typed + 1 member = 51 after merging
        $as->postJson($this->url('meetings.store'), $this->payload(['emails' => array_map(fn ($i) => "g{$i}@example.com", range(1, 50))]))
            ->assertUnprocessable()->assertJsonPath('errors.emails.0', 'Invite at most 50 people.');
        $as->postJson($this->url('meetings.store'), $this->payload(['duration' => 2]))->assertJsonValidationErrors('duration');
        $as->postJson($this->url('meetings.store'), $this->payload(['timezone' => 'Mars/Base']))->assertJsonValidationErrors('timezone');

        $this->assertSame(0, Meeting::count());

        // A calendar where the Workspace admin switched Meet off.
        $this->allowedTypes = [];
        $other = CalendarConnection::factory()->create(['user_id' => $this->editor->id]);
        $as->postJson($this->url('meetings.store'), $this->payload(['connection_id' => $other->id]))
            ->assertUnprocessable()->assertJsonPath('errors.conference.0', 'Google Meet isn’t available for this calendar. Pick “No video call” or another calendar.');
    }

    public function test_the_list_shows_meetings_to_viewers_without_guest_addresses(): void
    {
        $meeting = $this->schedule();

        $this->actingAs($this->viewer)->getJson($this->url('meetings.index').'?subject_type=item&subject_id='.$this->issue->id)
            ->assertOk()
            ->assertJsonPath('meetings.0.id', $meeting->id)
            ->assertJsonPath('meetings.0.join_url', 'https://meet.google.com/abc-defg-hij')
            ->assertJsonPath('meetings.0.event_url', 'https://calendar.google.com/event?eid=1')
            ->assertJsonPath('meetings.0.attendees', [['name' => $this->viewer->name, 'email' => null], ['name' => 'Guest', 'email' => null]])
            ->assertJsonPath('meetings.0.can_cancel', false)
            ->assertJsonPath('meetings.0.can_remove', false)
            ->assertJsonPath('can_schedule', false);

        $this->actingAs($this->editor)->getJson($this->url('meetings.index').'?subject_type=item&subject_id='.$this->issue->id)
            ->assertJsonPath('meetings.0.attendees.1', ['name' => null, 'email' => 'client@partner.test'])
            ->assertJsonPath('meetings.0.can_cancel', true);

        $this->actingAs($this->outsider)->getJson($this->url('meetings.index').'?subject_type=item&subject_id='.$this->issue->id)->assertForbidden();
    }

    public function test_remove_unlinks_or_cancels_the_calendar_event(): void
    {
        $meeting = $this->schedule();
        $otherEditor = $this->projectMember('editor');

        // viewers can do neither; another editor can unlink but not cancel (not their calendar)
        $this->actingAs($this->viewer)->deleteJson($this->url('meetings.destroy', [$meeting->id]))->assertForbidden();
        $this->actingAs($otherEditor)->deleteJson($this->url('meetings.destroy', [$meeting->id]), ['cancel_event' => true])->assertForbidden();
        $this->actingAs($otherEditor)->deleteJson($this->url('meetings.destroy', [$meeting->id]))->assertOk()->assertJsonPath('cancelled', false);
        $this->assertModelMissing($meeting);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE');
        $this->assertTrue(ActivityLog::where('action', 'meeting_removed')->where('user_id', $otherEditor->id)->exists());

        // the organiser cancels: the event is deleted in Google (guests are told) and unlinked
        $second = $this->schedule(null, ['title' => 'Second']);
        $this->actingAs($this->editor)->deleteJson($this->url('meetings.destroy', [$second->id]), ['cancel_event' => true])
            ->assertOk()->assertJsonPath('cancelled', true);
        $this->assertModelMissing($second);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/'.$second->external_event_id.'?sendUpdates=all'));
        $this->assertSame(
            ['title' => 'Second', 'conference' => 'Google Meet', 'when' => 'Thu, Nov 5 · 14:00 CET'],
            ActivityLog::where('action', 'meeting_cancelled')->sole()->metadata,
        );

        // a meeting of another project isn't reachable through this one
        $this->actingAs($this->editor)->deleteJson(route('meetings.destroy', [$this->workspace->slug, $this->project->slug, 999999]))->assertNotFound();
    }

    public function test_meeting_notes_are_created_once_published_and_linked(): void
    {
        $doc = Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Launch plan']);
        $meeting = $this->schedule(null, ['subject_type' => 'page', 'subject_id' => $doc->uuid]);

        // viewers can't create docs
        $this->actingAs($this->viewer)->postJson($this->url('meetings.notes', [$meeting->id]))->assertForbidden();

        $first = $this->actingAs($this->editor)->postJson($this->url('meetings.notes', [$meeting->id]))->assertCreated()->assertJsonPath('created', true);
        $notes = Page::where('id', $meeting->fresh()->meeting_notes_page_id)->sole();
        $this->assertSame('Notes — Checkout redesign sync Nov 5, 2026', $notes->name);
        $this->assertSame(['published', $doc->id, $this->editor->id], [$notes->status, $notes->parent_id, $notes->created_by]);
        $html = $notes->content['html'];
        $this->assertStringContainsString('href="https://meet.google.com/abc-defg-hij"', $html);
        $this->assertStringContainsString(route('pages.show', [$this->workspace->slug, $this->project->slug, $doc->uuid]), $html);
        $this->assertStringContainsString('<h2>Action items</h2>', $html);
        // members by name, guests only counted: the doc is readable by the whole project
        $this->assertStringContainsString(e($this->viewer->name).', +1 guest', $html);
        $this->assertStringNotContainsString('client@partner.test', $html);
        $this->assertTrue(ContentReference::where('source_type', 'page')->where('source_id', $doc->id)->where('target_id', $notes->id)->exists());
        $first->assertJsonPath('url', route('pages.show', [$this->workspace->slug, $this->project->slug, $notes->uuid]));

        // again (and for a viewer): the same doc, nothing new
        $this->actingAs($this->editor)->postJson($this->url('meetings.notes', [$meeting->id]))->assertOk()->assertJsonPath('created', false);
        $this->actingAs($this->viewer)->postJson($this->url('meetings.notes', [$meeting->id]))->assertOk()->assertJsonPath('url', $first->json('url'));
        $this->assertSame(1, Page::where('name', 'like', 'Notes — %')->count());

        // unpublished by its author: others get a 403, never a second doc
        $notes->update(['status' => 'draft']);
        $this->actingAs($this->viewer)->postJson($this->url('meetings.notes', [$meeting->id]))->assertForbidden();
        $this->assertSame($notes->id, $meeting->fresh()->meeting_notes_page_id);
        $notes->update(['status' => 'published']);
        $this->actingAs($this->viewer)->getJson($this->url('meetings.index').'?subject_type=page&subject_id='.$doc->uuid)
            ->assertJsonPath('meetings.0.notes_page.uuid', $notes->uuid);
    }

    public function test_meetings_follow_an_issue_moved_to_another_project(): void
    {
        $meeting = $this->schedule();
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id]);
        $otherProject->users()->attach($this->editor->id, ['role' => 'admin']);
        $otherBoard = Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $this->editor->id]);
        $this->issue->forceFill(['board_id' => $otherBoard->id, 'project_id' => $otherProject->id])->saveQuietly();

        // no longer reachable through the old project …
        $this->actingAs($this->editor)->postJson($this->url('meetings.notes', [$meeting->id]))->assertNotFound();
        $this->actingAs($this->editor)->deleteJson($this->url('meetings.destroy', [$meeting->id]))->assertNotFound();
        // … but listed (and reachable) in the new one
        $this->actingAs($this->editor)->getJson(route('meetings.index', [$this->workspace->slug, $otherProject->slug]).'?subject_type=item&subject_id='.$this->issue->id)
            ->assertOk()->assertJsonPath('meetings.0.id', $meeting->id);
        $this->actingAs($this->editor)->deleteJson(route('meetings.destroy', [$this->workspace->slug, $otherProject->slug, $meeting->id]))->assertOk();
    }

    public function test_quick_create_adds_a_meet_and_guests_on_the_calendar_page(): void
    {
        $this->fakeGoogle();

        $this->actingAs($this->editor)->post(route('calendar.connections.events.create', $this->calendar), [
            'title' => 'Coffee', 'date' => '2026-11-06', 'time' => '10:00', 'timezone' => 'Europe/Berlin',
            'conference' => 'google_meet', 'attendees' => ['friend@example.com'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && isset($r['conferenceData']) && $r['attendees'] === [['email' => 'friend@example.com']]);

        $this->actingAs($this->editor)->from(route('calendar.index'))->post(route('calendar.connections.events.create', $this->calendar), [
            'title' => 'Coffee', 'date' => '2026-11-06', 'time' => '10:00', 'conference' => 'teams',
        ])->assertSessionHasErrors(['calendar' => 'Teams isn’t available for this account.']);
        $this->actingAs($this->editor)->post(route('calendar.connections.events.create', $this->calendar), [
            'title' => 'Coffee', 'date' => '2026-11-06', 'attendees' => ['nope'],
        ])->assertSessionHasErrors('attendees.0');

        $this->actingAs($this->editor)->getJson(route('calendar.meetings.calendars'))
            ->assertOk()->assertJsonPath('calendars.0.conference_providers', ['google_meet']);
    }

    public function test_the_calendar_page_passes_the_join_link_of_events(): void
    {
        Http::fake([self::EVENTS => Http::response(['items' => [[
            'id' => 'm1', 'summary' => 'Standup', 'hangoutLink' => 'https://meet.google.com/std-up',
            'start' => ['dateTime' => now()->setTime(9, 0)->toRfc3339String()], 'end' => ['dateTime' => now()->setTime(9, 15)->toRfc3339String()],
        ], [
            'id' => 'm2', 'summary' => 'No call',
            'start' => ['dateTime' => now()->setTime(11, 0)->toRfc3339String()], 'end' => ['dateTime' => now()->setTime(11, 15)->toRfc3339String()],
        ]]])]);

        $this->actingAs($this->editor)->get(route('calendar.index', ['view' => 'day', 'date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)->firstWhere('id', 'm1')['conference_url'] === 'https://meet.google.com/std-up'
                    && collect($events)->firstWhere('id', 'm1')['conference_provider'] === 'google_meet'
                    && collect($events)->firstWhere('id', 'm2')['conference_url'] === null));
    }
}
