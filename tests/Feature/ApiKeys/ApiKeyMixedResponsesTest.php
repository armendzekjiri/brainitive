<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiKey;
use App\Models\Board;
use App\Models\CalendarConnection;
use App\Models\Capture;
use App\Models\ContentReference;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\ApiKeys\ApiKeyAreas;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\NormalizedEvent;
use App\Support\Captures\CaptureConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\ApiKeys\Concerns\UsesApiKeys;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Calendar\FakeCalendarProvider;
use Tests\TestCase;

/**
 * Developer API (v1.52.0, Codex security review): responses that combine data of several areas or resolve
 * references must follow the key, not only the user — smart links (`links/resolve`), Today and the calendar agenda
 * (connected calendars + bookings need Calendar & bookings read and aren't even fetched without it), workspace
 * insights and project health (every area they read).
 */
class ApiKeyMixedResponsesTest extends TestCase
{
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesApiKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        CalendarProviders::fake('fake', FakeCalendarProvider::class);
        FakeCalendarProvider::reset();
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00', 'UTC'));
        $this->editor->update(['timezone' => 'Europe/Berlin']);
        CalendarConnection::factory()->provider('fake')->create(['user_id' => $this->editor->id, 'external_calendar_name' => 'Work']);
        FakeCalendarProvider::$events = [
            NormalizedEvent::make(['id' => 'standup', 'title' => 'Board meeting with Acme', 'start_date' => '2026-11-10', 'start_time' => '09:00', 'end_date' => '2026-11-10', 'end_time' => '09:15', 'timezone' => 'Europe/Berlin']),
        ];
    }

    protected function tearDown(): void
    {
        CalendarProviders::fake('fake', null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Smart links ─────────────────────────────────────────────────────────────────────────

    public function test_smart_links_follow_the_keys_scope_and_areas(): void
    {
        $item = $this->item(['title' => 'Login bug']);
        $doc = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'status' => 'published', 'published_at' => now(), 'name' => 'Guide']);
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Website']);
        $other->users()->attach($this->editor->id, ['role' => 'editor']);
        $otherDoc = Page::factory()->create(['project_id' => $other->id, 'created_by' => $this->editor->id, 'status' => 'published', 'published_at' => now(), 'name' => 'Secret roadmap']);
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id]);
        $otherItem = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $this->editor->id, 'title' => 'Other board issue']);

        $query = fn () => http_build_query(['workspace' => $this->workspace->slug, 'refs' => ["item:{$item->id}", "page:{$doc->uuid}", "page:{$otherDoc->uuid}", "item:{$otherItem->id}"]]);

        // docs read, no issues: the issue is "not found" like a ref that doesn't exist (no title, no "restricted")
        $docsOnly = $this->apiKey($this->editor, ['docs' => 'read'], ApiKey::SCOPE_PROJECTS, [$this->project->id]);
        $data = $this->getJson('/api/v1/links/resolve?'.$query(), $this->keyHeaders($docsOnly))->assertOk()->json('data');
        $this->assertSame(['not_found', 'ok', 'not_found', 'not_found'], array_column($data, 'state'));
        $this->assertSame([null, 'Guide', null, null], array_column($data, 'title'));
        $this->assertStringNotContainsString('Login bug', json_encode($data));
        $this->assertStringNotContainsString('Secret roadmap', json_encode($data));

        // a board key with issues + docs: only its board's issues; docs of the board's project
        $boardKey = $this->apiKey($this->editor, ['issues' => 'read', 'docs' => 'read'], ApiKey::SCOPE_BOARDS, [$this->board->id]);
        $data = $this->getJson('/api/v1/links/resolve?'.$query(), $this->keyHeaders($boardKey))->assertOk()->json('data');
        $this->assertSame(['ok', 'ok', 'not_found', 'not_found'], array_column($data, 'state'));
    }

    public function test_related_content_follows_the_keys_areas(): void
    {
        $doc = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'status' => 'published', 'published_at' => now(), 'name' => 'Guide']);
        $item = $this->item(['title' => 'Hidden issue']);
        ContentReference::create(['project_id' => $this->project->id, 'source_type' => 'page', 'source_id' => $doc->id, 'target_type' => 'item', 'target_id' => $item->id, 'origin' => ContentReference::ORIGIN_MANUAL, 'created_by' => $this->editor->id]);

        $docsOnly = $this->apiKey($this->editor, ['docs' => 'read']);
        $response = $this->getJson("/api/v1/pages/{$doc->uuid}/links", $this->keyHeaders($docsOnly))->assertOk();
        $this->assertSame([], $response->json('data.related'));
        $this->assertStringNotContainsString('Hidden issue', $response->getContent());

        $both = $this->apiKey($this->editor, ['docs' => 'read', 'issues' => 'read']);
        $this->getJson("/api/v1/pages/{$doc->uuid}/links", $this->keyHeaders($both))->assertOk()
            ->assertJsonPath('data.related.0.title', 'Hidden issue');
    }

    public function test_a_capture_target_outside_the_scope_shows_no_snapshot(): void
    {
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id]);
        $item = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $this->editor->id, 'title' => 'Out of scope target']);
        $capture = Capture::factory()->create(['user_id' => $this->editor->id, 'text' => 'note', 'target_type' => 'issue', 'target_id' => $item->id,
            'processed_at' => now(), 'target_location' => ['type' => 'issue', 'title' => 'Out of scope target', 'breadcrumb' => 'Snapshot crumb']]);

        // reads issues + work, but only the main board
        $key = $this->apiKey($this->editor, ['issues' => 'read', 'work' => 'read'], ApiKey::SCOPE_BOARDS, [$this->board->id]);
        $response = $this->getJson("/api/v1/captures/{$capture->uuid}", $this->keyHeaders($key))->assertOk()
            ->assertJsonPath('data.target.title', '')->assertJsonPath('data.target.breadcrumb', '')->assertJsonPath('data.target.available', false);
        $this->assertStringNotContainsString('Out of scope target', $response->getContent());
        $this->assertStringNotContainsString('Snapshot crumb', $response->getContent());

        // the apps (no key) still get the snapshot
        $this->assertSame('Out of scope target', CaptureConversion::location($capture->fresh(), $this->outsider)['title']);
    }

    public function test_a_time_only_key_deleting_a_work_log_still_moves_the_issue_total(): void
    {
        $item = $this->item(['title' => 'Logged on']);
        $entry = TimeEntry::create(['workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'item_id' => $item->id, 'user_id' => $this->editor->id,
            'spent_on' => now()->toDateString(), 'minutes' => 45, 'source' => 'manual', 'billable' => false]);
        $this->assertSame(45, (int) $item->fresh()->time_spent);

        $key = $this->apiKey($this->editor, ['time' => 'write'], canDelete: true);
        $this->deleteJson("/api/v1/work-logs/{$entry->id}", [], $this->keyHeaders($key))->assertNoContent();
        $this->assertSame(0, (int) $item->fresh()->time_spent);
    }

    // ── Today + agenda ──────────────────────────────────────────────────────────────────────

    public function test_today_has_no_calendar_without_calendar_read_and_doesnt_fetch_it(): void
    {
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-10', 'title' => 'Due today']);

        $work = $this->apiKey($this->editor, ['work' => 'read', 'issues' => 'read']);
        $response = $this->getJson('/api/v1/me/today', $this->keyHeaders($work))->assertOk();
        $this->assertSame([], $response->json('data.events'));
        $this->assertSame(['Due today'], array_column($response->json('data.work'), 'title'));
        $this->assertSame([], FakeCalendarProvider::$calls, 'the connected calendar must not even be read');

        $withCalendar = $this->apiKey($this->editor, ['work' => 'read', 'calendar' => 'read']);
        $this->getJson('/api/v1/me/today', $this->keyHeaders($withCalendar))->assertOk()
            ->assertJsonPath('data.events.0.title', 'Board meeting with Acme');
    }

    public function test_the_agenda_needs_calendar_read_for_events_and_each_area_for_its_own_dates(): void
    {
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-10', 'title' => 'Due today']);
        $range = '/api/v1/calendar/events?from=2026-11-10&to=2026-11-10';

        // calendar only: the external event, not the issue
        $calendar = $this->apiKey($this->editor, ['calendar' => 'read']);
        $titles = array_column($this->getJson($range, $this->keyHeaders($calendar))->assertOk()->json('data'), 'title');
        $this->assertContains('Board meeting with Acme', $titles);
        $this->assertNotContains('Due today', $titles);

        // the route needs calendar read at all
        $this->getJson($range, $this->keyHeaders($this->apiKey($this->editor, ['issues' => 'read'])))
            ->assertForbidden()->assertJsonPath('code', 'key_ability_denied');
    }

    // ── Insights + health ───────────────────────────────────────────────────────────────────

    public function test_workspace_insights_and_project_health_need_every_area_they_read(): void
    {
        $owner = $this->workspace->owner;
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/insights/team", $this->keyHeaders($this->apiKey($owner, ['issues' => 'read'])))
            ->assertForbidden()->assertJsonPath('code', 'key_ability_denied');
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/insights/overview", $this->keyHeaders($this->apiKey($owner, array_fill_keys(ApiKeyAreas::keys(), 'read'))))
            ->assertOk();

        $this->getJson("/api/v1/projects/{$this->project->id}/health", $this->keyHeaders($this->apiKey($this->editor, ['issues' => 'read'])))
            ->assertForbidden()->assertJsonPath('code', 'key_ability_denied');
        $this->getJson("/api/v1/projects/{$this->project->id}/health", $this->keyHeaders($this->apiKey($this->editor, ['issues' => 'read', 'plans' => 'read'])))
            ->assertOk();
    }
}
