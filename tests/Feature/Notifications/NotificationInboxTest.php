<?php

namespace Tests\Feature\Notifications;

use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The inbox feed, filters and read / unread / archive actions (BRAI-102). */
class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function notification(array $data, array $attributes = [], ?User $user = null): DatabaseNotification
    {
        return ($user ?? $this->user)->notifications()->create($attributes + [
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'data' => $data,
        ]);
    }

    private function feed(array $query = []): array
    {
        return $this->actingAs($this->user)->getJson(route('notifications.index', $query))->assertOk()->json();
    }

    public function test_feed_lists_newest_first_with_category(): void
    {
        $older = $this->notification(['type' => 'item_assigned', 'item_key' => 'WEB-1'], ['created_at' => now()->subHour()]);
        $newer = $this->notification(['type' => 'mentioned', 'context_title' => 'Spec'], ['created_at' => now()]);

        $feed = $this->feed();

        $this->assertSame([$newer->id, $older->id], array_column($feed['data'], 'id'));
        $this->assertSame('mentions', $feed['data'][0]['category']);
        $this->assertSame('assignments', $feed['data'][1]['category']);
        $this->assertSame('WEB-1', $feed['data'][1]['data']['item_key']);
        $this->assertSame(2, $feed['unread_count']);
        $this->assertNull($feed['next_cursor']);
    }

    public function test_unknown_types_have_no_category_and_never_break_the_feed(): void
    {
        $this->notification(['type' => 'something_new', 'title' => 'Max did a new thing', 'url' => '/x']);
        $this->notification(['no_type' => true]);

        $feed = $this->feed();
        $this->assertCount(2, $feed['data']);
        $this->assertSame([null, null], array_column($feed['data'], 'category'));
        $this->assertSame('something_new', $feed['data'][1]['type'] ?? $feed['data'][0]['type']);

        $this->assertCount(0, $this->feed(['filter' => 'mentions'])['data']);
    }

    public function test_filters_unread_mentions_and_assigned(): void
    {
        $mention = $this->notification(['type' => 'mentioned']);
        $assigned = $this->notification(['type' => 'item_assigned'], ['read_at' => now()]);
        $ticket = $this->notification(['type' => 'ticket_assigned']);
        $status = $this->notification(['type' => 'item_status_changed']);

        $ids = fn (string $filter) => collect($this->feed(['filter' => $filter])['data'])->pluck('id')->sort()->values()->all();

        $this->assertEquals(collect([$mention->id, $ticket->id, $status->id])->sort()->values()->all(), $ids('unread'));
        $this->assertEquals([$mention->id], $ids('mentions'));
        $this->assertEquals(collect([$assigned->id, $ticket->id])->sort()->values()->all(), $ids('assigned'));
        $this->assertCount(4, $this->feed(['filter' => 'bogus'])['data']);
    }

    public function test_archived_rows_only_show_in_the_archived_view(): void
    {
        $inbox = $this->notification(['type' => 'mentioned']);
        $archived = $this->notification(['type' => 'mentioned'], ['archived_at' => now(), 'read_at' => now()]);

        $this->assertSame([$inbox->id], array_column($this->feed()['data'], 'id'));
        $this->assertSame([$archived->id], array_column($this->feed(['archived' => 1])['data'], 'id'));
    }

    public function test_feed_pages_with_a_cursor(): void
    {
        foreach (range(1, 25) as $i) {
            $this->notification(['type' => 'mentioned', 'n' => $i], ['created_at' => now()->subMinutes($i)]);
        }

        $first = $this->feed();
        $this->assertCount(20, $first['data']);
        $this->assertNotNull($first['next_cursor']);

        $second = $this->feed(['cursor' => $first['next_cursor']]);
        $this->assertCount(5, $second['data']);
        $this->assertSame(21, $second['data'][0]['data']['n']);
        $this->assertNull($second['next_cursor']);
    }

    public function test_only_own_notifications_are_listed_or_changed(): void
    {
        $other = User::factory()->create();
        $foreign = $this->notification(['type' => 'mentioned'], [], $other);

        $this->assertCount(0, $this->feed()['data']);
        $this->actingAs($this->user)->postJson(route('notifications.read', $foreign->id))->assertNotFound();
        $this->actingAs($this->user)->postJson(route('notifications.archive', $foreign->id))->assertNotFound();
        $this->actingAs($this->user)->postJson(route('notifications.bulk'), ['ids' => [$foreign->id], 'action' => 'archive'])->assertOk();

        $this->assertNull($foreign->fresh()->read_at);
        $this->assertNull(InboxNotification::find($foreign->id)->archived_at);
    }

    public function test_a_malformed_id_never_reaches_the_database(): void
    {
        // whereUuid: no route matches, so Postgres never sees an invalid uuid (that would be a 500)
        $status = $this->actingAs($this->user)->postJson('/api/notifications/not-a-uuid/read')->status();
        $this->assertContains($status, [404, 405]);
    }

    public function test_read_and_unread(): void
    {
        $n = $this->notification(['type' => 'mentioned']);

        $this->actingAs($this->user)->postJson(route('notifications.read', $n->id))->assertOk()->assertJson(['unread_count' => 0]);
        $this->assertNotNull($n->fresh()->read_at);

        $this->actingAs($this->user)->postJson(route('notifications.unread', $n->id))->assertOk()->assertJson(['unread_count' => 1]);
        $this->assertNull($n->fresh()->read_at);
    }

    public function test_archive_marks_read_and_unread_brings_it_back_to_the_inbox(): void
    {
        $n = $this->notification(['type' => 'mentioned']);

        $this->actingAs($this->user)->postJson(route('notifications.archive', $n->id))->assertOk()->assertJson(['unread_count' => 0]);
        $row = InboxNotification::find($n->id);
        $this->assertNotNull($row->archived_at);
        $this->assertNotNull($row->read_at);

        $this->actingAs($this->user)->postJson(route('notifications.unarchive', $n->id))->assertOk();
        $this->assertNull(InboxNotification::find($n->id)->archived_at);

        $this->actingAs($this->user)->postJson(route('notifications.archive', $n->id))->assertOk();
        $this->actingAs($this->user)->postJson(route('notifications.unread', $n->id))->assertOk()->assertJson(['unread_count' => 1]);
        $row = InboxNotification::find($n->id);
        $this->assertNull($row->archived_at);
        $this->assertNull($row->read_at);
    }

    public function test_bulk_actions(): void
    {
        $a = $this->notification(['type' => 'mentioned']);
        $b = $this->notification(['type' => 'item_assigned']);
        $c = $this->notification(['type' => 'item_assigned']);

        $this->actingAs($this->user)->postJson(route('notifications.bulk'), ['ids' => [$a->id, $b->id], 'action' => 'read'])
            ->assertOk()->assertJson(['unread_count' => 1]);
        $this->actingAs($this->user)->postJson(route('notifications.bulk'), ['ids' => [$a->id, $c->id], 'action' => 'archive'])
            ->assertOk()->assertJson(['unread_count' => 0]);

        $this->assertSame([$b->id], array_column($this->feed()['data'], 'id'));
        $this->assertCount(2, $this->feed(['archived' => 1])['data']);
    }

    public function test_bulk_validation(): void
    {
        $this->actingAs($this->user)->postJson(route('notifications.bulk'), ['ids' => [], 'action' => 'read'])->assertUnprocessable();
        $this->actingAs($this->user)->postJson(route('notifications.bulk'), ['ids' => ['nope'], 'action' => 'read'])->assertUnprocessable()->assertJsonValidationErrors('ids.0');
        $this->actingAs($this->user)->postJson(route('notifications.bulk'), ['ids' => [(string) Str::uuid()], 'action' => 'delete'])->assertUnprocessable()->assertJsonValidationErrors('action');
    }

    public function test_read_all_marks_the_inbox_read(): void
    {
        $this->notification(['type' => 'mentioned']);
        $this->notification(['type' => 'item_assigned']);
        $other = $this->notification(['type' => 'mentioned'], [], User::factory()->create());

        $this->actingAs($this->user)->postJson(route('notifications.read-all'))->assertOk()->assertJson(['unread_count' => 0]);

        $this->actingAs($this->user)->getJson(route('notifications.unread-count'))->assertOk()->assertJson(['count' => 0]);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_opening_the_page_does_not_mark_anything_read(): void
    {
        $n = $this->notification(['type' => 'mentioned']);

        $this->withoutVite()->actingAs($this->user)->get(route('notifications.page', ['filter' => 'mentions']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Notifications/Index')
                ->where('filter', 'mentions')
                ->where('archived', false)
                ->has('feed.data', 1)
                ->where('feed.data.0.id', $n->id)
                ->where('feed.unread_count', 1));

        $this->assertNull($n->fresh()->read_at);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('notifications.page'))->assertRedirect(route('login'));
        $this->getJson(route('notifications.index'))->assertUnauthorized();
    }
}
