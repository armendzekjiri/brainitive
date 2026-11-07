<?php

namespace Tests\Feature\ApiKeys;

use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\Capture;
use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Release;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\ApiKeys\ApiKeyRoutes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\ApiKeys\Concerns\UsesApiKeys;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Developer API (v1.52.0, response audit): a key with ONE area never sees content of another area in ANY answer of
 * any GET route it may call — issues, docs, plans / releases, snippets, bookings, work logs, to-dos, captures are
 * linked to each other (an issue in a release, a work log and a My Day entry on it, a booking's card, a converted
 * capture) and each has a marker that must only appear for a key reading its area. No route may fail with 500.
 * Cross-area writes (convert a capture into an issue, meeting notes as a doc) need write on the target area.
 */
class ApiKeySingleAreaSweepTest extends TestCase
{
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesApiKeys;

    /** area => markers that only a key reading it may see */
    private const MARKERS = [
        'issues' => ['ISSUE-MARK-title'],
        'docs' => ['DOC-MARK-name', 'DOC-MARK-body'],
        'plans' => ['PLAN-MARK-name', 'RELEASE-MARK-name'],
        'library' => ['LIB-MARK-collection'],
        'calendar' => ['GUEST-MARK-name', 'BOOKPAGE-MARK-title'],
        'time' => ['TIME-MARK-note'],
        'work' => ['TASK-MARK-title', 'CAPTURE-MARK-text'],
    ];

    private User $owner;

    /** @var array<string, string> route parameter → value */
    private array $params = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        // hundreds of calls per key: no rate limit in this sweep (a 429 would hide a leak)
        config(['api-keys.rate_limits.read' => 100000, 'api-keys.rate_limits.write' => 100000]);
        $this->owner = User::findOrFail($this->workspace->owner_id);
        $this->project->users()->attach($this->owner->id, ['role' => 'admin']);
        $today = now()->toDateString();

        $release = Release::factory()->create(['project_id' => $this->project->id, 'name' => 'RELEASE-MARK-name']);
        $item = $this->item(['title' => 'ISSUE-MARK-title', 'assigned_to' => $this->owner->id, 'due_date' => $today, 'release_id' => $release->id]);
        $page = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'DOC-MARK-name',
            'status' => 'published', 'published_at' => now(), 'content' => ['type' => 'lexical', 'html' => '<p>DOC-MARK-body</p>']]);
        $plan = Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'PLAN-MARK-name']);
        $collection = SnippetCollection::create(['workspace_id' => $this->workspace->id, 'name' => 'LIB-MARK-collection', 'created_by' => $this->owner->id]);
        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        $task = TaskListItem::factory()->create(['task_list_id' => $list->id, 'title' => 'TASK-MARK-title', 'assignee_id' => $this->owner->id, 'due_date' => $today]);
        $capture = Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'CAPTURE-MARK-text', 'target_type' => 'issue', 'target_id' => $item->id, 'processed_at' => now()]);
        MyDayEntry::create(['user_id' => $this->owner->id, 'work_type' => 'board', 'work_id' => $item->id, 'plan_date' => $today, 'position' => 1]);
        $log = TimeEntry::query()->insertGetId(['workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'item_id' => $item->id, 'user_id' => $this->owner->id,
            'spent_on' => $today, 'minutes' => 30, 'note' => 'TIME-MARK-note', 'item_key' => $item->key, 'item_title' => 'ISSUE-MARK-title', 'source' => 'manual', 'billable' => false,
            'created_at' => now(), 'updated_at' => now()]);
        $bookingPage = BookingPage::create(['user_id' => $this->owner->id, 'workspace_id' => $this->workspace->id, 'public_slug' => 'mark', 'title' => 'BOOKPAGE-MARK-title', 'timezone' => 'UTC', 'settings' => ['consent' => 'off']]);
        $type = $bookingPage->types()->create(['slug' => 'intro', 'name' => 'Intro', 'duration_minutes' => 30, 'availability' => ['weekly' => [], 'overrides' => []], 'location' => ['kind' => 'none', 'value' => null], 'questions' => []]);
        $start = CarbonImmutable::now('UTC')->addHour()->startOfHour();
        $booking = Booking::create(['booking_type_id' => $type->id, 'user_id' => $this->owner->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'item_id' => $item->id,
            'guest_name' => 'GUEST-MARK-name', 'guest_email' => 'guest@mark.test', 'guest_timezone' => 'UTC', 'answers' => [], 'status' => Booking::CONFIRMED, 'manage_token_hash' => hash('sha256', 'm')]);

        $this->params = [
            'workspace' => $this->workspace->slug, 'project' => (string) $this->project->id, 'board' => (string) $this->board->id,
            'item' => (string) $item->id, 'key' => (string) $item->key, 'uuid' => (string) $page->uuid, 'plan' => (string) $plan->id,
            'release' => (string) $release->id, 'collection' => (string) $collection->id, 'list' => (string) $list->id,
            'capture' => (string) $capture->uuid, 'booking' => (string) $booking->uuid, 'log' => (string) $log, 'scopeType' => 'item',
            'scopeId' => (string) $item->id, 'pollId' => 'p1', 'tab' => 'overview', 'task' => (string) $task->id,
        ];
    }

    private function uri(RoutingRoute $route, string $name): string
    {
        $path = '/'.Str::after(preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $this->params[$m[1]] ?? '999999', $route->uri()), 'api/v1/');
        $day = now()->toDateString();
        $query = match ($name) {
            'calendar.events' => "?from={$day}&to=".now()->addDay()->toDateString(),
            'timesheet.show' => '?workspace='.$this->workspace->slug,
            'search' => '?q=MARK',
            'items.dated' => "?from={$day}&to={$day}",
            'links.resolve' => '?workspace='.$this->workspace->slug.'&refs[]=item:'.$this->params['item'],
            'me.work', 'work.index' => '?status=all',
            default => '',
        };

        return '/api/v1'.$path.$query;
    }

    public function test_a_key_with_one_area_sees_nothing_of_the_others(): void
    {
        $calls = 0;
        foreach (array_keys(self::MARKERS) as $area) {
            cache()->flush(); // the 600 / min per IP limit
            $key = $this->apiKey($this->owner, [$area => 'read']);
            $foreign = array_merge(...array_values(array_diff_key(self::MARKERS, [$area => true])));

            foreach (Route::getRoutes()->getRoutes() as $route) {
                /** @var RoutingRoute $route */
                $name = Str::after((string) $route->getName(), 'app.v1.');
                if (! Str::startsWith($route->uri(), 'api/v1/') || ! in_array('GET', $route->methods(), true)) {
                    continue;
                }
                $entry = ApiKeyRoutes::entry($name);
                if ($entry === null || ! $entry['allowed'] || $entry['public']) {
                    continue;
                }
                $uri = $this->uri($route, $name);
                $response = $this->getJson($uri, $this->keyHeaders($key));
                $this->assertNotSame(429, $response->status(), "{$area} key: GET {$uri} was rate limited");
                $this->assertLessThan(500, $response->status(), "{$area} key: GET {$uri} failed: ".substr((string) $response->getContent(), 0, 300));
                foreach ($foreign as $marker) {
                    $this->assertStringNotContainsString($marker, (string) $response->getContent(), "{$area} key: GET {$uri} leaked {$marker}");
                }
                $calls++;
            }

            // and its own area is really there somewhere (the test would pass vacuously otherwise)
            $own = $this->getJson(match ($area) {
                'issues' => "/api/v1/items/{$this->params['item']}",
                'docs' => "/api/v1/pages/{$this->params['uuid']}",
                'plans' => "/api/v1/plans/{$this->params['plan']}",
                'library' => "/api/v1/snippet-collections/{$this->params['collection']}",
                'calendar' => '/api/v1/bookings',
                'time' => '/api/v1/work-logs',
                'work' => "/api/v1/task-lists/{$this->params['list']}/items",
            }, $this->keyHeaders($key))->assertOk();
            $this->assertStringContainsString(self::MARKERS[$area][0], (string) $own->getContent(), "{$area} key can't see its own area");
        }
        $this->assertGreaterThan(400, $calls);
    }

    public function test_cross_area_writes_need_write_on_the_target_area(): void
    {
        $inbox = Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'to convert']);
        $workOnly = $this->apiKey($this->owner, ['work' => 'write', 'issues' => 'read']);

        $this->postJson("/api/v1/captures/{$inbox->uuid}/convert", ['type' => 'issue', 'destination_id' => $this->board->id, 'fields' => ['title' => 'x']], $this->keyHeaders($workOnly))
            ->assertForbidden()->assertJsonPath('code', 'key_ability_denied');
        $this->assertNull($inbox->fresh()->processed_at);

        // completing an issue through My Work is a write on the issue
        $this->patchJson("/api/v1/me/work/issue:{$this->params['item']}", ['done' => true], $this->keyHeaders($workOnly))->assertForbidden();
        $this->assertNull(Item::query()->find((int) $this->params['item'])->completed_at);
    }
}
