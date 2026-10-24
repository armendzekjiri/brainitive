<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Helpdesk;
use App\Models\HelpdeskSlaPolicy;
use App\Models\ItemPriority;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\User;
use App\Notifications\TicketSlaBreachedNotification;
use App\Notifications\TicketSlaWarningNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Helpdesk SLA (BRAI-125): the policy settings, the SLA dates on tickets (create, priority, pause,
 * first response, policy changes) and the checker's notifications. Calendar maths: BusinessCalendarTest.
 */
class HelpdeskSlaTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private Helpdesk $helpdesk;

    private User $admin;

    private ItemPriority $high;

    private ItemPriority $medium;

    private TicketContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = User::find($this->workspace->owner_id);
        $this->project->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->admin->id]);
        $this->helpdesk->columns()->create(['name' => 'Waiting on customer', 'slug' => 'waiting', 'position' => 5]);
        $this->high = ItemPriority::create(['name' => 'High', 'slug' => 'high', 'order' => 1]);
        $this->medium = ItemPriority::create(['name' => 'Medium', 'slug' => 'medium', 'order' => 2, 'is_default' => true]);
        $this->contact = TicketContact::create(['email' => 'c@example.test', 'uuid' => (string) Str::uuid()]);

        Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'UTC')); // a Monday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function route(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug, ...$extra]);
    }

    private function column(string $slug): int
    {
        return $this->helpdesk->columns()->where('slug', $slug)->value('id');
    }

    /** High: respond in 2 h, resolve in 1 day; no priority: respond in 4 h. 24/7 unless overridden. */
    private function policy(array $overrides = []): HelpdeskSlaPolicy
    {
        return HelpdeskSlaPolicy::create($overrides + [
            'helpdesk_id' => $this->helpdesk->id,
            'enabled' => true,
            'targets' => [
                (string) $this->high->id => ['first_response' => ['value' => 2, 'unit' => 'hours'], 'resolution' => ['value' => 1, 'unit' => 'days']],
                'none' => ['first_response' => ['value' => 4, 'unit' => 'hours'], 'resolution' => null],
            ],
            'business_hours' => null,
            'timezone' => 'UTC',
            'pause_column_ids' => [$this->column('waiting')],
            'warn_percent' => 80,
        ]);
    }

    private function ticket(array $attributes = []): Ticket
    {
        // unguarded: some tests set created_at
        return Ticket::unguarded(fn () => $this->helpdesk->tickets()->create($attributes + [
            'subject' => 'Printer on fire',
            'column_id' => $this->column('open'),
            'ticket_contact_id' => $this->contact->id,
            'priority_id' => $this->high->id,
            'key' => 'SUP-'.Str::random(4),
            'position' => 0,
        ]))->refresh();
    }

    private static function utc(?\DateTimeInterface $at): ?string
    {
        return $at ? Carbon::instance($at)->utc()->format('Y-m-d H:i') : null;
    }

    // ── settings ─────────────────────────────────────────────────────────────

    public function test_whoever_edits_the_helpdesk_saves_the_policy_and_others_get_403(): void
    {
        $payload = [
            'enabled' => true,
            'targets' => [
                (string) $this->high->id => ['first_response' => ['value' => 30, 'unit' => 'minutes'], 'resolution' => ['value' => 2, 'unit' => 'days']],
                (string) $this->medium->id => ['first_response' => ['value' => '', 'unit' => 'hours'], 'resolution' => ['value' => '', 'unit' => 'hours']],
            ],
            'business_hours' => ['days' => [5, 1, 2, 3, 4], 'from' => '09:00', 'to' => '17:00'],
            'timezone' => 'Europe/Berlin',
            'pause_column_ids' => [$this->column('waiting')],
            'warn_percent' => 75,
        ];

        // defaults before anything is saved: off, 24/7, the "waiting" column suggested as a pause
        $this->actingAs($this->editor)->getJson($this->route('helpdesks.sla.show'))->assertOk()
            ->assertJsonPath('policy.exists', false)
            ->assertJsonPath('policy.enabled', false)
            ->assertJsonPath('policy.pause_column_ids', [$this->column('waiting')])
            ->assertJsonPath('priorities.0.name', 'High');

        $this->actingAs($this->viewer)->getJson($this->route('helpdesks.sla.show'))->assertForbidden();
        $this->actingAs($this->viewer)->putJson($this->route('helpdesks.sla.update'), $payload)->assertForbidden();
        $this->actingAs($this->outsider)->putJson($this->route('helpdesks.sla.update'), $payload)->assertForbidden();
        $this->assertDatabaseCount('helpdesk_sla_policies', 0);

        $this->actingAs($this->editor)->putJson($this->route('helpdesks.sla.update'), $payload)->assertOk()
            ->assertJsonPath('policy.enabled', true)
            ->assertJsonPath('policy.business_hours', ['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '17:00']);

        $policy = $this->helpdesk->slaPolicy()->firstOrFail();
        // empty targets are dropped
        $this->assertSame([$this->high->id], array_keys($policy->targets)); // PHP turns numeric keys into ints
        $this->assertSame(['first_response' => 30, 'resolution' => 960], $policy->targetMinutes($this->high->id)); // 2 business days of 8 h
        $this->assertSame(75, $policy->warn_percent);
    }

    public function test_bad_policies_are_rejected(): void
    {
        $base = ['enabled' => true, 'targets' => [], 'business_hours' => null, 'timezone' => 'UTC', 'pause_column_ids' => [], 'warn_percent' => 80];
        $foreignPriority = ItemPriority::create(['name' => 'Other', 'slug' => 'other', 'order' => 9, 'project_id' => Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id])->id]);

        foreach ([
            'targets.'.$foreignPriority->id => ['targets' => [(string) $foreignPriority->id => ['first_response' => ['value' => 1, 'unit' => 'hours']]]],
            'targets.none.resolution.value' => ['targets' => ['none' => ['resolution' => ['value' => 400, 'unit' => 'days']]]],
            'targets.none.first_response.unit' => ['targets' => ['none' => ['first_response' => ['value' => 4, 'unit' => 'weeks']]]],
            'pause_column_ids.0' => ['pause_column_ids' => [$this->column('resolved')]], // a done column can't pause
            'timezone' => ['timezone' => 'Mars/Olympus'],
            'business_hours.to' => ['business_hours' => ['days' => [1], 'from' => '09:00', 'to' => '09:00']],
            'business_hours.days' => ['business_hours' => ['days' => [], 'from' => '09:00', 'to' => '17:00']],
        ] as $field => $override) {
            $this->actingAs($this->admin)->putJson($this->route('helpdesks.sla.update'), $override + $base)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    // ── ticket dates ─────────────────────────────────────────────────────────

    public function test_a_new_ticket_gets_due_dates_from_its_priority(): void
    {
        $this->policy();

        $this->actingAs($this->admin)->post(route('tickets.store', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]), [
            'subject' => 'Hello', 'column_id' => $this->column('open'), 'contact_email' => 'c@example.test', 'priority_id' => $this->high->id,
        ])->assertSessionHasNoErrors();
        $ticket = Ticket::where('subject', 'Hello')->firstOrFail();

        $this->assertSame('2026-10-19 12:00', self::utc($ticket->sla_first_response_due_at));
        $this->assertSame('2026-10-19 11:36', self::utc($ticket->sla_first_response_warn_at)); // 80 % of 2 h
        $this->assertSame('2026-10-20 10:00', self::utc($ticket->sla_resolution_due_at));
        $this->assertNull($ticket->sla_breached_at);

        // a public ticket has no priority: the "none" row (respond in 4 h, no resolution target)
        $public = $this->ticket(['priority_id' => null]);
        $this->assertSame('2026-10-19 14:00', self::utc($public->sla_first_response_due_at));
        $this->assertNull($public->sla_resolution_due_at);

        // a priority without targets: no SLA
        $this->assertNull($this->ticket(['priority_id' => $this->medium->id])->sla_first_response_due_at);
    }

    public function test_business_hours_and_a_priority_change_recompute_the_dates(): void
    {
        $this->policy(['business_hours' => ['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '17:00'], 'timezone' => 'Europe/Berlin']);

        Carbon::setTestNow(Carbon::parse('2026-10-23 15:00:00', 'UTC')); // Friday 17:00 in Berlin: closed
        $ticket = $this->ticket(['priority_id' => null]);
        $this->assertSame('2026-10-26 12:00', self::utc($ticket->sla_first_response_due_at)); // Monday 13:00 CET (4 h after 09:00)

        Carbon::setTestNow(Carbon::parse('2026-10-24 09:00:00', 'UTC'));
        $this->actingAs($this->admin)->patch(route('tickets.update', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug, $ticket->id]), ['priority_id' => $this->high->id])
            ->assertSessionHasNoErrors();
        $ticket->refresh();
        // counted from creation, not from the change: Monday 09:00 + 2 h, resolution = one 8 h business day
        $this->assertSame('2026-10-26 10:00', self::utc($ticket->sla_first_response_due_at));
        $this->assertSame('2026-10-26 16:00', self::utc($ticket->sla_resolution_due_at));
    }

    public function test_the_first_public_agent_reply_is_the_first_response(): void
    {
        $this->policy();
        $ticket = $this->ticket();
        $agentReply = fn (array $data) => $this->actingAs($this->admin)
            ->post(route('tickets.replies.store', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug, $ticket->id]), $data)
            ->assertSessionHasNoErrors();
        Notification::fake();

        // an internal note and the customer's own reply don't count
        Carbon::setTestNow(Carbon::parse('2026-10-19 10:30:00', 'UTC'));
        $agentReply(['body' => '<p>Looking into it</p>', 'is_internal_note' => true]);
        $this->postJson(route('helpdesks.tickets.replies.store-public', [$this->helpdesk->uuid, $ticket->uuid]), ['body' => 'Any news?', 'access_token' => $ticket->publicAccessToken()])->assertOk();
        $this->assertNull($ticket->refresh()->first_responded_at);

        // the first public agent reply sets it; a later one doesn't move it
        Carbon::setTestNow(Carbon::parse('2026-10-19 11:00:00', 'UTC'));
        $agentReply(['body' => '<p>Fixed?</p>']);
        Carbon::setTestNow(Carbon::parse('2026-10-19 13:00:00', 'UTC'));
        $agentReply(['body' => '<p>Ping</p>']);
        $ticket->refresh();
        $this->assertSame('2026-10-19 11:00', self::utc($ticket->first_responded_at));
        $this->assertFalse($ticket->sla_first_response_breached);

        // a late first response is a breach, dated at the missed due time
        $late = $this->ticket(['created_at' => Carbon::parse('2026-10-19 09:00:00', 'UTC')]);
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:30:00', 'UTC'));
        $late->replies()->create(['user_id' => $this->admin->id, 'body' => 'Sorry', 'is_internal_note' => false]);
        $late->refresh();
        $this->assertTrue($late->sla_first_response_breached);
        $this->assertSame('2026-10-19 11:00', self::utc($late->sla_breached_at));
    }

    public function test_a_waiting_column_pauses_the_resolution_clock_only(): void
    {
        $this->policy();
        $ticket = $this->ticket();
        $this->assertSame('2026-10-20 10:00', self::utc($ticket->sla_resolution_due_at));

        Carbon::setTestNow(Carbon::parse('2026-10-19 11:00:00', 'UTC'));
        $ticket->moveToColumn($ticket->helpdesk->columns()->where('slug', 'waiting')->firstOrFail());
        $this->assertSame('2026-10-19 11:00', self::utc($ticket->refresh()->sla_paused_at));

        // three hours later the customer answers and the ticket goes back to "Open"
        Carbon::setTestNow(Carbon::parse('2026-10-19 14:00:00', 'UTC'));
        $ticket->moveToColumn($ticket->helpdesk->columns()->where('slug', 'open')->firstOrFail());
        $ticket->refresh();
        $this->assertNull($ticket->sla_paused_at);
        $this->assertSame(180, $ticket->sla_paused_minutes);
        $this->assertSame('2026-10-20 13:00', self::utc($ticket->sla_resolution_due_at));
        $this->assertSame('2026-10-19 12:00', self::utc($ticket->sla_first_response_due_at)); // unchanged

        // resolved straight from a pause: the pause counts up to the resolution
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
        $ticket->moveToColumn($ticket->helpdesk->columns()->where('slug', 'waiting')->firstOrFail());
        Carbon::setTestNow(Carbon::parse('2026-10-20 15:00:00', 'UTC'));
        $ticket->moveToColumn($ticket->helpdesk->columns()->where('slug', 'resolved')->firstOrFail());
        $ticket->refresh();
        $this->assertSame(360, $ticket->sla_paused_minutes);
        $this->assertSame('2026-10-20 16:00', self::utc($ticket->sla_resolution_due_at));
        $this->assertFalse($ticket->sla_resolution_breached); // resolved 15:00, due 16:00
    }

    public function test_saving_the_policy_recomputes_open_tickets_and_keeps_resolved_ones(): void
    {
        $open = $this->ticket(['priority_id' => $this->high->id]);
        $resolved = $this->ticket(['priority_id' => $this->high->id]);
        $resolved->moveToColumn($this->helpdesk->columns()->where('slug', 'resolved')->firstOrFail());
        $this->assertNull($open->sla_first_response_due_at); // no policy yet

        $response = $this->actingAs($this->admin)->putJson($this->route('helpdesks.sla.update'), [
            'enabled' => true,
            'targets' => [(string) $this->high->id => ['first_response' => ['value' => 1, 'unit' => 'hours'], 'resolution' => null]],
            'business_hours' => null, 'timezone' => 'UTC', 'pause_column_ids' => [], 'warn_percent' => null,
        ])->assertOk();
        $this->assertSame(1, $response->json('recomputed'));

        $open->refresh();
        $this->assertSame('2026-10-19 11:00', self::utc($open->sla_first_response_due_at));
        $this->assertSame('2026-10-19 11:00', self::utc($open->sla_first_response_warn_at)); // no warning: warn = due
        $this->assertNull($resolved->refresh()->sla_first_response_due_at);

        // switching it off clears the open tickets' SLA
        $this->actingAs($this->admin)->putJson($this->route('helpdesks.sla.update'), [
            'enabled' => false, 'targets' => [], 'business_hours' => null, 'timezone' => 'UTC', 'pause_column_ids' => [], 'warn_percent' => 80,
        ])->assertOk();
        $this->assertNull($open->refresh()->sla_first_response_due_at);
    }

    // ── checker ──────────────────────────────────────────────────────────────

    public function test_the_checker_warns_then_reports_the_breach_once_each(): void
    {
        Notification::fake();
        $this->policy();
        $agent = $this->editor;
        $assigned = $this->ticket(['assigned_to' => $agent->id]);
        $unassigned = $this->ticket();
        $paused = $this->ticket(['column_id' => $this->column('waiting')]);

        // before 80 %: nothing
        Carbon::setTestNow(Carbon::parse('2026-10-19 11:30:00', 'UTC'));
        $this->artisan('app:check-helpdesk-sla')->assertSuccessful();
        Notification::assertNothingSent();

        // 11:40 = past 80 % of the 2 h first response: the assignee, and the admins for the unassigned one
        Carbon::setTestNow(Carbon::parse('2026-10-19 11:40:00', 'UTC'));
        $this->artisan('app:check-helpdesk-sla')->assertSuccessful();
        $this->artisan('app:check-helpdesk-sla')->assertSuccessful();
        Notification::assertSentToTimes($agent, TicketSlaWarningNotification::class, 1);
        Notification::assertSentTo($agent, TicketSlaWarningNotification::class, fn ($n) => $n->ticket->is($assigned) && $n->target === 'first_response');
        Notification::assertSentTo($this->admin, TicketSlaWarningNotification::class, fn ($n) => $n->ticket->is($unassigned));
        Notification::assertSentToTimes($this->admin, TicketSlaWarningNotification::class, 2); // unassigned + paused (first response still pending)
        $this->assertNull($assigned->refresh()->sla_breached_at);

        // after the due time: the breach is recorded and reported once
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:05:00', 'UTC'));
        $this->artisan('app:check-helpdesk-sla')->assertSuccessful();
        $this->artisan('app:check-helpdesk-sla')->assertSuccessful();
        Notification::assertSentToTimes($agent, TicketSlaBreachedNotification::class, 1);
        $assigned->refresh();
        $this->assertTrue($assigned->sla_first_response_breached);
        $this->assertSame('2026-10-19 12:00', self::utc($assigned->sla_breached_at));

        // a day later: resolution breached for the open ones, never for the paused one
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
        $this->artisan('app:check-helpdesk-sla')->assertSuccessful();
        Notification::assertSentTo($agent, TicketSlaBreachedNotification::class, fn ($n) => $n->target === 'resolution');
        Notification::assertNotSentTo($this->admin, TicketSlaBreachedNotification::class, fn ($n) => $n->ticket->is($paused) && $n->target === 'resolution');
        $this->assertFalse($paused->refresh()->sla_resolution_breached);
        $this->assertTrue($assigned->refresh()->sla_resolution_breached);

        // the payload follows the catalog contract
        $data = (new TicketSlaBreachedNotification($assigned, 'resolution', $assigned->sla_resolution_due_at))->toDatabase($agent);
        $this->assertSame('sla_breached', $data['type']);
        $this->assertStringContainsString('missed its resolution target', $data['title']);
        $this->assertSame($assigned->key, $data['subject_key']);
    }

    public function test_the_checker_skips_resolved_tickets_disabled_policies_and_people_without_access(): void
    {
        Notification::fake();
        $policy = $this->policy();
        $resolved = $this->ticket();
        $resolved->moveToColumn($this->helpdesk->columns()->where('slug', 'resolved')->firstOrFail());
        $removed = $this->projectMember('editor');
        $this->ticket(['assigned_to' => $removed->id]);
        $this->project->users()->detach($removed->id);

        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'UTC'));
        $this->artisan('app:check-helpdesk-sla')->assertSuccessful();
        Notification::assertNotSentTo($removed, TicketSlaBreachedNotification::class);
        // the assignee lost access: the admins hear about it instead
        Notification::assertSentTo($this->admin, TicketSlaBreachedNotification::class);
        Notification::assertNotSentTo($this->admin, TicketSlaBreachedNotification::class, fn ($n) => $n->ticket->is($resolved));

        $policy->update(['enabled' => false]);
        $fresh = $this->ticket(['created_at' => Carbon::parse('2026-10-18 10:00:00', 'UTC')]);
        $this->artisan('app:check-helpdesk-sla')->assertSuccessful();
        Notification::assertNotSentTo($this->admin, TicketSlaBreachedNotification::class, fn ($n) => $n->ticket->is($fresh));
    }

    public function test_the_e2e_trigger_route_only_exists_in_the_e2e_environment(): void
    {
        $this->assertFalse(Route::has('helpdesks.sla.e2e-check'));
        $this->assertTrue(Route::has('helpdesks.sla.update'));
    }
}
