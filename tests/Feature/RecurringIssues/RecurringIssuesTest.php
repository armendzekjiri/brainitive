<?php

namespace Tests\Feature\RecurringIssues;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\RecurringIssue;
use App\Notifications\ItemAssignedNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-123: recurring issue templates — the endpoints (permissions, validation, preview) and
 * `app:create-recurring-issues` (local day + DST, idempotency, catch-up, pause, end, actor).
 */
class RecurringIssuesTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $name, ?RecurringIssue $template = null, ?Board $board = null): string
    {
        return route($name, array_filter([$this->workspace->slug, $this->project->slug, ($board ?? $this->board)->slug, $template?->id]));
    }

    /** A template as the editor saved it (Europe/Berlin), weekly on Mondays from 2026-10-26 unless overridden. */
    private function template(array $attributes = []): RecurringIssue
    {
        $template = new RecurringIssue($attributes + [
            'board_id' => $this->board->id,
            'created_by' => $this->editor->id,
            'title' => 'Weekly report {{date}}',
            'frequency' => 'weekly',
            'interval' => 1,
            'weekdays' => [1],
            'start_date' => '2026-10-26',
            'timezone' => 'Europe/Berlin',
            'active_from' => '2026-10-24',
        ]);
        $template->refreshNextOccurrence()->save();

        return $template;
    }

    private function runAt(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        $this->artisan('app:create-recurring-issues')->assertSuccessful();
    }

    /** @return Collection<int, Item> */
    private function createdBy(RecurringIssue $template)
    {
        return Item::whereIn('id', $template->occurrences()->pluck('item_id'))->orderBy('id')->get();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Sprint review {{date}}',
            'frequency' => 'weekly',
            'interval' => 1,
            'weekdays' => [1, 4],
            'start_date' => '2026-10-26',
        ];
    }

    // ── Endpoints ──────────────────────────────────────────────────────────────

    public function test_whoever_can_create_issues_manages_templates_and_others_get_403(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00', 'UTC'));
        $this->editor->update(['timezone' => 'Europe/Berlin']);

        $response = $this->actingAs($this->editor)->postJson($this->url('recurring-issues.store'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('template.summary', 'Every week on Mon, Thu')
            ->assertJsonPath('template.badge_label', 'Repeats weekly')
            ->assertJsonPath('template.next_occurrence_date', '2026-10-26')
            ->assertJsonPath('template.timezone', 'Europe/Berlin')
            ->assertJsonPath('template.creator.id', $this->editor->id);
        $template = RecurringIssue::findOrFail($response->json('template.id'));
        $this->assertSame('2026-10-24', $template->active_from->toDateString());

        $this->actingAs($this->editor)->getJson($this->url('recurring-issues.index'))
            ->assertOk()
            ->assertJsonCount(1, 'templates')
            ->assertJsonPath('options.columns.0.name', 'To Do')
            ->assertJsonFragment(['id' => $this->editor->id, 'name' => $this->editor->name]);

        foreach ([$this->viewer, $this->outsider] as $user) {
            $this->actingAs($user)->getJson($this->url('recurring-issues.index'))->assertForbidden();
            $this->actingAs($user)->postJson($this->url('recurring-issues.store'), $this->payload())->assertForbidden();
            $this->actingAs($user)->putJson($this->url('recurring-issues.update', $template), $this->payload())->assertForbidden();
            $this->actingAs($user)->postJson($this->url('recurring-issues.pause', $template))->assertForbidden();
            $this->actingAs($user)->deleteJson($this->url('recurring-issues.destroy', $template))->assertForbidden();
            $this->actingAs($user)->postJson($this->url('recurring-issues.preview'), $this->payload())->assertForbidden();
        }
        $this->assertSame(1, RecurringIssue::count());
    }

    public function test_foreign_ids_and_bad_schedules_are_rejected(): void
    {
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $foreignLabel = $otherProject->labels()->create(['name' => 'Elsewhere', 'color' => '#000000']);
        $foreignBoard = Board::factory()->create(['project_id' => $otherProject->id]);

        $this->actingAs($this->editor)->postJson($this->url('recurring-issues.store'), $this->payload([
            'label_ids' => [$foreignLabel->id],
            'column_id' => $foreignBoard->columns()->value('id'),
            'assigned_to' => $this->outsider->id,
            'end_date' => '2026-10-01',
            'interval' => 0,
            'frequency' => 'hourly',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['label_ids.0', 'column_id', 'assigned_to', 'end_date', 'interval', 'frequency']);

        // a template of another board can't be reached through this one (scoped binding)
        $other = $this->template(['board_id' => Board::factory()->create(['project_id' => $this->project->id])->id]);
        $this->actingAs($this->editor)->putJson($this->url('recurring-issues.update', $other), $this->payload())->assertNotFound();
        $this->actingAs($this->editor)->deleteJson($this->url('recurring-issues.destroy', $other))->assertNotFound();
    }

    public function test_preview_shows_the_next_five_dates_and_respects_the_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00', 'UTC'));

        $this->actingAs($this->editor)->postJson($this->url('recurring-issues.preview'), $this->payload(['weekdays' => [1]]))
            ->assertOk()
            ->assertJsonPath('dates', ['2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23'])
            ->assertJsonPath('summary', 'Every week on Mon');

        // past start: only dates from today on; 3 occurrences at most
        $this->actingAs($this->editor)->postJson($this->url('recurring-issues.preview'), $this->payload([
            'frequency' => 'monthly', 'monthly_mode' => 'day', 'month_day' => 31, 'start_date' => '2026-01-31', 'max_occurrences' => 3,
        ]))->assertOk()->assertJsonPath('dates', ['2026-10-31', '2026-11-30', '2026-12-31']);
    }

    public function test_changing_the_schedule_starts_it_from_today_and_delete_keeps_the_issues(): void
    {
        $template = $this->template(['active_from' => '2026-01-01']);
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', 'UTC'));

        // only the title changes: active_from stays
        $this->actingAs($this->editor)->putJson($this->url('recurring-issues.update', $template), $this->payload(['weekdays' => [1], 'title' => 'Renamed']))
            ->assertOk()->assertJsonPath('template.title', 'Renamed');
        $this->assertSame('2026-01-01', $template->fresh()->active_from->toDateString());

        $this->actingAs($this->editor)->putJson($this->url('recurring-issues.update', $template), $this->payload(['weekdays' => [2], 'title' => 'Renamed']))
            ->assertOk()->assertJsonPath('template.next_occurrence_date', '2026-11-10');
        $this->assertSame('2026-11-10', $template->fresh()->active_from->toDateString());

        $this->runAt('2026-11-10 10:00');
        $item = $this->createdBy($template)->sole();
        $this->actingAs($this->editor)->deleteJson($this->url('recurring-issues.destroy', $template))->assertOk();
        $this->assertModelMissing($template);
        $this->assertNotNull($item->fresh());
    }

    // ── The command ────────────────────────────────────────────────────────────

    public function test_the_issue_is_created_from_local_midnight_across_the_dst_change(): void
    {
        $type = ItemType::create(['name' => 'Chore', 'slug' => 'chore-test', 'is_active' => true]);
        $priority = ItemPriority::create(['name' => 'Highest', 'slug' => 'highest-test', 'color' => '#f00', 'order' => 1]);
        $label = $this->project->labels()->create(['name' => 'Ops', 'color' => '#22c55e']);
        $template = $this->template([
            'description' => '<p>Collect the numbers</p>',
            'item_type_id' => $type->id,
            'priority_id' => $priority->id,
            'label_ids' => [$label->id],
            'column_id' => $this->column('in-progress'),
            'due_in_days' => 2,
        ]);

        // Berlin left summer time on Sunday 2026-10-25: Monday starts at 23:00 UTC, not 22:00
        $this->runAt('2026-10-25 22:30');
        $this->assertCount(0, $this->createdBy($template));

        $this->runAt('2026-10-25 23:00');
        $item = $this->createdBy($template)->sole();
        $this->assertSame('Weekly report 2026-10-26', $item->title);
        $this->assertSame('<p>Collect the numbers</p>', $item->description);
        $this->assertSame($type->id, $item->item_type_id);
        $this->assertSame($priority->id, $item->priority_id);
        $this->assertSame([$label->id], $item->labels()->pluck('labels.id')->all());
        $this->assertSame($this->column('in-progress'), $item->column_id);
        $this->assertSame('2026-10-28', $item->due_date->toDateString());
        $this->assertNull($item->sprint_id);
        $this->assertSame($this->editor->id, $item->created_by);
        $this->assertSame($this->editor->id, $item->reporter_id);
        $this->assertStringStartsWith('BRAI-', $item->key);

        $template->refresh();
        $this->assertSame(1, $template->occurrences_count);
        $this->assertSame('2026-10-26', $template->last_occurrence_date->toDateString());
        $this->assertSame('2026-11-02', $template->next_occurrence_date->toDateString());
    }

    public function test_a_zone_behind_utc_waits_for_its_own_midnight(): void
    {
        $template = $this->template(['timezone' => 'America/Los_Angeles']);

        $this->runAt('2026-10-26 06:59'); // 23:59 on Sunday in Los Angeles (PDT)
        $this->assertCount(0, $this->createdBy($template));

        $this->runAt('2026-10-26 07:00');
        $this->assertCount(1, $this->createdBy($template));
    }

    public function test_running_again_never_creates_a_second_issue(): void
    {
        $template = $this->template();

        $this->runAt('2026-10-26 08:00');
        $this->runAt('2026-10-26 08:00');
        $this->runAt('2026-10-26 20:00');
        $this->runAt('2026-10-29 20:00');

        $this->assertCount(1, $this->createdBy($template));
        $this->assertSame(1, $template->fresh()->occurrences_count);
        $this->assertSame(1, $template->occurrences()->count());
    }

    public function test_after_downtime_only_the_latest_missed_occurrence_is_created(): void
    {
        $template = $this->template(['frequency' => 'daily', 'weekdays' => null, 'start_date' => '2026-10-01', 'active_from' => '2026-10-01', 'title' => 'Check backups {{date}}']);

        $this->runAt('2026-10-20 09:00');

        $this->assertSame(['Check backups 2026-10-20'], $this->createdBy($template)->pluck('title')->all());
        $this->assertSame('2026-10-21', $template->fresh()->next_occurrence_date->toDateString());

        $this->runAt('2026-10-22 09:00'); // the 21st was missed again: only the 22nd
        $this->assertSame(['Check backups 2026-10-20', 'Check backups 2026-10-22'], $this->createdBy($template)->pluck('title')->all());
    }

    public function test_paused_templates_create_nothing_and_resuming_skips_the_paused_dates(): void
    {
        $template = $this->template(['frequency' => 'daily', 'weekdays' => null, 'start_date' => '2026-10-24']);
        Carbon::setTestNow(Carbon::parse('2026-10-24 06:00', 'UTC'));
        $this->actingAs($this->editor)->postJson($this->url('recurring-issues.pause', $template))->assertOk()->assertJsonPath('template.paused', true);

        $this->runAt('2026-10-26 09:00');
        $this->assertCount(0, $this->createdBy($template));

        // resumed by another editor, late in the evening of the 27th (Berlin): the 24th–26th never come
        $other = $this->projectMember('editor');
        Carbon::setTestNow(Carbon::parse('2026-10-27 20:00', 'UTC'));
        $this->actingAs($other)->postJson($this->url('recurring-issues.resume', $template))
            ->assertOk()
            ->assertJsonPath('template.paused', false)
            ->assertJsonPath('template.next_occurrence_date', '2026-10-27')
            ->assertJsonPath('template.creator.id', $other->id);

        $this->runAt('2026-10-27 20:05');
        $item = $this->createdBy($template)->sole();
        $this->assertSame('Weekly report 2026-10-27', $item->title);
        $this->assertSame($other->id, $item->created_by);
    }

    public function test_the_count_and_the_end_date_finish_the_series(): void
    {
        $counted = $this->template(['frequency' => 'daily', 'weekdays' => null, 'start_date' => '2026-10-24', 'max_occurrences' => 2]);
        $ended = $this->template(['frequency' => 'daily', 'weekdays' => null, 'start_date' => '2026-10-24', 'end_date' => '2026-10-25']);

        foreach (['2026-10-24 12:00', '2026-10-25 12:00', '2026-10-26 12:00', '2026-10-27 12:00'] as $now) {
            $this->runAt($now);
        }

        $this->assertCount(2, $this->createdBy($counted));
        $this->assertCount(2, $this->createdBy($ended));
        $this->assertNull($counted->fresh()->next_occurrence_date);
        $this->assertNull($ended->fresh()->next_occurrence_date);
        $this->actingAs($this->editor)->getJson($this->url('recurring-issues.index'))->assertJsonPath('templates.0.finished', true);
    }

    public function test_the_assignee_hears_about_it_from_the_templates_creator(): void
    {
        Notification::fake();
        $template = $this->template(['assigned_to' => $this->viewer->id]);
        $self = $this->template(['assigned_to' => $this->editor->id, 'title' => 'Mine']);

        $this->runAt('2026-10-26 08:00');

        $this->assertSame($this->viewer->id, $this->createdBy($template)->sole()->assigned_to);
        Notification::assertSentTo($this->viewer, ItemAssignedNotification::class, fn (ItemAssignedNotification $n) => $n->actor->is($this->editor));
        Notification::assertNotSentTo($this->editor, ItemAssignedNotification::class); // assigning yourself is silent
        $this->assertSame($this->editor->id, $this->createdBy($self)->sole()->assigned_to);
        $this->assertFalse(Auth::check(), 'the console is signed out again');
    }

    public function test_an_assignee_who_left_gets_an_unassigned_issue(): void
    {
        $template = $this->template(['assigned_to' => $this->viewer->id]);
        $this->project->users()->detach($this->viewer->id);

        $this->runAt('2026-10-26 08:00');

        $this->assertNull($this->createdBy($template)->sole()->assigned_to);
    }

    public function test_a_creator_who_lost_access_pauses_the_template(): void
    {
        $template = $this->template();
        $this->project->users()->updateExistingPivot($this->editor->id, ['role' => 'viewer']);

        $this->runAt('2026-10-26 08:00');

        $this->assertCount(0, $this->createdBy($template));
        $template->refresh();
        $this->assertNotNull($template->paused_at);
        $this->assertStringContainsString('can no longer create issues', $template->last_error);
    }

    public function test_cards_show_the_repeat_badge(): void
    {
        $template = $this->template(['interval' => 2]);
        $this->runAt('2026-10-26 08:00');
        $item = $this->createdBy($template)->sole();

        $this->actingAs($this->viewer)
            ->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('board.columns.0.items.0.id', $item->id)
                ->where('board.columns.0.items.0.recurring_occurrence.recurring_issue.badge_label', 'Repeats every 2 weeks'));
    }

    public function test_the_e2e_trigger_exists_only_in_the_e2e_environment(): void
    {
        $this->assertFalse(Route::has('recurring-issues.e2e-run'));
        // no route: only the GET fallback matches (405), nothing runs
        $status = $this->actingAs($this->editor)->postJson($this->url('recurring-issues.index').'/e2e-run')->status();
        $this->assertContains($status, [404, 405]);
    }
}
