<?php

namespace Tests\Feature\AppApi;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ItemCommentedNotification;
use App\Notifications\MentionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 comments, watchers, attachments and history of an issue (BRAI-405): the issue dialog's rules
 * (everybody who can open it reads and watches; editors comment and add files), the shared code
 * (ItemComments notifications + history, ItemAttachments), Markdown bodies with mentions.
 */
class IssueActivityTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private Item $issue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->issue = $this->item(['title' => 'Talk about it', 'reporter_id' => $this->editor->id]);
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->editor));
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/items/{$this->issue->id}{$path}";
    }

    // ── comments ────────────────────────────────────────────────────────────────────────────

    public function test_lists_comments_oldest_first_with_replies_as_markdown(): void
    {
        $first = Comment::create(['item_id' => $this->issue->id, 'user_id' => $this->editor->id, 'body' => '<p>First <strong>one</strong></p>']);
        $this->travel(1)->minutes();
        $reply = Comment::create(['item_id' => $this->issue->id, 'user_id' => $this->viewer->id, 'body' => '<p>A reply</p>', 'parent_id' => $first->id]);
        Comment::create(['item_id' => $this->item()->id, 'user_id' => $this->editor->id, 'body' => '<p>elsewhere</p>']);

        $response = $this->getJson($this->url('/comments'), $this->headers($this->viewer))->assertOk()->assertJsonPath('next_cursor', null);
        $this->assertSame([$first->id, $reply->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['id', 'item_id', 'parent_id', 'body', 'author', 'created_at', 'updated_at'], array_keys($response->json('data.0')));
        $response->assertJsonPath('data.0.body', 'First **one**')
            ->assertJsonPath('data.1.parent_id', $first->id)
            ->assertJsonPath('data.1.author.id', $this->viewer->id);
        $this->assertArrayNotHasKey('email', $response->json('data.0.author'));

        $page = $this->getJson($this->url('/comments?limit=1'), $this->headers())->assertOk();
        $this->getJson($this->url('/comments?limit=1&cursor='.urlencode($page->json('next_cursor'))), $this->headers())
            ->assertOk()->assertJsonPath('data.0.id', $reply->id);
    }

    public function test_editors_comment_with_mentions_like_the_web(): void
    {
        $mentioned = $this->projectMember('viewer');
        $watcher = $this->projectMember('viewer');
        $this->issue->addWatcher($watcher);

        $requestId = '7d6c2b1a-3e4f-4a5b-8c9d-0e1f2a3b4c5d';
        $response = $this->postJson($this->url('/comments'), ['body' => "Looks **good**, @[M](user:{$mentioned->id})", 'request_id' => $requestId], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.author.id', $this->editor->id)
            ->assertJsonPath('data.body', "Looks **good**, @[{$mentioned->name}](user:{$mentioned->id})");
        Notification::assertSentTo($mentioned, MentionNotification::class);
        Notification::assertSentTo($watcher, ItemCommentedNotification::class);
        $this->assertSame(1, ActivityLog::where('loggable_id', $this->issue->id)->where('action', 'commented')->count());

        // a retry posts nothing new
        $this->postJson($this->url('/comments'), ['body' => "Looks **good**, @[M](user:{$mentioned->id})", 'request_id' => $requestId], $this->headers())
            ->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertSame(1, $this->issue->comments()->count());

        // a reply
        $this->postJson($this->url('/comments'), ['body' => 'Thanks', 'parent_id' => $response->json('data.id')], $this->headers())
            ->assertCreated()->assertJsonPath('data.parent_id', $response->json('data.id'));
    }

    public function test_comment_rules_and_rights(): void
    {
        $elsewhere = Comment::create(['item_id' => $this->item()->id, 'user_id' => $this->editor->id, 'body' => '<p>x</p>']);

        // the web lets only the people who may edit the issue comment
        $this->postJson($this->url('/comments'), ['body' => 'Hi'], $this->headers($this->viewer))->assertForbidden();
        $this->postJson($this->url('/comments'), ['body' => '', 'parent_id' => $elsewhere->id], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['body', 'parent_id']);
    }

    // ── watchers ────────────────────────────────────────────────────────────────────────────

    public function test_anybody_who_can_open_the_issue_watches_it(): void
    {
        $this->getJson($this->url('/watchers'), $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.watching', false)
            ->assertJsonPath('data.watchers.0.id', $this->editor->id); // the reporter

        $this->postJson($this->url('/watch'), [], $this->headers($this->viewer))->assertOk()->assertJsonPath('data.watching', true)->assertJsonCount(2, 'data.watchers');
        $this->postJson($this->url('/watch'), [], $this->headers($this->viewer))->assertOk()->assertJsonCount(2, 'data.watchers');
        $this->getJson($this->url(), $this->headers($this->viewer))->assertOk()->assertJsonPath('data.watching', true);

        $this->deleteJson($this->url('/watch'), [], $this->headers($this->viewer))->assertOk()->assertJsonPath('data.watching', false)->assertJsonCount(1, 'data.watchers');
        $this->assertFalse($this->issue->watchers()->whereKey($this->viewer->id)->exists());
    }

    // ── attachments ─────────────────────────────────────────────────────────────────────────

    public function test_editors_add_and_remove_files(): void
    {
        Storage::fake('public');

        $response = $this->post($this->url('/attachments'), ['file' => UploadedFile::fake()->create('spec.pdf', 120, 'application/pdf')], $this->headers() + ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'spec.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.uploaded_by.id', $this->editor->id);
        $attachment = $this->issue->attachments()->firstOrFail();
        Storage::disk('public')->assertExists($attachment->path);
        $this->assertSame(asset('storage/'.$attachment->path), $response->json('data.url'));
        $this->assertSame(1, ActivityLog::where('loggable_id', $this->issue->id)->where('action', 'attachment_added')->count());

        $this->getJson($this->url('/attachments'), $this->headers($this->viewer))->assertOk()->assertJsonPath('data.0.id', $attachment->id);
        $this->getJson($this->url(), $this->headers())->assertJsonPath('data.counts.attachments', 1);

        // viewers can't; too big → 422; another issue's file → 404
        $this->post($this->url('/attachments'), ['file' => UploadedFile::fake()->create('a.txt', 1)], $this->headers($this->viewer) + ['Accept' => 'application/json'])->assertForbidden();
        $this->post($this->url('/attachments'), ['file' => UploadedFile::fake()->create('big.zip', 10241)], $this->headers() + ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $other = $this->item();
        $this->deleteJson("/api/v1/items/{$other->id}/attachments/{$attachment->id}", [], $this->headers())->assertNotFound();
        $this->deleteJson($this->url("/attachments/{$attachment->id}"), [], $this->headers($this->viewer))->assertForbidden();

        $this->deleteJson($this->url("/attachments/{$attachment->id}"), [], $this->headers())->assertNoContent();
        Storage::disk('public')->assertMissing($attachment->path);
        $this->assertSame(0, $this->issue->attachments()->count());
    }

    // ── history ─────────────────────────────────────────────────────────────────────────────

    public function test_history_newest_first(): void
    {
        ActivityLog::query()->where('loggable_id', $this->issue->id)->delete();
        $this->postJson($this->url('/comments'), ['body' => 'One'], $this->headers())->assertCreated();
        $this->travel(1)->minutes();
        $this->patchJson($this->url(), ['title' => 'Renamed'], $this->appHeaders($this->appToken($this->editor)))->assertOk();

        $response = $this->getJson($this->url('/activity'), $this->headers($this->viewer))->assertOk();
        $actions = array_column($response->json('data'), 'action');
        $this->assertSame('commented', end($actions));
        $this->assertNotSame('commented', $actions[0]);
        $this->assertSame(['id', 'action', 'changes', 'metadata', 'user', 'created_at'], array_keys($response->json('data.0')));
        $this->assertSame($this->editor->id, $response->json('data.0.user.id'));

        $page = $this->getJson($this->url('/activity?limit=1'), $this->headers())->assertOk();
        $this->assertCount(1, $page->json('data'));
        $this->assertNotNull($page->json('next_cursor'));
    }

    public function test_foreign_issues_are_not_found_and_passport_tokens_rejected(): void
    {
        $foreignProject = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
        $foreign = Item::factory()->create(['board_id' => Board::factory()->create(['project_id' => $foreignProject->id])->id]);

        foreach (['/comments', '/watchers', '/attachments', '/activity'] as $path) {
            $this->getJson("/api/v1/items/{$foreign->id}{$path}", $this->headers())->assertNotFound();
        }
        $this->postJson("/api/v1/items/{$foreign->id}/comments", [], $this->headers())->assertNotFound();
        $this->postJson("/api/v1/items/{$foreign->id}/watch", [], $this->headers())->assertNotFound();
        $this->deleteJson("/api/v1/items/{$foreign->id}/watch", [], $this->headers())->assertNotFound();
        $this->postJson('/api/v1/items/abc/comments', ['body' => 'x'], $this->headers())->assertNotFound();
        $this->deleteJson($this->url('/attachments/abc'), [], $this->headers())->assertNotFound();

        $this->getJson($this->url('/comments'), $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->postJson($this->url('/watch'), [], $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }
}
