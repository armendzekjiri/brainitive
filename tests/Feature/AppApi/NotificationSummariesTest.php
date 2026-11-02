<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Notifications\NotificationText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * `summary` + `url` of `GET /api/v1/notifications`: the web bell's sentence (NotificationMessage.tsx) as one line
 * of plain text per type, with a safe fallback for unknown types, and a link only to a page of this server the
 * user can open now.
 */
class NotificationSummariesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    /** @param  array<string, mixed>  $data */
    private function notify(array $data, ?User $user = null): string
    {
        $id = (string) Str::uuid();
        ($user ?? $this->editor)->notifications()->create(['id' => $id, 'type' => 'App\\Notifications\\Test', 'data' => $data]);

        return $id;
    }

    /** @return array<string, array{summary: string, url: string|null}> by id */
    private function inbox(): array
    {
        $rows = $this->getJson('/api/v1/notifications?limit=100', $this->appHeaders($this->appToken($this->editor)))->assertOk()->json('data');

        return collect($rows)->mapWithKeys(fn (array $row) => [$row['id'] => ['summary' => $row['summary'], 'url' => $row['url']]])->all();
    }

    private function itemUrl(Item $item, Board $board, Project $project, Workspace $workspace): string
    {
        return route('boards.items.show', [$workspace->slug, $project->slug, $board->slug, $item->id]);
    }

    public function test_every_worded_type_reads_like_the_bell(): void
    {
        $item = $this->item(['title' => 'Fix login']);
        $itemUrl = $this->itemUrl($item, $this->board, $this->project, $this->workspace);
        $page = Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Roadmap']);
        $pageUrl = route('pages.show', [$this->workspace->slug, $this->project->slug, $page->uuid]);

        $cases = [
            [['type' => 'item_assigned', 'actor_name' => 'Ada', 'item_key' => 'BRAI-1', 'item_title' => 'Fix login', 'item_url' => $itemUrl], 'Ada assigned you to BRAI-1', $itemUrl],
            [['type' => 'item_status_changed', 'actor_name' => 'Ada', 'item_key' => 'BRAI-1', 'old_column' => 'To Do', 'new_column' => 'Done', 'item_url' => $itemUrl], 'Ada moved BRAI-1 from To Do → Done', $itemUrl],
            [['type' => 'item_reminder', 'item_key' => 'BRAI-1', 'when_label' => 'is due in 1 hour', 'item_url' => $itemUrl], 'BRAI-1 is due in 1 hour', $itemUrl],
            [['type' => 'mentioned', 'actor_name' => 'Bob', 'context_type' => 'page', 'context_title' => 'Roadmap', 'context_url' => $pageUrl], 'Bob mentioned you on page Roadmap', $pageUrl],
            [['type' => 'mentioned', 'actor_name' => 'Bob', 'context_type' => 'item', 'context_title' => 'BRAI-1 <b>Fix</b> &amp; ship', 'context_url' => $itemUrl], 'Bob mentioned you in BRAI-1 Fix & ship', $itemUrl],
            [['type' => 'page_updated', 'actor_name' => 'Cy', 'page_name' => 'Roadmap', 'page_url' => $pageUrl], 'Cy updated page Roadmap', $pageUrl],
            [['type' => 'ticket_created', 'contact_email' => 'jane@example.com', 'ticket_key' => 'SUP-3'], 'jane@example.com opened ticket SUP-3', null],
            [['type' => 'ticket_reply', 'actor_name' => 'Jane', 'ticket_key' => 'SUP-3'], 'Jane replied to ticket SUP-3', null],
            [['type' => 'ticket_assigned', 'ticket_key' => 'SUP-3'], 'Someone assigned you to ticket SUP-3', null],
            [['type' => 'lead_created', 'lead_number' => 4, 'lead_name' => 'Jane Doe', 'form_name' => 'Contact'], 'New lead #4 from Jane Doe', null],
            [['type' => 'task_digest', 'due_today' => 2, 'overdue' => 1, 'titles' => ['A'], 'url' => '/my-work'], 'Your tasks: 2 due today · 1 overdue', url('/my-work')],
            [['type' => 'task_digest', 'due_today' => 0, 'overdue' => 0], 'Your tasks: nothing due', null],
            // catalog types: the server worded the title; mentions and Markdown come out as plain text
            [['type' => 'item_commented', 'title' => "**Ada** commented on BRAI-1: hi @[Bob](user:{$this->viewer->id}) see [the doc](https://x.test)", 'url' => $itemUrl, 'actor_name' => 'Ada'], 'Ada commented on BRAI-1: hi @Bob see the doc', $itemUrl],
            // unknown types never break: the generic line
            [['type' => 'brand_new_type', 'actor_name' => 'Ada', 'subject_key' => 'X-9'], 'Ada updated X-9', null],
            [['type' => 'brand_new_type'], 'Someone sent you a notification', null],
            [['message' => 'no type at all'], 'Someone sent you a notification', null],
        ];
        $ids = array_map(fn (array $case) => $this->notify($case[0]), $cases);

        $inbox = $this->inbox();
        foreach ($cases as $i => [$data, $summary, $url]) {
            $this->assertSame($summary, $inbox[$ids[$i]]['summary'], json_encode($data));
            $this->assertSame($url, $inbox[$ids[$i]]['url'], json_encode($data));
        }
    }

    public function test_the_summary_is_one_capped_line_of_plain_text(): void
    {
        $id = $this->notify(['type' => 'workspace_role_changed', 'title' => "# Heading\n\n> ".str_repeat('word ', 80).'<script>alert(1)</script>']);

        $summary = $this->inbox()[$id]['summary'];
        $this->assertSame(NotificationText::MAX, mb_strlen($summary));
        $this->assertStringEndsWith('…', $summary);
        $this->assertStringStartsWith('Heading word word', $summary);
        $this->assertStringNotContainsString("\n", $summary);
        $this->assertStringNotContainsString('<', $summary);
    }

    public function test_the_url_is_null_when_the_user_cannot_open_the_page(): void
    {
        // a project of the same workspace the editor is no member of
        $owner = User::factory()->create();
        $hidden = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $owner->id, 'key' => 'HID']);
        $hiddenBoard = Board::factory()->create(['project_id' => $hidden->id, 'created_by' => $owner->id]);
        $hiddenItem = Item::factory()->create(['board_id' => $hiddenBoard->id, 'created_by' => $owner->id]);
        // a draft doc of someone else in the editor's own project
        $draft = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $owner->id, 'name' => 'Secret draft']);
        // another workspace entirely
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $deleted = $this->item();
        $deletedUrl = $this->itemUrl($deleted, $this->board, $this->project, $this->workspace);
        $deleted->delete();

        $links = [
            'hidden project' => $this->itemUrl($hiddenItem, $hiddenBoard, $hidden, $this->workspace),
            'draft of someone else' => route('pages.show', [$this->workspace->slug, $this->project->slug, $draft->uuid]),
            'other workspace' => url('/workspaces/'.$otherWorkspace->slug),
            'deleted issue' => $deletedUrl,
            'foreign host' => 'https://evil.example.com/my-work',
            'protocol-relative' => '//evil.example.com/my-work',
            'javascript' => 'javascript:alert(1)',
            'placeholder' => '#',
            'unknown path' => '/definitely/not/a/page',
            'api' => '/api/v1/me',
        ];
        $ids = array_map(fn (string $link) => $this->notify(['type' => 'item_assigned', 'actor_name' => 'Ada', 'item_key' => 'X-1', 'item_url' => $link]), $links);

        $inbox = $this->inbox();
        foreach ($ids as $case => $id) {
            $this->assertNull($inbox[$id]['url'], $case);
            $this->assertSame('Ada assigned you to X-1', $inbox[$id]['summary']);
        }

        // the same links work for someone who may open them
        $this->hiddenMemberSees($hidden, $links['hidden project']);
    }

    private function hiddenMemberSees(Project $hidden, string $link): void
    {
        $hidden->users()->attach($this->editor->id, ['role' => 'viewer']);
        $id = $this->notify(['type' => 'item_assigned', 'item_url' => $link]);

        $this->assertSame($link, $this->inbox()[$id]['url']);
    }
}
