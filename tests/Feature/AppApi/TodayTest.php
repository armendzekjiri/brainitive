<?php

namespace Tests\Feature\AppApi;

use App\Models\CalendarConnection;
use App\Models\MyDayEntry;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\NormalizedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Calendar\FakeCalendarProvider;
use Tests\TestCase;

/**
 * GET /api/v1/me/today (BRAI-396, the TV wallboard): work due today / overdue, today's My Day and
 * today's events in one call — no per-row queries, and an ETag that answers 304 when nothing changed.
 */
class TodayTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        CalendarProviders::fake('fake', FakeCalendarProvider::class);
        FakeCalendarProvider::reset();
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00', 'UTC'));
        $this->editor->update(['timezone' => 'Europe/Berlin']);
        $this->token = $this->appToken($this->editor);
    }

    protected function tearDown(): void
    {
        CalendarProviders::fake('fake', null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function today(array $headers = [])
    {
        return $this->getJson('/api/v1/me/today', $this->appHeaders($this->token) + $headers);
    }

    public function test_work_my_day_and_events_of_today_in_one_call(): void
    {
        CalendarConnection::factory()->provider('fake')->create(['user_id' => $this->editor->id, 'external_calendar_name' => 'Work']);
        FakeCalendarProvider::$events = [
            NormalizedEvent::make(['id' => 'standup', 'title' => 'Standup', 'start_date' => '2026-11-10', 'start_time' => '09:00', 'end_date' => '2026-11-10', 'end_time' => '09:15', 'timezone' => 'Europe/Berlin']),
            NormalizedEvent::allDay('tomorrow', 'Tomorrow', '2026-11-11'),
        ];
        $overdue = $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-09']);
        $due = $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-10']);
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-12']); // upcoming: not today
        $this->item(['assigned_to' => $this->editor->id]); // no date
        $planned = $this->item(['title' => 'Planned']);
        MyDayEntry::create(['user_id' => $this->editor->id, 'work_type' => 'board', 'work_id' => $planned->id, 'plan_date' => '2026-11-10', 'position' => 1]);

        $response = $this->today()->assertOk()
            ->assertJsonPath('data.date', '2026-11-10')
            ->assertJsonPath('data.timezone', 'Europe/Berlin')
            ->assertJsonPath('data.errors', [])
            ->assertHeader('Cache-Control', 'no-cache, private');

        $this->assertSame(["issue:{$overdue->id}", "issue:{$due->id}"], array_column($response->json('data.work'), 'id'));
        $this->assertSame(["issue:{$planned->id}"], array_column($response->json('data.my_day'), 'id'));
        $this->assertSame(['Standup'], array_column($response->json('data.events'), 'title'));
        $this->assertSame('2026-11-10T08:00:00Z', $response->json('data.events.0.start'));
        $this->assertSame(['id', 'type', 'key', 'title', 'done', 'status', 'priority', 'due_date', 'due_time', 'project', 'workspace', 'source', 'url', 'updated_at'], array_keys($response->json('data.work.0')));
    }

    public function test_an_unchanged_refresh_answers_304(): void
    {
        $item = $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-10']);

        $etag = $this->today()->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->today(['If-None-Match' => $etag])->assertStatus(304)->assertNoContent(304);

        // a change → a new body and ETag
        $item->update(['title' => 'Renamed']);
        $changed = $this->today(['If-None-Match' => $etag])->assertOk()->assertJsonPath('data.work.0.title', 'Renamed');
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_work(): void
    {
        $list = TaskList::factory()->create(['project_id' => $this->project->id]);
        $seed = function (int $n) use ($list) {
            foreach (range(1, $n) as $i) {
                $item = $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-10']);
                TaskListItem::factory()->create(['task_list_id' => $list->id, 'assignee_id' => $this->editor->id, 'due_date' => '2026-11-09', 'created_by' => $this->editor->id]);
                MyDayEntry::create(['user_id' => $this->editor->id, 'work_type' => 'board', 'work_id' => $item->id, 'plan_date' => '2026-11-10', 'position' => $i]);
            }
        };
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->today()->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->today()->assertOk(); // first call of the device: last_used_at, instance settings
        $seed(2);
        $few = $count();
        $seed(8);
        $many = $count();

        $this->assertSame($few, $many, 'GET /me/today must not query per work item');
    }

    public function test_other_tokens_are_refused(): void
    {
        $this->getJson('/api/v1/me/today')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/me/today', $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }
}
