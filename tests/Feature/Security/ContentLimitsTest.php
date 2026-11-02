<?php

namespace Tests\Feature\Security;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\CreateSnippetTool;
use App\Models\Helpdesk;
use App\Models\Page;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Models\TicketContact;
use App\Models\User;
use App\Support\Validation\ContentLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Codex web review D / J: one set of generous maximum lengths and shapes (ContentLimits) for the web, the App API,
 * MCP and sync — and task descriptions accept only the editor's document formats, never arbitrary JSON.
 */
class ContentLimitsTest extends TestCase
{
    use ActsAsAppClient;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function list(): TaskList
    {
        return TaskList::create(['project_id' => $this->project->id, 'name' => 'List', 'slug' => 'list', 'created_by' => $this->editor->id]);
    }

    private function storeTask(TaskList $list, mixed $description)
    {
        return $this->actingAs($this->editor)->post(route('task-lists.items.store', [$this->workspace->slug, $this->project->slug, $list->slug]), [
            'title' => 'Task', 'description' => $description,
        ]);
    }

    public function test_task_descriptions_accept_only_editor_documents(): void
    {
        $list = $this->list();

        $this->storeTask($list, ['custom' => str_repeat('x', 1000)])->assertSessionHasErrors('description');
        $this->storeTask($list, ['type' => 'lexical', 'html' => str_repeat('x', ContentLimits::BODY + 1)])->assertSessionHasErrors('description');
        $this->storeTask($list, ['type' => 'lexical', 'html' => '<p>x</p>', 'extra' => 1])->assertSessionHasErrors('description');
        $deep = ['type' => 'text', 'text' => 'x'];
        for ($i = 0; $i < 60; $i++) {
            $deep = ['type' => 'blockquote', 'content' => [$deep]];
        }
        $this->storeTask($list, ['type' => 'doc', 'content' => [$deep]])->assertSessionHasErrors('description');
        $this->storeTask($list, ['type' => 'doc', 'content' => [['type' => 'script', 'text' => 'x']]])->assertSessionHasErrors('description');
        $this->assertSame(0, $list->items()->count());

        $this->storeTask($list, ['type' => 'lexical', 'html' => '<p>Notes</p>'])->assertSessionHasNoErrors();
        $this->storeTask($list, ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi', 'marks' => [['type' => 'bold']]]]]]])->assertSessionHasNoErrors();
        $this->assertSame(2, $list->items()->count());
    }

    public function test_bodies_and_comments_have_one_maximum_everywhere(): void
    {
        // web issue description
        $this->actingAs($this->editor)->post(route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]), [
            'title' => 'Big', 'column_id' => $this->column('done'), 'description' => str_repeat('x', ContentLimits::BODY + 1),
        ])->assertSessionHasErrors('description');

        // web comment
        $item = $this->item();
        $this->actingAs($this->editor)->post(route('boards.items.comments.store', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]), [
            'body' => str_repeat('x', ContentLimits::COMMENT + 1),
        ])->assertSessionHasErrors('body');
        // App API comment: the same limit (not the old 20 000)
        $this->postJson('/api/v1/items/'.$item->id.'/comments', ['body' => str_repeat('y', 30000)], $this->appHeaders($this->appToken($this->editor)))
            ->assertCreated();

        // web snippet + MCP snippet
        $collection = SnippetCollection::create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'name' => 'C']);
        $this->workspace->users()->updateExistingPivot($this->editor->id, ['role' => 'editor']);
        $this->actingAs($this->editor)->post(route('snippets.snippets.store', [$this->workspace->slug, $collection->slug]), [
            'title' => 'Big', 'content' => str_repeat('x', ContentLimits::BODY + 1),
        ])->assertSessionHasErrors('content');
        BrainitiveServer::actingAs($this->editor)->tool(CreateSnippetTool::class, [
            'workspace' => $this->workspace->slug, 'collection' => $collection->slug, 'title' => 'Big', 'content' => str_repeat('x', ContentLimits::BODY + 1),
        ])->assertHasErrors();
        $this->assertSame(0, $collection->snippets()->count());

        // web page draft
        $page = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->actingAs($this->editor)->putJson(route('pages.draft.save', [$this->workspace->slug, $this->project->slug, $page->uuid]), [
            'content' => ['type' => 'lexical', 'html' => str_repeat('x', ContentLimits::PAGE_BODY)],
        ])->assertUnprocessable()->assertJsonValidationErrors('content');
    }

    public function test_helpdesk_inputs_are_bounded(): void
    {
        $admin = User::find($this->workspace->owner_id);
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $admin->id]);
        $fieldUrl = route('helpdesks.fields.store', [$this->workspace->slug, $this->project->slug, $helpdesk->slug]);

        $this->actingAs($admin)->postJson($fieldUrl, ['label' => 'Field', 'type' => 'select', 'options' => [str_repeat('x', ContentLimits::FIELD_OPTION + 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('options.0');
        $this->actingAs($admin)->postJson($fieldUrl, ['label' => 'Field', 'type' => 'select', 'options' => array_map('strval', range(1, ContentLimits::FIELD_OPTIONS + 1))])
            ->assertUnprocessable()->assertJsonValidationErrors('options');
        $this->actingAs($admin)->postJson($fieldUrl, ['label' => 'Field', 'type' => 'select', 'options' => ['A', 'B']])->assertSessionHasNoErrors();

        $this->actingAs($admin)->postJson(route('helpdesks.columns.reorder', [$this->workspace->slug, $this->project->slug, $helpdesk->slug]), [
            'columns' => array_fill(0, ContentLimits::REORDER_IDS + 1, ['id' => 1, 'position' => 0]),
        ])->assertUnprocessable();

        $contact = TicketContact::create(['email' => 'c@example.com', 'uuid' => (string) str()->uuid()]);
        $ticket = $helpdesk->tickets()->create([
            'subject' => 'Help', 'column_id' => $helpdesk->columns()->first()->id, 'ticket_contact_id' => $contact->id,
            'key' => $this->project->getNextTicketKey(), 'position' => 1,
        ]);
        $this->actingAs($admin)->post(route('tickets.replies.store', [$this->workspace->slug, $this->project->slug, $helpdesk->slug, $ticket->id]), [
            'body' => str_repeat('x', ContentLimits::TICKET_REPLY + 1),
        ])->assertSessionHasErrors('body');
        $this->actingAs($admin)->patch(route('tickets.update', [$this->workspace->slug, $this->project->slug, $helpdesk->slug, $ticket->id]), [
            'description' => str_repeat('x', ContentLimits::TICKET_BODY + 1),
        ])->assertSessionHasErrors('description');
    }
}
