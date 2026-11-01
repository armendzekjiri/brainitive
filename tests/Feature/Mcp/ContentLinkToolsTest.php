<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\GetBoardItemTool;
use App\Mcp\Tools\GetPageTool;
use App\Mcp\Tools\LinkIssuesTool;
use App\Mcp\Tools\LinkRelatedContentTool;
use App\Mcp\Tools\ListContentLinksTool;
use App\Mcp\Tools\UnlinkIssuesTool;
use App\Mcp\Tools\UnlinkRelatedContentTool;
use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\ContentReference;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * MCP access to "Related content", "Referenced by" and "Linked issues" (BRAI-432, GitHub #132):
 * same services, permissions and visibility as the issue / doc view.
 */
class ContentLinkToolsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private int $requests = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    // ─── Fixtures ────────────────────────────────────────────────────────────

    private function doc(array $attributes = []): Page
    {
        return Page::factory()->published()->create($attributes + [
            'project_id' => $this->project->id,
            'created_by' => $this->editor->id,
            'name' => 'Weekly sync notes',
            'content' => ['type' => 'lexical', 'html' => '<p>Notes</p>'],
        ]);
    }

    private function draft(User $author, string $name): Page
    {
        $page = Page::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $author->id,
            'name' => $name,
            'content' => ['type' => 'lexical', 'html' => '<p>x</p>'],
        ]);
        $this->assertTrue($page->isDraft());

        return $page;
    }

    private function ticket(): Ticket
    {
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->editor->id]);

        return Ticket::create([
            'helpdesk_id' => $helpdesk->id,
            'column_id' => $helpdesk->columns()->first()->id,
            'key' => 'BRAI-T1',
            'subject' => 'Printer on fire',
        ]);
    }

    private function plan(array $rows = []): Plan
    {
        return Plan::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->editor->id,
            'name' => 'Launch plan',
            'data' => ['rows' => $rows, 'dependencies' => []],
        ]);
    }

    // ─── Calls ───────────────────────────────────────────────────────────────

    private function mcp(User $user, string $tool, array $args): TestResponse
    {
        return BrainitiveServer::actingAs($user)->tool($tool, ['workspace' => $this->workspace->slug, 'project' => $this->project->slug] + $args);
    }

    private function write(User $user, string $tool, array $args): TestResponse
    {
        return $this->mcp($user, $tool, $args + ['request_id' => 'req-'.(++$this->requests)]);
    }

    private function links(User $user, string $type, string|int $id): array
    {
        return $this->content($this->mcp($user, ListContentLinksTool::class, ['type' => $type, 'id' => (string) $id])->assertOk());
    }

    private function content(TestResponse $response): array
    {
        return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
    }

    private function linkDoc(User $user, Item $item, Page $page): TestResponse
    {
        return $this->write($user, LinkRelatedContentTool::class, ['source_type' => 'item', 'source_id' => (string) $item->key, 'target_type' => 'page', 'target_id' => $page->uuid]);
    }

    // ─── Related content / referenced by ─────────────────────────────────────

    public function test_link_a_meeting_task_to_its_notes_doc_and_read_both_sides(): void
    {
        $task = $this->item(['title' => 'Weekly sync']);
        $doc = $this->doc();

        $args = ['source_type' => 'item', 'source_id' => (string) $task->key, 'target_type' => 'doc', 'target_id' => $doc->uuid, 'request_id' => 'link-1'];
        $first = $this->content($this->mcp($this->editor, LinkRelatedContentTool::class, $args)->assertOk());
        $this->assertTrue($first['created']);
        $this->assertSame('Weekly sync notes', $first['target']);
        $this->assertStringContainsString('#item-'.$task->id, $first['location']['url']);

        // Retry with the same request_id, and the same link with a new request_id: no second row.
        $this->assertFalse($this->content($this->mcp($this->editor, LinkRelatedContentTool::class, $args)->assertOk())['created']);
        $this->assertFalse($this->content($this->linkDoc($this->editor, $task, $doc)->assertOk())['created']);
        $this->assertSame(1, ContentReference::count());

        // From the task: Related content. Ids as the AI gets them (numeric id works as well as the key).
        $fromTask = $this->links($this->viewer, 'item', $task->id);
        $this->assertCount(1, $fromTask['related']);
        $this->assertSame([], $fromTask['referenced_by']);
        $this->assertFalse($fromTask['can_link']);
        $this->assertSame(['type' => 'page', 'kind' => 'Doc', 'id' => $doc->id, 'key' => '', 'uuid' => $doc->uuid, 'title' => 'Weekly sync notes', 'status' => '', 'origin' => 'manual', 'derived_from' => '', 'can_remove' => false],
            collect($fromTask['related'][0])->except('url')->all());
        $this->assertStringContainsString($doc->uuid, $fromTask['related'][0]['url']);

        // From the doc: Referenced by.
        $fromDoc = $this->links($this->editor, 'page', $doc->uuid);
        $this->assertSame([[(string) $task->key, 'Weekly sync', true]], array_map(fn ($e) => [$e['key'], $e['title'], $e['can_remove']], $fromDoc['referenced_by']));
        $this->assertTrue($fromDoc['can_link']);

        // The existing read tools carry them too.
        $item = $this->content(BrainitiveServer::actingAs($this->viewer)->tool(GetBoardItemTool::class, ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug, 'item_id' => $task->id])->assertOk());
        $this->assertSame([$doc->uuid], array_column($item['related_content'], 'uuid'));
        $this->assertSame([], $item['referenced_by']);
        $this->assertSame([], $item['linked_issues']);

        $page = $this->content(BrainitiveServer::actingAs($this->viewer)->tool(GetPageTool::class, ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'page_uuid' => $doc->uuid])->assertOk());
        $this->assertSame([$task->id], array_column($page['referenced_by'], 'id'));
        $this->assertSame([], $page['related_content']);
    }

    public function test_tickets_and_plans_link_by_key_and_slug(): void
    {
        $ticket = $this->ticket();
        $plan = $this->plan();
        $doc = $this->doc();

        $this->write($this->editor, LinkRelatedContentTool::class, ['source_type' => 'ticket', 'source_id' => 'brai-t1', 'target_type' => 'page', 'target_id' => $doc->uuid])->assertOk();
        $this->write($this->editor, LinkRelatedContentTool::class, ['source_type' => 'plan', 'source_id' => $plan->slug, 'target_type' => 'ticket', 'target_id' => (string) $ticket->id])->assertOk();

        $fromTicket = $this->links($this->editor, 'ticket', 'BRAI-T1');
        $this->assertSame(['page'], array_column($fromTicket['related'], 'type'));
        $this->assertSame([['plan', 'Launch plan']], array_map(fn ($e) => [$e['type'], $e['title']], $fromTicket['referenced_by']));
        $this->assertSame([], $fromTicket['linked_issues']);

        $fromPlan = $this->links($this->editor, 'plan', $plan->id);
        $this->assertSame([['ticket', 'BRAI-T1', 'Printer on fire']], array_map(fn ($e) => [$e['type'], $e['key'], $e['title']], $fromPlan['related']));
    }

    public function test_viewers_read_but_cannot_change_and_outsiders_get_nothing(): void
    {
        $task = $this->item();
        $other = $this->item();
        $doc = $this->doc();
        $this->linkDoc($this->editor, $task, $doc)->assertOk();

        $this->assertCount(1, $this->links($this->viewer, 'item', $task->id)['related']);
        $this->linkDoc($this->viewer, $other, $doc)->assertHasErrors();
        $this->write($this->viewer, UnlinkRelatedContentTool::class, ['source_type' => 'item', 'source_id' => (string) $task->id, 'target_type' => 'page', 'target_id' => $doc->uuid])->assertHasErrors();
        $this->write($this->viewer, LinkIssuesTool::class, ['item' => (string) $task->id, 'link_type' => 'blocks', 'target_item' => (string) $other->id])->assertHasErrors();
        $this->assertSame(1, ContentReference::count());
        $this->assertSame(0, ItemLink::count());

        $this->mcp($this->outsider, ListContentLinksTool::class, ['type' => 'item', 'id' => (string) $task->id])->assertHasErrors(['do not have access']);
        $this->linkDoc($this->outsider, $other, $doc)->assertHasErrors(['do not have access']);
        $this->write($this->outsider, LinkIssuesTool::class, ['item' => (string) $task->id, 'link_type' => 'blocks', 'target_item' => (string) $other->id])->assertHasErrors(['do not have access']);
    }

    public function test_a_private_draft_never_leaks_its_title(): void
    {
        $task = $this->item();
        $draft = $this->draft($this->editor, 'Secret launch notes');

        // The author sees and links their draft.
        $this->linkDoc($this->editor, $task, $draft)->assertOk();
        $this->assertSame([$draft->uuid], array_column($this->links($this->editor, 'item', $task->id)['related'], 'uuid'));

        // Everybody else: not listed, not even as a count — in every read tool.
        $other = $this->projectMember('editor');
        $links = $this->links($other, 'item', $task->id);
        $this->assertSame([], $links['related']);
        $this->assertStringNotContainsString('Secret launch', json_encode($links));
        $item = $this->content(BrainitiveServer::actingAs($other)->tool(GetBoardItemTool::class, ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug, 'item_id' => $task->id])->assertOk());
        $this->assertStringNotContainsString('Secret launch', json_encode($item));

        // Linking to it answers like a missing doc; reading it too.
        $this->linkDoc($other, $this->item(), $draft)->assertHasErrors(['No page']);
        $this->mcp($other, ListContentLinksTool::class, ['type' => 'page', 'id' => $draft->uuid])->assertHasErrors(['No page']);
        $this->assertSame(1, ContentReference::count());
    }

    public function test_unlink_related_content(): void
    {
        $task = $this->item();
        $doc = $this->doc();
        $this->linkDoc($this->editor, $task, $doc)->assertOk();

        // Named the other way round: the link added on the task is still found.
        $removed = $this->content($this->write($this->editor, UnlinkRelatedContentTool::class, ['source_type' => 'page', 'source_id' => $doc->uuid, 'target_type' => 'item', 'target_id' => (string) $task->key])->assertOk());
        $this->assertTrue($removed['removed']);
        $this->assertSame(0, ContentReference::count());

        // Again: a no-op.
        $again = $this->content($this->write($this->editor, UnlinkRelatedContentTool::class, ['source_type' => 'item', 'source_id' => (string) $task->id, 'target_type' => 'page', 'target_id' => $doc->uuid])->assertOk());
        $this->assertFalse($again['removed']);
    }

    public function test_derived_links_are_listed_but_cannot_be_removed_here(): void
    {
        $task = $this->item();
        $plan = $this->plan([['id' => 'r1', 'name' => 'Row', 'level' => 0, 'bars' => [], 'itemId' => $task->id]]);
        $this->assertSame(1, ContentReference::where('origin', 'derived')->count());

        $links = $this->links($this->editor, 'item', $task->key);
        $this->assertSame([['plan', 'derived', 'Plan row', false]], array_map(fn ($e) => [$e['type'], $e['origin'], $e['derived_from'], $e['can_remove']], $links['referenced_by']));

        $this->write($this->editor, UnlinkRelatedContentTool::class, ['source_type' => 'plan', 'source_id' => $plan->slug, 'target_type' => 'item', 'target_id' => (string) $task->id])
            ->assertHasErrors(['plan row']);
        $this->assertSame(1, ContentReference::count());

        // A manual link on top shows as one entry.
        $this->write($this->editor, LinkRelatedContentTool::class, ['source_type' => 'plan', 'source_id' => (string) $plan->id, 'target_type' => 'item', 'target_id' => (string) $task->id])->assertOk();
        $this->assertSame(['manual+derived'], array_column($this->links($this->editor, 'item', $task->id)['referenced_by'], 'origin'));
    }

    public function test_invalid_types_self_links_and_other_projects_are_refused(): void
    {
        $task = $this->item();
        $other = $this->item();

        $this->mcp($this->editor, ListContentLinksTool::class, ['type' => 'snippet', 'id' => '1'])
            ->assertHasErrors(['Unknown type "snippet"', 'item (issue / board card), page (doc or whiteboard), ticket (helpdesk ticket), plan (Gantt plan)']);
        $this->write($this->editor, LinkRelatedContentTool::class, ['source_type' => 'item', 'source_id' => (string) $task->id, 'target_type' => 'task', 'target_id' => '1'])
            ->assertHasErrors(['Unknown target_type "task"']);
        $this->write($this->editor, LinkRelatedContentTool::class, ['source_type' => 'item', 'source_id' => (string) $task->id, 'target_type' => 'item', 'target_id' => (string) $other->id])
            ->assertHasErrors(['link_issues']);
        $doc = $this->doc();
        $this->write($this->editor, LinkRelatedContentTool::class, ['source_type' => 'page', 'source_id' => $doc->uuid, 'target_type' => 'page', 'target_id' => $doc->uuid])
            ->assertHasErrors(['itself']);
        $this->mcp($this->editor, ListContentLinksTool::class, ['type' => 'page', 'id' => 'not-a-uuid'])->assertHasErrors(['No page']);

        // Another project of the workspace, and another workspace: "not found in this project".
        $sibling = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->workspace->owner_id, 'key' => 'SIB']);
        $sibling->users()->attach($this->editor->id, ['role' => 'editor']);
        $siblingDoc = $this->doc(['project_id' => $sibling->id, 'name' => 'Sibling doc']);
        $siblingItem = Item::factory()->create(['board_id' => Board::factory()->create(['project_id' => $sibling->id, 'created_by' => $this->editor->id])->id, 'created_by' => $this->editor->id]);

        $foreignOwner = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $foreignOwner->id]);
        $foreignWorkspace->addUser($foreignOwner, 'admin');
        $foreignWorkspace->addUser($this->editor, 'admin');
        $foreignProject = Project::factory()->create(['workspace_id' => $foreignWorkspace->id, 'created_by' => $foreignOwner->id, 'key' => 'FOR']);
        $foreignDoc = $this->doc(['project_id' => $foreignProject->id, 'name' => 'Foreign doc']);
        $foreignItem = Item::factory()->create(['board_id' => Board::factory()->create(['project_id' => $foreignProject->id, 'created_by' => $foreignOwner->id])->id, 'created_by' => $foreignOwner->id]);

        foreach ([$siblingDoc, $foreignDoc] as $target) {
            $this->linkDoc($this->editor, $task, $target)->assertHasErrors(['in this project']);
        }
        foreach ([$siblingItem, $foreignItem] as $target) {
            $this->write($this->editor, LinkIssuesTool::class, ['item' => (string) $task->id, 'link_type' => 'relates_to', 'target_item' => (string) $target->key])->assertHasErrors(['in this project']);
        }
        $this->assertSame(0, ContentReference::count());
        $this->assertSame(0, ItemLink::count());
    }

    // ─── Linked issues ───────────────────────────────────────────────────────

    public function test_link_issues_is_idempotent_from_both_sides_with_history(): void
    {
        $a = $this->item(['title' => 'API']);
        $b = $this->item(['title' => 'Frontend']);

        $first = $this->content($this->write($this->editor, LinkIssuesTool::class, ['item' => (string) $a->key, 'link_type' => 'blocks', 'target_item' => (string) $b->key])->assertOk());
        $this->assertTrue($first['created']);
        $this->assertSame("{$a->key} blocks {$b->key}", $first['link']);

        // The same link, and its inverse from the other issue ("is blocked by", spelled as in the UI): no-ops.
        $this->assertFalse($this->content($this->write($this->editor, LinkIssuesTool::class, ['item' => (string) $a->id, 'link_type' => 'blocks', 'target_item' => (string) $b->id])->assertOk())['created']);
        $inverse = $this->content($this->write($this->editor, LinkIssuesTool::class, ['item' => (string) $b->id, 'link_type' => 'is blocked by', 'target_item' => (string) $a->id])->assertOk());
        $this->assertFalse($inverse['created']);
        $this->assertSame($first['link_id'], $inverse['link_id']);
        $this->assertSame(1, ItemLink::count());
        $this->assertSame(1, ActivityLog::where('action', 'linked')->count());
        $this->assertSame(['link_type' => 'blocks', 'item' => $b->key], ActivityLog::where('action', 'linked')->first()->metadata);

        // Read from both sides.
        $fromB = $this->links($this->viewer, 'item', $b->id)['linked_issues'];
        $this->assertSame([['is_blocked_by', 'is blocked by', 'incoming', false, $a->id, $a->key, 'API']],
            array_map(fn ($l) => [$l['link_type'], $l['label'], $l['direction'], $l['restricted'], $l['id'], $l['key'], $l['title']], $fromB));
        $item = $this->content(BrainitiveServer::actingAs($this->viewer)->tool(GetBoardItemTool::class, ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug, 'item_id' => $a->id])->assertOk());
        $this->assertSame([['blocks', 'outgoing', $b->key]], array_map(fn ($l) => [$l['link_type'], $l['direction'], $l['key']], $item['linked_issues']));

        // Bad values list the valid ones.
        $this->write($this->editor, LinkIssuesTool::class, ['item' => (string) $a->id, 'link_type' => 'depends_on', 'target_item' => (string) $b->id])
            ->assertHasErrors(['Unknown link_type "depends_on"', 'blocks, is_blocked_by, duplicates, is_duplicated_by, relates_to, clones, is_cloned_by']);
        $this->write($this->editor, LinkIssuesTool::class, ['item' => (string) $a->id, 'link_type' => 'relates_to', 'target_item' => (string) $a->key])->assertHasErrors(['itself']);
        $this->write($this->editor, LinkIssuesTool::class, ['item' => (string) $a->id, 'link_type' => 'relates_to', 'target_item' => 'BRAI-99999'])->assertHasErrors(['No item "BRAI-99999"']);
        $this->assertSame(1, ItemLink::count());
    }

    public function test_the_issue_view_shares_the_link_rules(): void
    {
        $a = $this->item();
        $b = $this->item();
        $this->write($this->editor, LinkIssuesTool::class, ['item' => (string) $a->id, 'link_type' => 'duplicates', 'target_item' => (string) $b->id])->assertOk();

        // The inverse added from the other issue is the same link in the UI too.
        $this->actingAs($this->editor)
            ->post($this->itemRoute('boards.items.links.store', $b), ['target_item_id' => $a->id, 'link_type' => 'is_duplicated_by'])
            ->assertSessionHasErrors(['target_item_id' => 'This link already exists.']);
        $this->actingAs($this->editor)
            ->post($this->itemRoute('boards.items.links.store', $b), ['target_item_id' => $a->id, 'link_type' => 'relates_to'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, ItemLink::count());
    }

    public function test_unlink_issues(): void
    {
        $a = $this->item();
        $b = $this->item();
        $this->write($this->editor, LinkIssuesTool::class, ['item' => (string) $a->id, 'link_type' => 'blocks', 'target_item' => (string) $b->id])->assertOk();
        $this->write($this->editor, LinkIssuesTool::class, ['item' => (string) $a->id, 'link_type' => 'relates_to', 'target_item' => (string) $b->id])->assertOk();

        // One link by type, named from the other side.
        $removed = $this->content($this->write($this->editor, UnlinkIssuesTool::class, ['item' => (string) $b->key, 'target_item' => (string) $a->key, 'link_type' => 'is_blocked_by'])->assertOk());
        $this->assertSame(1, $removed['removed']);
        $this->assertSame(['relates_to'], ItemLink::pluck('link_type')->all());
        $this->assertSame(['link_type' => 'is_blocked_by', 'item' => $a->key], ActivityLog::where('action', 'unlinked')->first()->metadata);

        // Without a type: everything between the two; then a no-op.
        $this->assertSame(1, $this->content($this->write($this->editor, UnlinkIssuesTool::class, ['item' => (string) $a->id, 'target_item' => (string) $b->id])->assertOk())['removed']);
        $this->assertSame(0, ItemLink::count());
        $this->assertSame(0, $this->content($this->write($this->editor, UnlinkIssuesTool::class, ['item' => (string) $a->id, 'target_item' => (string) $b->id])->assertOk())['removed']);

        $this->write($this->viewer, UnlinkIssuesTool::class, ['item' => (string) $a->id, 'target_item' => (string) $b->id])->assertHasErrors();
    }

    public function test_a_linked_issue_the_user_cannot_open_is_restricted(): void
    {
        // An old link into a project the reader isn't a member of (the UI only offers issues of the project now).
        $privateProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->workspace->owner_id, 'key' => 'HR']);
        $secret = Item::factory()->create([
            'board_id' => Board::factory()->create(['project_id' => $privateProject->id, 'created_by' => $this->workspace->owner_id])->id,
            'created_by' => $this->workspace->owner_id,
            'title' => 'Salary review',
        ]);
        $task = $this->item();
        ItemLink::create(['source_item_id' => $secret->id, 'target_item_id' => $task->id, 'link_type' => 'blocks', 'created_by' => $this->workspace->owner_id]);
        $this->assertFalse($this->viewer->can('view', $secret));

        $links = $this->links($this->viewer, 'item', $task->id);
        $this->assertSame([['is_blocked_by', true, 0, '', '', '']], array_map(fn ($l) => [$l['link_type'], $l['restricted'], $l['id'], $l['key'], $l['title'], $l['url']], $links['linked_issues']));
        $this->assertStringNotContainsString('Salary', json_encode($links));
        $this->assertStringNotContainsString('HR-', json_encode($links));
    }
}
