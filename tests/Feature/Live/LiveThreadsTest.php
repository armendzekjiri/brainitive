<?php

namespace Tests\Feature\Live;

use App\Models\Board;
use App\Models\Comment;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Support\Live\Live;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Pages\InteractsWithPages;
use Tests\TestCase;

/**
 * Live basics (BRAI-151 part 2): comments publish on `item.{id}`, ticket replies on the new
 * `ticket.{id}` channel, notification read / archive actions on `user.{id}` — ids and counts only, the
 * payload rule holds (unit tests throw on a refused key). Plan: docs/ai/tasks/feat-live-basics.md.
 */
class LiveThreadsTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    private Board $board;

    private Item $item;

    private Helpdesk $helpdesk;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        config(['collab.url' => 'wss://brainitive.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false, 'live.enabled' => true, 'live.url' => null, 'live.publish_url' => 'http://127.0.0.1:6299']);
        Cache::forget('live:publish:paused');
        $this->withoutDefer();
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 1], 202)]);

        $this->board = Board::factory()->create(['project_id' => $this->project->id]);
        $this->item = Item::factory()->create(['board_id' => $this->board->id, 'created_by' => $this->author->id]);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->admin->id]);
        $this->ticket = Ticket::create([
            'helpdesk_id' => $this->helpdesk->id,
            'column_id' => $this->helpdesk->columns()->first()->id,
            'key' => 'WEB-T1',
            'subject' => 'Printer on fire',
        ]);
    }

    /**
     * @return list<array{channel: string, event: string, payload: array<string, mixed>}>
     */
    private function published(?string $channel = null): array
    {
        return collect(Http::recorded())
            ->flatMap(fn ($pair) => json_decode($pair[0]->body(), true)['messages'] ?? [])
            ->filter(fn ($message) => $channel === null || $message['channel'] === $channel)
            ->values()->all();
    }

    private function itemRoute(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $this->board->slug, $this->item->id, ...$extra]);
    }

    public function test_comments_and_replies_publish_ids_on_the_issue_channel(): void
    {
        Notification::fake();
        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.comments.store'), ['body' => '<p>Secret plan</p>'])->assertRedirect();
        $comment = Comment::where('item_id', $this->item->id)->firstOrFail();
        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.comments.store'), ['body' => '<p>Reply</p>', 'parent_id' => $comment->id])->assertRedirect();
        $reply = Comment::where('parent_id', $comment->id)->firstOrFail();

        $comment->update(['body' => '<p>Edited secret</p>']);
        $comment->update(['updated_at' => now()->addMinute()]); // nothing visible changed: no event
        $reply->delete();

        $channel = "item.{$this->item->id}";
        $this->assertSame([
            ['channel' => $channel, 'event' => 'comment.created', 'payload' => ['id' => $this->item->id, 'comment_id' => $comment->id, 'parent_id' => null]],
            ['channel' => $channel, 'event' => 'comment.created', 'payload' => ['id' => $this->item->id, 'comment_id' => $reply->id, 'parent_id' => $comment->id]],
            ['channel' => $channel, 'event' => 'comment.updated', 'payload' => ['id' => $this->item->id, 'comment_id' => $comment->id, 'parent_id' => null]],
            ['channel' => $channel, 'event' => 'comment.deleted', 'payload' => ['id' => $this->item->id, 'comment_id' => $reply->id, 'parent_id' => $comment->id]],
        ], $this->published($channel));

        // content never travels: every payload passes the allow-list (validate throws otherwise)
        foreach ($this->published() as $message) {
            Live::validate($message['channel'], $message['event'], $message['payload']);
        }
        $this->assertStringNotContainsString('Secret', json_encode($this->published()));
    }

    public function test_ticket_replies_publish_on_the_ticket_channel_from_every_path(): void
    {
        Notification::fake();
        $this->actingAs($this->editor)->post(route('tickets.replies.store', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug, $this->ticket->id]), [
            'body' => '<p>Internal: customer is angry</p>',
            'is_internal_note' => true,
        ])->assertRedirect();
        $reply = TicketReply::where('ticket_id', $this->ticket->id)->firstOrFail();
        // e.g. inbound mail / the portal create replies directly
        $contactReply = $this->ticket->replies()->create(['body' => '<p>From the customer</p>']);
        $reply->update(['email_status' => 'sent']);
        $contactReply->delete();

        $channel = "ticket.{$this->ticket->id}";
        $this->assertSame([
            ['channel' => $channel, 'event' => 'reply.created', 'payload' => ['id' => $this->ticket->id, 'reply_id' => $reply->id]],
            ['channel' => $channel, 'event' => 'reply.created', 'payload' => ['id' => $this->ticket->id, 'reply_id' => $contactReply->id]],
            ['channel' => $channel, 'event' => 'reply.updated', 'payload' => ['id' => $this->ticket->id, 'reply_id' => $reply->id]],
            ['channel' => $channel, 'event' => 'reply.deleted', 'payload' => ['id' => $this->ticket->id, 'reply_id' => $contactReply->id]],
        ], $this->published($channel));
        $this->assertStringNotContainsString('angry', json_encode($this->published()));
    }

    public function test_the_ticket_channel_follows_the_ticket_policy(): void
    {
        $channel = "ticket.{$this->ticket->id}";
        $token = fn (User $user) => $this->actingAs($user)->postJson(route('live.token'), ['channels' => [$channel]]);

        $token($this->viewer)->assertOk()->assertJsonPath('channels', [$channel]);
        $token($this->editor)->assertOk()->assertJsonPath('channels', [$channel]);
        $token($this->outsider)->assertOk()->assertJsonPath('channels', [])->assertJsonPath('denied', [$channel]);
        $this->actingAs($this->viewer)->postJson(route('live.token'), ['channels' => ['ticket.abc', 'ticket.0']])->assertOk()->assertJsonPath('channels', []);

        // trashed ticket / helpdesk: gone
        $this->helpdesk->delete();
        $token($this->admin)->assertOk()->assertJsonPath('channels', []);
    }

    public function test_reading_notifications_updates_the_users_other_tabs(): void
    {
        $this->viewer->notify(new MentionNotification(contextType: 'issue', contextTitle: 'WEB-1 - A', contextUrl: 'https://example.test/x', actor: $this->editor));
        $id = $this->viewer->notifications()->firstOrFail()->id;

        $this->actingAs($this->viewer)->postJson(route('notifications.read', $id))->assertOk()->assertJsonPath('unread_count', 0);
        $this->actingAs($this->viewer)->postJson(route('notifications.unread', $id))->assertOk()->assertJsonPath('unread_count', 1);
        $this->actingAs($this->viewer)->postJson(route('notifications.read-all'))->assertOk();

        $changes = collect($this->published("user.{$this->viewer->id}"))->where('event', 'notifications.changed')->pluck('payload')->values()->all();
        $this->assertSame([['unread_count' => 0], ['unread_count' => 1], ['unread_count' => 0]], $changes);
    }
}
