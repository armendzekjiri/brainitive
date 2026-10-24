<?php

namespace Tests\Feature\References;

use App\Models\Board;
use App\Models\ContentReference;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Mcp\ResourceLocator;
use App\Support\Projects\ProjectTransfer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Linked content and backlinks (BRAI-71): manual references, derived ones from plan rows and
 * whiteboard cards, visibility, pruning and canonical URLs.
 */
class ContentReferencesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private Board $board;

    private Helpdesk $helpdesk;

    private User $owner;

    private User $editor;

    private User $otherEditor;

    private User $viewer;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->addUser($this->owner, 'admin');
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id, 'key' => 'WEB']);
        $this->board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->owner->id]);

        $this->editor = $this->member('editor');
        $this->otherEditor = $this->member('editor');
        $this->viewer = $this->member('viewer');
        $this->outsider = User::factory()->create();
    }

    private function member(string $role, ?Project $project = null): User
    {
        $project ??= $this->project;
        $user = User::factory()->create();
        $project->workspace->addUser($user, 'member');
        $project->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function item(array $attributes = []): Item
    {
        return Item::factory()->create($attributes + ['board_id' => $this->board->id, 'created_by' => $this->editor->id]);
    }

    private function doc(array $attributes = []): Page
    {
        return Page::factory()->published()->create($attributes + [
            'project_id' => $this->project->id,
            'created_by' => $this->editor->id,
            'name' => 'Runbook',
            'content' => ['type' => 'lexical', 'html' => '<p>Text</p>'],
        ]);
    }

    private function whiteboard(array $elements, array $attributes = []): Page
    {
        return Page::factory()->published()->create($attributes + [
            'project_id' => $this->project->id,
            'created_by' => $this->editor->id,
            'name' => 'Sprint board',
            'content' => ['type' => 'excalidraw', 'elements' => $elements, 'appState' => []],
        ]);
    }

    private function card(int $itemId, bool $deleted = false): array
    {
        return ['id' => 'c'.$itemId, 'type' => 'rectangle', 'isDeleted' => $deleted, 'customData' => ['brainitive' => 'issue', 'itemId' => $itemId]];
    }

    private function plan(array $rows = [], array $attributes = []): Plan
    {
        return Plan::factory()->create($attributes + [
            'project_id' => $this->project->id,
            'created_by' => $this->editor->id,
            'data' => ['rows' => $rows, 'dependencies' => []],
        ]);
    }

    private function row(string $id, ?int $itemId = null): array
    {
        return array_filter(['id' => $id, 'name' => 'Row '.$id, 'level' => 0, 'bars' => [], 'itemId' => $itemId], fn ($v) => $v !== null);
    }

    private function ticket(array $attributes = []): Ticket
    {
        static $n = 0;
        $n++;

        return Ticket::create($attributes + [
            'helpdesk_id' => $this->helpdesk->id,
            'column_id' => $this->helpdesk->columns()->first()->id,
            'key' => 'WEB-T'.$n,
            'subject' => 'Printer on fire '.$n,
        ]);
    }

    private function typeOf(Model $model): string
    {
        return ContentReference::typeOf($model);
    }

    private function index(User $user, Model $model, ?Project $project = null): TestResponse
    {
        $project ??= $this->project;

        return $this->actingAs($user)->getJson(route('references.index', [$project->workspace->slug, $project->slug]).'?type='.$this->typeOf($model).'&id='.$model->getKey());
    }

    private function link(User $user, Model $source, Model $target, ?Project $project = null): TestResponse
    {
        $project ??= $this->project;

        return $this->actingAs($user)->postJson(route('references.store', [$project->workspace->slug, $project->slug]), [
            'source_type' => $this->typeOf($source), 'source_id' => $source->getKey(),
            'target_type' => $this->typeOf($target), 'target_id' => $target->getKey(),
        ]);
    }

    private function unlink(User $user, int $referenceId): TestResponse
    {
        return $this->actingAs($user)->deleteJson(route('references.destroy', [$this->workspace->slug, $this->project->slug, $referenceId]));
    }

    /** @return list<string> */
    private function keys(TestResponse $response, string $bucket): array
    {
        return array_column($response->assertOk()->json($bucket), 'key');
    }

    public function test_linking_an_issue_to_a_doc_shows_a_backlink_on_the_doc(): void
    {
        $item = $this->item(['title' => 'Checkout bug']);
        $doc = $this->doc(['name' => 'Payments runbook']);

        $this->link($this->editor, $item, $doc)->assertCreated()->assertJson(['created' => true]);

        $related = $this->index($this->editor, $item)->json('related');
        $this->assertSame(["page:{$doc->id}"], array_column($related, 'key'));
        $this->assertSame('Payments runbook', $related[0]['title']);
        $this->assertSame('Doc', $related[0]['label']);
        $this->assertTrue($related[0]['manual']);
        $this->assertTrue($related[0]['can_remove']);

        $backlinks = $this->index($this->viewer, $doc)->json('referenced_by');
        $this->assertSame(["item:{$item->id}"], array_column($backlinks, 'key'));
        $this->assertSame('Checkout bug', $backlinks[0]['title']);
        $this->assertSame($item->key, $backlinks[0]['subtitle']);
        $this->assertFalse($backlinks[0]['can_remove'], 'a viewer can’t remove');
        $this->assertSame([], $this->index($this->viewer, $doc)->json('related'));
    }

    public function test_linking_twice_does_not_duplicate(): void
    {
        $item = $this->item();
        $doc = $this->doc();

        $first = $this->link($this->editor, $item, $doc)->assertCreated()->json('id');
        $this->link($this->otherEditor, $item, $doc)->assertOk()->assertJson(['id' => $first, 'created' => false]);

        $this->assertSame(1, ContentReference::count());
        $this->assertCount(1, $this->index($this->editor, $doc)->json('referenced_by'));
    }

    public function test_self_links_issue_to_issue_folders_and_unsupported_types_fail(): void
    {
        $item = $this->item();
        $other = $this->item();
        $doc = $this->doc();
        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);

        $this->link($this->editor, $doc, $doc)->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->link($this->editor, $item, $other)->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->link($this->editor, $item, $folder)->assertUnprocessable()->assertJsonValidationErrors('target_id');

        $url = route('references.store', [$this->workspace->slug, $this->project->slug]);
        $this->actingAs($this->editor)->postJson($url, ['source_type' => 'item', 'source_id' => $item->id, 'target_type' => 'bookmark', 'target_id' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('target_type');
        $this->actingAs($this->editor)->postJson($url, ['source_type' => 'App\\Models\\Item', 'source_id' => $item->id, 'target_type' => 'page', 'target_id' => $doc->id])
            ->assertUnprocessable()->assertJsonValidationErrors('source_type');
        $this->actingAs($this->editor)->getJson(route('references.index', [$this->workspace->slug, $this->project->slug]).'?type=snippet&id=1')
            ->assertUnprocessable();

        $this->assertSame(0, ContentReference::count());
    }

    public function test_foreign_project_ids_fail(): void
    {
        $item = $this->item();
        // a project of the same workspace the editor can open, and one of another workspace
        $sibling = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id, 'key' => 'SIB']);
        $sibling->users()->attach($this->editor->id, ['role' => 'editor']);
        $siblingDoc = $this->doc(['project_id' => $sibling->id]);
        $foreignWorkspace = Workspace::factory()->create();
        $foreign = Project::factory()->create(['workspace_id' => $foreignWorkspace->id, 'key' => 'FOR']);
        $foreignPlan = $this->plan([], ['project_id' => $foreign->id]);

        $this->link($this->editor, $item, $siblingDoc)->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->link($this->editor, $item, $foreignPlan)->assertUnprocessable()->assertJsonValidationErrors('target_id');
        // the source must be in the URL's project too
        $this->link($this->editor, $siblingDoc, $item)->assertUnprocessable()->assertJsonValidationErrors('source_id');
        $this->index($this->editor, $siblingDoc)->assertNotFound();

        $this->assertSame(0, ContentReference::count());
    }

    public function test_only_editors_link_and_outsiders_see_nothing(): void
    {
        $item = $this->item();
        $doc = $this->doc();

        $this->link($this->viewer, $item, $doc)->assertForbidden();
        $this->link($this->outsider, $item, $doc)->assertUnprocessable();
        $this->index($this->outsider, $item)->assertNotFound();
        $this->actingAs($this->viewer)->getJson(route('references.candidates', [$this->workspace->slug, $this->project->slug]).'?type=item&id='.$item->id)->assertForbidden();

        $this->link($this->editor, $item, $doc)->assertCreated();
        $this->assertFalse($this->index($this->viewer, $item)->json('can_link'));
        $this->assertTrue($this->index($this->editor, $item)->json('can_link'));
        $this->index($this->outsider, $doc)->assertNotFound();
    }

    public function test_a_private_draft_is_invisible_to_other_members_everywhere(): void
    {
        $item = $this->item();
        $draft = Page::factory()->create([
            'project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Secret launch notes',
            'content' => ['type' => 'lexical', 'html' => '<p>x</p>'],
        ]);
        $this->assertTrue($draft->isDraft());

        $this->link($this->editor, $item, $draft)->assertCreated();
        $this->link($this->editor, $draft, $item)->assertCreated();

        // the author and admins see it
        $this->assertSame(["page:{$draft->id}"], $this->keys($this->index($this->editor, $item), 'related'));
        $this->assertSame(["page:{$draft->id}"], $this->keys($this->index($this->owner, $item), 'referenced_by'));

        // another editor: no title, no entry, no count, no search hit
        $response = $this->index($this->otherEditor, $item);
        $this->assertSame([], $response->json('related'));
        $this->assertSame([], $response->json('referenced_by'));
        $this->assertStringNotContainsString('Secret launch notes', $response->getContent());
        $this->index($this->otherEditor, $draft)->assertNotFound();

        $search = $this->actingAs($this->otherEditor)->getJson(route('references.candidates', [$this->workspace->slug, $this->project->slug]).'?type=item&id='.$item->id.'&q=Secret')->assertOk();
        $this->assertSame([], $search->json('results'));
        $this->assertStringNotContainsString('Secret launch notes', $search->getContent());
        $authorSearch = $this->actingAs($this->editor)->getJson(route('references.candidates', [$this->workspace->slug, $this->project->slug]).'?type=item&id='.$item->id.'&q=secret')->assertOk();
        $this->assertSame([['page:'.$draft->id, true]], array_map(fn ($r) => [$r['key'], $r['linked']], $authorSearch->json('results')));

        // linking to it answers like a missing page (no existence leak)
        $other = $this->item();
        $this->link($this->otherEditor, $other, $draft)->assertUnprocessable()
            ->assertJsonValidationErrors(['target_id' => 'This content wasn’t found in the project.']);

        // published: visible; unpublished again: hidden again
        $draft->publish($this->editor);
        $this->assertSame(["page:{$draft->id}"], $this->keys($this->index($this->otherEditor, $item), 'related'));
        $draft->unpublish($this->editor);
        $this->assertSame([], $this->keys($this->index($this->otherEditor, $item), 'related'));
    }

    public function test_permission_changes_apply_immediately(): void
    {
        $item = $this->item();
        $doc = $this->doc();
        $this->link($this->editor, $item, $doc)->assertCreated();

        $this->project->users()->updateExistingPivot($this->editor->id, ['role' => 'viewer']);
        $this->link($this->editor, $item, $this->plan())->assertForbidden();
        $this->assertFalse($this->index($this->editor, $item)->json('related.0.can_remove'));

        $this->project->users()->detach($this->editor->id);
        $this->index($this->editor, $item)->assertNotFound();
        $this->index($this->editor, $doc)->assertNotFound();
    }

    public function test_plan_rows_and_whiteboard_cards_are_derived_references_shown_once(): void
    {
        $item = $this->item(['title' => 'Checkout bug']);
        $plan = $this->plan([$this->row('r1', $item->id), $this->row('r2', $item->id), $this->row('r3')]);
        $board = $this->whiteboard([$this->card($item->id), $this->card($item->id), ['id' => 'x', 'type' => 'text']]);

        $this->assertSame(2, ContentReference::where('origin', 'derived')->count());

        $backlinks = collect($this->index($this->viewer, $item)->json('referenced_by'))->keyBy('key');
        $this->assertSame(["plan:{$plan->id}", "page:{$board->id}"], $backlinks->keys()->all());
        $this->assertTrue($backlinks["plan:{$plan->id}"]['derived']);
        $this->assertSame('Plan row', $backlinks["plan:{$plan->id}"]['derived_label']);
        $this->assertSame('Whiteboard card', $backlinks["page:{$board->id}"]['derived_label']);
        $this->assertSame('Whiteboard', $backlinks["page:{$board->id}"]['label']);
        $this->assertFalse($backlinks["plan:{$plan->id}"]['can_remove']);

        // the plan shows the issue under Related content
        $this->assertSame(["item:{$item->id}"], $this->keys($this->index($this->viewer, $plan), 'related'));

        // explicit + derived for the same pair: still one entry
        $this->link($this->editor, $plan, $item)->assertCreated();
        $entries = $this->index($this->editor, $item)->json('referenced_by');
        $this->assertCount(2, $entries);
        $planEntry = collect($entries)->firstWhere('key', "plan:{$plan->id}");
        $this->assertTrue($planEntry['manual'] && $planEntry['derived']);
        $this->assertTrue($planEntry['can_remove']);
    }

    public function test_removing_the_row_link_or_the_card_removes_the_derived_backlink(): void
    {
        $item = $this->item();
        $plan = $this->plan([$this->row('r1', $item->id)]);
        $board = $this->whiteboard([$this->card($item->id)]);
        $this->link($this->editor, $plan, $item)->assertCreated();

        $plan->saveDocument($this->editor, ['rows' => [$this->row('r1')], 'dependencies' => []], []);
        $board->saveWhiteboard($this->editor, ['type' => 'excalidraw', 'elements' => [$this->card($item->id, deleted: true)], 'appState' => []]);

        $this->assertSame(0, ContentReference::where('origin', 'derived')->count());
        // the manual link stays, the whiteboard backlink is gone
        $entries = $this->index($this->editor, $item)->json('referenced_by');
        $this->assertSame(["plan:{$plan->id}"], array_column($entries, 'key'));
        $this->assertFalse($entries[0]['derived']);
    }

    public function test_derived_references_ignore_issues_of_other_projects_and_follow_plan_restores(): void
    {
        $item = $this->item();
        $foreignProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'key' => 'OTH']);
        $foreignBoard = Board::factory()->create(['project_id' => $foreignProject->id]);
        $foreignItem = Item::factory()->create(['board_id' => $foreignBoard->id]);

        $plan = $this->plan([$this->row('r1', $item->id), $this->row('r2', $foreignItem->id)]);
        $this->whiteboard([$this->card($foreignItem->id)]);
        $this->assertSame([$item->id], ContentReference::where('origin', 'derived')->pluck('target_id')->all());

        $version = $plan->recordVersion($this->editor);
        $plan->saveDocument($this->editor, ['rows' => [], 'dependencies' => []], []);
        $this->assertSame(0, ContentReference::count());
        $plan->restoreVersion($version, $this->editor);
        $this->assertSame([$item->id], ContentReference::where('origin', 'derived')->pluck('target_id')->all());
    }

    public function test_derived_references_cannot_be_removed_here(): void
    {
        $item = $this->item();
        $this->plan([$this->row('r1', $item->id)]);
        $reference = ContentReference::where('origin', 'derived')->firstOrFail();

        $this->unlink($this->owner, $reference->id)->assertUnprocessable();
        $this->assertModelExists($reference);
    }

    public function test_removing_a_reference_updates_both_sides(): void
    {
        $item = $this->item();
        $doc = $this->doc();
        $id = $this->link($this->editor, $item, $doc)->json('id');

        $this->unlink($this->viewer, $id)->assertForbidden();
        $this->unlink($this->outsider, $id)->assertNotFound();

        // removable from the doc's "Referenced by" too (edit rights on the issue)
        $this->assertTrue($this->index($this->otherEditor, $doc)->json('referenced_by.0.can_remove'));
        $this->unlink($this->otherEditor, $id)->assertOk();

        $this->assertSame([], $this->index($this->editor, $item)->json('related'));
        $this->assertSame([], $this->index($this->editor, $doc)->json('referenced_by'));
    }

    public function test_a_reference_of_another_project_cannot_be_removed_through_this_one(): void
    {
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'key' => 'OTH']);
        $reference = ContentReference::create([
            'project_id' => $other->id, 'source_type' => 'plan', 'source_id' => 1, 'target_type' => 'page', 'target_id' => 2, 'origin' => 'manual',
        ]);

        $this->unlink($this->owner, $reference->id)->assertNotFound();
        $this->assertModelExists($reference);
    }

    public function test_soft_deleted_endpoints_are_hidden_and_come_back_on_restore(): void
    {
        $doc = $this->doc();
        $item = $this->item();
        $ticket = $this->ticket();
        $plan = $this->plan();
        foreach ([$item, $ticket, $plan] as $source) {
            $this->link($this->editor, $source, $doc)->assertCreated();
        }
        $this->assertCount(3, $this->index($this->editor, $doc)->json('referenced_by'));

        $item->delete();
        $ticket->delete();
        $plan->delete();
        $this->assertSame([], $this->index($this->editor, $doc)->json('referenced_by'));
        $this->assertSame(3, ContentReference::count(), 'soft deletes keep the rows');

        $item->restore();
        $ticket->restore();
        $plan->restore();
        $this->assertCount(3, $this->index($this->editor, $doc)->json('referenced_by'));

        // a trashed board hides its issues the same way
        $this->board->delete();
        $this->assertCount(2, $this->index($this->editor, $doc)->json('referenced_by'));
    }

    public function test_archived_pages_stay_visible_with_their_status(): void
    {
        $item = $this->item();
        $doc = $this->doc();
        $this->link($this->editor, $item, $doc)->assertCreated();

        $this->actingAs($this->editor)->post(route('pages.archive', [$this->workspace->slug, $this->project->slug, $doc->uuid]))->assertRedirect();

        $this->assertSame('archived', $this->index($this->otherEditor, $item)->json('related.0.status'));
    }

    public function test_deleting_a_page_prunes_its_references_and_its_subpages_references(): void
    {
        $item = $this->item();
        $parent = $this->doc(['name' => 'Parent']);
        $child = $this->doc(['name' => 'Child', 'parent_id' => $parent->id]);
        $keep = $this->doc(['name' => 'Keep']);
        foreach ([$parent, $child, $keep] as $doc) {
            $this->link($this->editor, $item, $doc)->assertCreated();
        }
        $this->link($this->editor, $child, $item)->assertCreated();

        $this->actingAs($this->owner)->delete(route('pages.destroy', [$this->workspace->slug, $this->project->slug, $parent->uuid]))->assertRedirect();

        $this->assertModelMissing($child);
        $this->assertSame(["page:{$keep->id}"], $this->keys($this->index($this->editor, $item), 'related'));
        $this->assertSame(1, ContentReference::count());
    }

    public function test_force_deleting_an_issue_or_plan_prunes_its_references(): void
    {
        $doc = $this->doc();
        $item = $this->item();
        $plan = $this->plan();
        $this->link($this->editor, $item, $doc)->assertCreated();
        $this->link($this->editor, $plan, $doc)->assertCreated();

        $item->forceDelete();
        $this->assertSame(1, ContentReference::count());
        $plan->forceDelete();
        $this->assertSame(0, ContentReference::count());
    }

    public function test_moving_an_issue_to_another_project_drops_its_references(): void
    {
        $item = $this->item();
        $subtask = $this->item(['parent_id' => $item->id]);
        $doc = $this->doc();
        $this->link($this->editor, $item, $doc)->assertCreated();
        $this->link($this->editor, $subtask, $doc)->assertCreated();
        $this->plan([$this->row('r1', $item->id)]);

        $target = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id, 'key' => 'TGT']);
        $targetBoard = Board::factory()->create(['project_id' => $target->id, 'created_by' => $this->owner->id]);

        $this->actingAs($this->owner)->patch(route('boards.items.move-to-board', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]), [
            'target_board_id' => $targetBoard->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(0, ContentReference::count());
        $this->assertSame([], $this->index($this->editor, $doc)->json('referenced_by'));
    }

    public function test_moving_the_project_to_another_workspace_keeps_references_and_updates_urls(): void
    {
        $item = $this->item();
        $doc = $this->doc();
        $this->link($this->editor, $item, $doc)->assertCreated();

        $target = Workspace::factory()->create(['owner_id' => $this->owner->id, 'slug' => 'new-home']);
        $target->addUser($this->owner, 'admin');
        $target->addUser($this->editor, 'member');
        $moved = ProjectTransfer::move($this->project, $target);

        $entry = $this->index($this->editor, $item, $moved)->json('related.0');
        $this->assertSame("page:{$doc->id}", $entry['key']);
        $this->assertStringContainsString('/workspaces/new-home/projects/', $entry['url']);
        // a member who wasn't in the target workspace lost the project, and with it the backlinks
        $this->index($this->otherEditor, $doc, $moved)->assertNotFound();
    }

    public function test_urls_are_canonical(): void
    {
        $doc = $this->doc();
        $whiteboard = $this->whiteboard([]);
        $item = $this->item();
        $ticket = $this->ticket();
        $plan = $this->plan();
        foreach ([$doc, $whiteboard, $item, $ticket] as $target) {
            $this->link($this->editor, $plan, $target)->assertCreated();
        }
        $this->link($this->editor, $doc, $plan)->assertCreated();

        $entries = collect($this->index($this->editor, $plan)->json('related'))->keyBy('key');
        foreach ([$doc, $whiteboard, $item, $ticket] as $model) {
            $key = $this->typeOf($model).':'.$model->getKey();
            $this->assertSame(ResourceLocator::locate($model->fresh())['url'], $entries[$key]['url'], $key);
        }
        $this->assertSame(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]).'#item-'.$item->id, $entries["item:{$item->id}"]['url']);
        $this->assertSame(route('tickets.show', [$this->workspace->slug, $this->project->slug, 'support', $ticket->id]), $entries["ticket:{$ticket->id}"]['url']);
        $this->assertSame('Ticket', $entries["ticket:{$ticket->id}"]['label']);
        $this->assertSame(ResourceLocator::locate($plan->fresh())['url'], $this->index($this->editor, $doc)->json('related.0.url'));
    }

    public function test_listing_runs_a_constant_number_of_queries(): void
    {
        $doc = $this->doc();
        $makeSources = function (int $n) use ($doc) {
            for ($i = 0; $i < $n; $i++) {
                $this->link($this->editor, $this->item(), $doc)->assertCreated();
                $this->link($this->editor, $this->plan(), $doc)->assertCreated();
                $this->link($this->editor, $this->ticket(), $doc)->assertCreated();
                $this->link($this->editor, $doc, $this->doc(['name' => 'Doc '.$i]))->assertCreated();
            }
        };
        $count = function () use ($doc): int {
            $this->app['auth']->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->index($this->otherEditor, $doc);
            DB::disableQueryLog();
            $response->assertOk();

            return count(DB::getQueryLog());
        };

        $makeSources(1);
        $few = $count();
        $this->assertCount(3, $this->index($this->otherEditor, $doc)->json('referenced_by'));

        $makeSources(5);
        $many = $count();
        $this->assertCount(18, $this->index($this->otherEditor, $doc)->json('referenced_by'));
        $this->assertCount(6, $this->index($this->otherEditor, $doc)->json('related'));

        $this->assertSame($few, $many, "query count grew from {$few} to {$many}");
    }

    public function test_the_picker_searches_the_project_and_marks_existing_links(): void
    {
        $item = $this->item(['title' => 'Checkout bug']);
        $this->item(['title' => 'Another checkout issue']);
        $doc = $this->doc(['name' => 'Checkout runbook']);
        $plan = $this->plan([], ['name' => 'Checkout rollout']);
        $ticket = $this->ticket(['subject' => 'Checkout fails for me']);
        $this->link($this->editor, $item, $doc)->assertCreated();

        $results = $this->actingAs($this->editor)
            ->getJson(route('references.candidates', [$this->workspace->slug, $this->project->slug]).'?type=item&id='.$item->id.'&q=CHECKOUT')
            ->assertOk()->json('results');

        $this->assertEqualsCanonicalizing(["page:{$doc->id}", "plan:{$plan->id}", "ticket:{$ticket->id}"], array_column($results, 'key'));
        $this->assertTrue(collect($results)->firstWhere('key', "page:{$doc->id}")['linked']);
        $this->assertFalse(collect($results)->firstWhere('key', "plan:{$plan->id}")['linked']);

        // from a doc: issues are offered (by key too), the doc itself isn't
        $byKey = $this->actingAs($this->editor)
            ->getJson(route('references.candidates', [$this->workspace->slug, $this->project->slug]).'?type=page&id='.$doc->id.'&q='.strtolower($item->key))
            ->assertOk()->json('results');
        $this->assertContains("item:{$item->id}", array_column($byKey, 'key'));
        $this->assertNotContains("page:{$doc->id}", array_column($byKey, 'key'));
    }

    public function test_tickets_link_both_ways(): void
    {
        $ticket = $this->ticket(['subject' => 'Refund request']);
        $plan = $this->plan([], ['name' => 'Refund process']);

        $this->link($this->editor, $ticket, $plan)->assertCreated();

        $this->assertSame(["plan:{$plan->id}"], $this->keys($this->index($this->viewer, $ticket), 'related'));
        $backlink = $this->index($this->viewer, $plan)->json('referenced_by.0');
        $this->assertSame('Refund request', $backlink['title']);
        $this->assertSame($ticket->key, $backlink['subtitle']);
    }

    public function test_deleting_the_project_removes_its_references(): void
    {
        $this->link($this->editor, $this->item(), $this->doc())->assertCreated();
        $this->project->forceDelete();

        $this->assertSame(0, ContentReference::count());
    }
}
