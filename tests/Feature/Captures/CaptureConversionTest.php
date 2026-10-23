<?php

namespace Tests\Feature\Captures;

use App\Jobs\FetchLinkMetadataJob;
use App\Models\Board;
use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\Capture;
use App\Models\Item;
use App\Models\Page;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Quick capture (BRAI-70): organizing a capture into content — every destination, the policy
 * matrix, atomicity (rollback), idempotency (double click / retry) and sanitization.
 */
class CaptureConversionTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected TaskList $taskList;

    protected Bookmark $bookmark;

    protected SnippetCollection $collection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();

        // snippets need a workspace editor (BookmarkPolicy / SnippetPolicy)
        $this->workspace->users()->updateExistingPivot($this->editor->id, ['role' => 'editor']);
        $this->taskList = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->bookmark = Bookmark::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Reading',
            'visibility' => 'workspace',
            'created_by' => $this->editor->id,
        ]);
        $this->collection = SnippetCollection::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Scripts',
            'slug' => 'scripts',
            'created_by' => $this->editor->id,
        ]);
    }

    private function capture(User $user, array $attributes = []): Capture
    {
        return Capture::factory()->create($attributes + ['user_id' => $user->id]);
    }

    /** The preview fields for a type, as the dialog loads them. */
    private function preview(Capture $capture, string $type): array
    {
        return $this->getJson(route('captures.preview', [$capture, 'type' => $type]))->assertOk()->json('fields');
    }

    private function convert(Capture $capture, string $type, int $destinationId, ?array $fields = null)
    {
        return $this->postJson(route('captures.convert', $capture), [
            'type' => $type,
            'destination_id' => $destinationId,
            'fields' => $fields ?? $this->preview($capture, $type),
        ]);
    }

    private function assertUnprocessed(Capture $capture): void
    {
        $capture->refresh();
        $this->assertNull($capture->processed_at);
        $this->assertNull($capture->target_type);
        $this->assertNull($capture->target_id);
    }

    public function test_converts_to_a_board_issue_keeping_markdown_code_and_the_url(): void
    {
        $capture = $this->capture($this->editor, [
            'text' => "Login fails on Safari\n\nSteps:\n```js\nif (a < b) { login(); }\n```\nSee **logs**.",
            'url' => 'https://example.com/report?id=1&x=2',
        ]);
        $this->actingAs($this->editor);

        $fields = $this->preview($capture, 'issue');
        $this->assertSame('Login fails on Safari', $fields['title']);
        $this->assertStringContainsString('[https://example.com/report?id=1&x=2](https://example.com/report?id=1&x=2)', $fields['description']);

        $response = $this->convert($capture, 'issue', $this->board->id, $fields + ['column_id' => $this->column('in-progress')])
            ->assertCreated()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('capture.state', 'processed')
            ->assertJsonPath('capture.target.type', 'issue')
            ->assertJsonPath('capture.target.available', true);

        $item = Item::sole();
        $this->assertSame('Login fails on Safari', $item->title);
        $this->assertSame($this->column('in-progress'), $item->column_id);
        $this->assertStringStartsWith('BRAI-', $item->key);
        $this->assertStringContainsString('<pre><code class="language-js">if (a &lt; b) { login(); }</code></pre>', $item->description);
        $this->assertStringContainsString('<strong>logs</strong>', $item->description);
        $this->assertStringContainsString('<a href="https://example.com/report?id=1&amp;x=2">', $item->description);
        $this->assertStringEndsWith('#item-'.$item->id, $response->json('location.url'));

        $capture->refresh();
        $this->assertSame('processed', $capture->state());
        $this->assertSame(['issue', $item->id], [$capture->target_type, $capture->target_id]);
        $this->assertSame($response->json('location.url'), $capture->target_location['url']);
        // the source text stays as captured (recoverable)
        $this->assertStringStartsWith('Login fails on Safari', $capture->text);
    }

    public function test_converts_to_a_task_list_task(): void
    {
        $capture = $this->capture($this->editor, ['text' => "Read the RFC\n- section 2\n- section 4", 'url' => 'https://example.com/rfc']);
        $this->actingAs($this->editor);

        $response = $this->convert($capture, 'task', $this->taskList->id)->assertCreated();

        $task = TaskListItem::sole();
        $this->assertSame('Read the RFC', $task->title);
        $this->assertSame('lexical', $task->description['type']);
        $this->assertStringContainsString('<ul><li>section 2</li><li>section 4</li></ul>', $task->description['html']);
        $this->assertStringContainsString('<a href="https://example.com/rfc">', $task->description['html']);
        $this->assertStringContainsString('/task-lists/', $response->json('location.url'));
        $this->assertSame('task', $capture->fresh()->target_type);
    }

    public function test_converts_to_a_private_draft_doc(): void
    {
        $capture = $this->capture($this->editor, ['text' => "# Queue design\n\nUse `jobs` table.\n\n```php\nQueue::push(\$job);\n```"]);
        $this->actingAs($this->editor);

        $fields = $this->preview($capture, 'page');
        $this->assertSame('Queue design', $fields['title']);

        $response = $this->convert($capture, 'page', $this->project->id, $fields)->assertCreated();

        $page = Page::where('uuid', basename($response->json('location.url')))->firstOrFail();
        $this->assertSame('Queue design', $page->name);
        $this->assertSame($this->editor->id, $page->created_by);
        $this->assertNotSame('published', $page->status);
        $this->assertStringContainsString('<code>jobs</code>', $page->content['html']);
        $this->assertStringContainsString('Queue::push($job);', $page->content['html']);
    }

    public function test_converts_to_a_bookmark_link_or_a_note(): void
    {
        Queue::fake();
        $this->actingAs($this->editor);

        $link = $this->capture($this->editor, ['text' => "Great article on CRDTs\nread later", 'url' => 'https://example.com/crdt']);
        $fields = $this->preview($link, 'bookmark');
        $this->assertSame(['type' => 'link', 'title' => 'Great article on CRDTs', 'url' => 'https://example.com/crdt', 'content' => 'read later'], $fields);
        $this->convert($link, 'bookmark', $this->bookmark->id, $fields)->assertCreated()
            ->assertJsonPath('location.type', 'bookmark');
        Queue::assertPushed(FetchLinkMetadataJob::class);

        $note = $this->capture($this->editor, ['text' => "Gift ideas\n- book\n- tea"]);
        $this->convert($note, 'bookmark', $this->bookmark->id)->assertCreated();

        $items = BookmarkItem::orderBy('id')->get();
        $this->assertSame(['link', 'note'], $items->pluck('type')->all());
        $this->assertSame('https://example.com/crdt', $items[0]->url);
        $this->assertSame("Gift ideas\n- book\n- tea", $items[1]->content);
    }

    public function test_converts_to_a_snippet_keeping_code_verbatim(): void
    {
        $code = "server {\n    listen 80;\n    # <not html>\n}";
        $capture = $this->capture($this->editor, ['text' => "Nginx default\n```nginx\n{$code}\n```", 'url' => 'https://example.com/nginx']);
        $this->actingAs($this->editor);

        $fields = $this->preview($capture, 'snippet');
        $this->assertSame(['title' => 'Nginx default', 'content' => $code, 'language' => 'nginx', 'description' => 'Source: https://example.com/nginx'], $fields);

        $this->convert($capture, 'snippet', $this->collection->id, $fields)->assertCreated()
            ->assertJsonPath('location.type', 'snippet');

        $snippet = Snippet::sole();
        $this->assertSame($code, $snippet->content);
        $this->assertSame('nginx', $snippet->language);

        // plain text without a fence becomes a plaintext snippet of the whole text
        $plain = $this->capture($this->editor, ['text' => "SELECT *\n  FROM users;"]);
        $this->assertSame("SELECT *\n  FROM users;", $this->preview($plain, 'snippet')['content']);
        $this->assertSame('plaintext', $this->preview($plain, 'snippet')['language']);
    }

    public function test_double_click_and_retry_create_one_target(): void
    {
        $capture = $this->capture($this->editor, ['text' => 'Only once']);
        $this->actingAs($this->editor);
        $fields = $this->preview($capture, 'issue');

        $first = $this->convert($capture, 'issue', $this->board->id, $fields)->assertCreated();
        $second = $this->convert($capture, 'issue', $this->board->id, $fields)->assertOk()->assertJsonPath('replayed', true);
        // a retry aimed elsewhere still returns what was created first
        $third = $this->convert($capture, 'task', $this->taskList->id, ['title' => 'Only once'])->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame(1, Item::count());
        $this->assertSame(0, TaskListItem::count());
        $this->assertSame($first->json('location.url'), $second->json('location.url'));
        $this->assertSame($first->json('location.url'), $third->json('location.url'));
    }

    public function test_policy_matrix_leaves_the_capture_unprocessed(): void
    {
        $private = Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'Mine', 'visibility' => 'private', 'created_by' => $this->viewer->id]);

        // [user, type, destination] that must be refused
        $denied = [
            [$this->viewer, 'issue', $this->board->id],
            [$this->viewer, 'task', $this->taskList->id],
            [$this->viewer, 'page', $this->project->id],
            [$this->viewer, 'snippet', $this->collection->id], // workspace member, not editor
            [$this->editor, 'bookmark', $private->id],         // someone else's private group
            [$this->outsider, 'issue', $this->board->id],
            [$this->outsider, 'task', $this->taskList->id],
            [$this->outsider, 'page', $this->project->id],
            [$this->outsider, 'bookmark', $this->bookmark->id],
            [$this->outsider, 'snippet', $this->collection->id],
        ];

        // a workspace editor who isn't a member of the project
        $wsEditor = User::factory()->create();
        $this->workspace->addUser($wsEditor, 'editor');
        $denied[] = [$wsEditor, 'issue', $this->board->id];
        $denied[] = [$wsEditor, 'task', $this->taskList->id];
        $denied[] = [$wsEditor, 'page', $this->project->id];

        foreach ($denied as [$user, $type, $id]) {
            $capture = $this->capture($user, ['text' => "Try {$type}", 'url' => 'https://example.com']);
            $this->actingAs($user);
            $this->convert($capture, $type, $id, CaptureFieldsFixture::for($type))
                ->assertForbidden();
            $this->assertUnprocessed($capture);
        }

        $this->assertSame(0, Item::count());
        $this->assertSame(0, TaskListItem::count());
        $this->assertSame(0, Page::count());
        $this->assertSame(0, BookmarkItem::count());
        $this->assertSame(0, Snippet::count());

        // and the same destinations work for the editor
        $this->actingAs($this->editor);
        foreach (['issue' => $this->board->id, 'task' => $this->taskList->id, 'page' => $this->project->id, 'bookmark' => $this->bookmark->id, 'snippet' => $this->collection->id] as $type => $id) {
            $this->convert($this->capture($this->editor, ['text' => "Allowed {$type}"]), $type, $id, CaptureFieldsFixture::for($type))->assertCreated();
        }
    }

    public function test_permission_loss_after_capturing_leaves_it_in_the_inbox(): void
    {
        $capture = $this->capture($this->editor, ['text' => 'Captured while I was a member']);
        $this->project->users()->detach($this->editor->id);

        $this->actingAs($this->editor);
        $this->convert($capture, 'issue', $this->board->id, ['title' => 'Captured while I was a member'])->assertForbidden();
        $this->assertUnprocessed($capture);
        $this->assertSame('inbox', $capture->fresh()->state());
    }

    public function test_invalid_destinations_and_fields_are_rejected(): void
    {
        $capture = $this->capture($this->editor, ['text' => 'Somewhere']);
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->actingAs($this->editor);

        $this->convert($capture, 'issue', 999999, ['title' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('destination_id');
        $this->convert($capture, 'helpdesk', $this->board->id, ['title' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->convert($capture, 'issue', $this->board->id, ['title' => 'x', 'column_id' => $otherBoard->columns()->first()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('column_id');
        $this->convert($capture, 'issue', $this->board->id, ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->convert($capture, 'bookmark', $this->bookmark->id, ['type' => 'link', 'url' => 'javascript:alert(1)'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->convert($capture, 'snippet', $this->collection->id, ['title' => 'x', 'content' => 'y', 'language' => '<script>'])
            ->assertUnprocessable()->assertJsonValidationErrors('language');

        $this->assertUnprocessed($capture);
        $this->assertSame(0, Item::count());
    }

    public function test_archived_captures_must_be_restored_before_converting(): void
    {
        $capture = $this->capture($this->editor, ['text' => 'Old idea', 'archived_at' => now()]);
        $this->actingAs($this->editor);

        $this->convert($capture, 'issue', $this->board->id, ['title' => 'Old idea'])->assertUnprocessable()->assertJsonValidationErrors('capture');
        $this->assertUnprocessed($capture);
    }

    public function test_a_failure_rolls_everything_back(): void
    {
        $capture = $this->capture($this->editor, ['text' => 'Half done']);
        $this->actingAs($this->editor);

        // A board without columns can't take issues: nothing is created.
        $empty = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $empty->columns()->delete();
        $this->convert($capture, 'issue', $empty->id, ['title' => 'Half done'])->assertUnprocessable()->assertJsonValidationErrors('destination_id');
        $this->assertUnprocessed($capture);

        // Marking the capture processed fails after the issue was written: the issue is rolled back.
        $fail = true;
        Capture::updating(function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('disk full');
            }
        });
        $this->convert($capture, 'issue', $this->board->id, ['title' => 'Half done'])->assertServerError();

        $fail = false;
        $this->assertSame(0, Item::withTrashed()->count());
        $this->assertUnprocessed($capture);

        // …and the retry then succeeds once.
        $this->convert($capture, 'issue', $this->board->id, ['title' => 'Half done'])->assertCreated();
        $this->assertSame(1, Item::count());
    }

    public function test_markup_in_a_capture_is_escaped_in_the_content(): void
    {
        $capture = $this->capture($this->editor, ['text' => "<script>alert(1)</script> Title\n<img src=x onerror=alert(2)> and [click](javascript:alert(3))"]);
        $this->actingAs($this->editor);

        $this->convert($capture, 'issue', $this->board->id)->assertCreated();
        $item = Item::sole();

        // titles are plain text everywhere (React escapes them); the HTML body is what matters
        $this->assertSame('<script>alert(1)</script> Title', $item->title);
        $this->assertStringNotContainsString('<script', $item->description);
        $this->assertStringNotContainsString('<img', $item->description);
        $this->assertStringNotContainsString('javascript:', $item->description);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $item->description);
        // the capture keeps the raw text; the page renders it as text, never as HTML
        $this->assertStringStartsWith('<script>', $capture->fresh()->text);
    }

    public function test_processed_list_links_to_the_created_content(): void
    {
        $capture = $this->capture($this->editor, ['text' => 'Ship it']);
        $this->actingAs($this->editor);
        $url = $this->convert($capture, 'issue', $this->board->id)->json('location.url');

        $this->get(route('captures.index', ['state' => 'processed']))
            ->assertInertia(fn ($p) => $p->where('captures.0.target.url', $url)->where('captures.0.target.available', true)->where('counts.inbox', 0));

        // deleted since: the snapshot stays, marked unavailable
        Item::sole()->delete();
        $this->get(route('captures.index', ['state' => 'processed']))
            ->assertInertia(fn ($p) => $p->where('captures.0.target.url', $url)->where('captures.0.target.available', false));
    }

    public function test_destinations_list_only_writable_targets(): void
    {
        $this->actingAs($this->editor)->getJson(route('captures.destinations'))
            ->assertOk()
            ->assertJsonPath('workspaces.0.projects.0.boards.0.id', $this->board->id)
            ->assertJsonPath('workspaces.0.projects.0.task_lists.0.id', $this->taskList->id)
            ->assertJsonPath('workspaces.0.bookmarks.0.id', $this->bookmark->id)
            ->assertJsonPath('workspaces.0.snippet_collections.0.id', $this->collection->id);

        $this->actingAs($this->outsider)->getJson(route('captures.destinations'))->assertOk()->assertJsonPath('workspaces', []);
    }
}

/** Minimal valid fields per type, for the policy matrix. */
final class CaptureFieldsFixture
{
    public static function for(string $type): array
    {
        return match ($type) {
            'issue', 'task', 'page' => ['title' => 'Captured idea'],
            'bookmark' => ['type' => 'link', 'title' => 'Captured', 'url' => 'https://example.com'],
            'snippet' => ['title' => 'Captured', 'content' => 'echo 1'],
        };
    }
}
