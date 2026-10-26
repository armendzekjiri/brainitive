<?php

namespace Tests\Feature\References;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\ContentReference;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ClientPortal\DeliverableHtml;
use App\Support\References\SmartLinks;
use App\Support\SafeHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Smart links (BRAI-143): the batched live-data endpoint (visibility, no title leaks, batching),
 * the picker search, derived references from doc content and issue descriptions, and how the
 * saved anchor degrades outside the editor.
 */
class SmartLinksTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private Project $otherProject;

    private Board $board;

    private Helpdesk $helpdesk;

    private User $owner;

    private User $editor;

    private User $member;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->owner = User::factory()->create(['name' => 'Olivia Owner']);
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->addUser($this->owner, 'admin');
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id, 'key' => 'WEB', 'name' => 'Website']);
        $this->otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id, 'key' => 'OPS', 'name' => 'Ops']);
        $this->board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->owner->id]);

        $this->editor = $this->memberOf($this->project, 'editor', 'Eddie Editor');
        // a member of the workspace who can open only the other project
        $this->member = $this->memberOf($this->otherProject, 'editor', 'Mia Member');
        $this->outsider = User::factory()->create();
    }

    private function memberOf(Project $project, string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $project->workspace->addUser($user, 'member');
        $project->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function item(array $attributes = [], ?Board $board = null): Item
    {
        return Item::factory()->create($attributes + ['board_id' => ($board ?? $this->board)->id, 'created_by' => $this->editor->id]);
    }

    private function doc(string $html = '<p>Text</p>', array $attributes = []): Page
    {
        return Page::factory()->published()->create($attributes + [
            'project_id' => $this->project->id,
            'created_by' => $this->editor->id,
            'name' => 'Runbook',
            'content' => ['type' => 'lexical', 'html' => $html],
        ]);
    }

    private function draftDoc(User $author, string $name): Page
    {
        return Page::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $author->id,
            'name' => $name,
            'status' => 'draft',
            'content' => ['type' => 'lexical', 'html' => '<p>secret</p>'],
        ]);
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::create($attributes + [
            'helpdesk_id' => $this->helpdesk->id,
            'column_id' => $this->helpdesk->columns()->orderBy('position')->first()->id,
            'key' => 'WEB-T1',
            'subject' => 'Printer on fire',
        ]);
    }

    private function plan(array $attributes = []): Plan
    {
        return Plan::factory()->create($attributes + [
            'project_id' => $this->project->id,
            'created_by' => $this->editor->id,
            'name' => 'Q4 roadmap',
            'data' => ['rows' => [], 'dependencies' => []],
        ]);
    }

    /** @param list<string> $refs */
    private function links(User $user, array $refs, ?Workspace $workspace = null): TestResponse
    {
        $query = http_build_query(['refs' => $refs]);

        return $this->actingAs($user)->getJson(route('smart-links.index', ($workspace ?? $this->workspace)->slug).'?'.$query);
    }

    private function anchor(string $ref, string $text = 'link', string $view = 'inline'): string
    {
        return '<a href="https://app.test/x" data-smart-link="'.$ref.'" data-smart-link-view="'.$view.'">'.$text.'</a>';
    }

    /** @return list<string> */
    private function backlinks(User $user, string $type, int $id): array
    {
        $response = $this->actingAs($user)
            ->getJson(route('references.index', [$this->workspace->slug, $this->project->slug]).'?type='.$type.'&id='.$id)
            ->assertOk();

        return array_column($response->json('referenced_by'), 'key');
    }

    // ── Endpoint ────────────────────────────────────────────────────────────────────

    public function test_an_issue_answers_with_its_live_data(): void
    {
        $priority = ItemPriority::create(['project_id' => $this->project->id, 'name' => 'High', 'slug' => 'high', 'color' => '#f00', 'order' => 1]);
        $item = $this->item(['title' => 'Fix login', 'due_date' => '2026-11-05', 'assigned_to' => $this->editor->id, 'priority_id' => $priority->id]);
        $ref = "item:{$item->id}";

        $link = $this->links($this->editor, [$ref])->assertOk()->json("links.{$ref}");

        $this->assertSame('ok', $link['state']);
        $this->assertSame($ref, $link['ref']);
        $this->assertSame('item', $link['type']);
        $this->assertSame($item->key, $link['key']);
        $this->assertSame('Fix login', $link['title']);
        $this->assertSame('todo', $link['status']['category'], 'the first column is To Do');
        $this->assertSame('2026-11-05', $link['due_date']);
        $this->assertSame(['role' => 'Assignee', 'name' => 'Eddie Editor'], array_intersect_key($link['person'], ['role' => 1, 'name' => 1]));
        $this->assertSame('High', $link['priority']['name']);
        $this->assertSame(['name' => 'Website', 'slug' => $this->project->slug], $link['project']);
        $this->assertStringEndsWith("/boards/{$this->board->slug}#item-{$item->id}", $link['url']);

        // moving it to the done column shows on the next request
        $done = BoardColumn::where('board_id', $this->board->id)->where('is_done_column', true)->first()
            ?? BoardColumn::create(['board_id' => $this->board->id, 'name' => 'Done', 'slug' => 'done', 'position' => 99, 'is_done_column' => true]);
        $item->update(['column_id' => $done->id]);
        $this->assertSame(
            ['name' => $done->name, 'category' => 'done'],
            $this->links($this->editor, [$ref])->json("links.{$ref}.status"),
        );
    }

    public function test_docs_tickets_and_plans_answer_and_a_plan_alias_gets_its_canonical_ref(): void
    {
        $doc = $this->doc('<p>x</p>', ['name' => 'Payments runbook']);
        $ticket = $this->ticket(['subject' => 'Refund missing', 'assigned_to' => $this->editor->id]);
        $plan = $this->plan(['name' => 'Launch plan']);
        $alias = "plan:{$this->project->slug}/{$plan->slug}";

        $links = $this->links($this->editor, ["page:{$doc->uuid}", "ticket:{$ticket->id}", "plan:{$plan->id}", $alias])->assertOk()->json('links');

        $this->assertSame(['ok', 'Doc', 'Payments runbook'], [$links["page:{$doc->uuid}"]['state'], $links["page:{$doc->uuid}"]['label'], $links["page:{$doc->uuid}"]['title']]);
        $this->assertSame('Published', $links["page:{$doc->uuid}"]['status']['name']);
        $this->assertSame(['Ticket', 'WEB-T1', 'Refund missing'], [$links["ticket:{$ticket->id}"]['label'], $links["ticket:{$ticket->id}"]['key'], $links["ticket:{$ticket->id}"]['title']]);
        $this->assertSame('Launch plan', $links["plan:{$plan->id}"]['title']);
        $this->assertSame("plan:{$plan->id}", $links[$alias]['ref'], 'a pasted plan URL gets the canonical ref');
        $this->assertStringEndsWith("/plans/{$plan->slug}", $links[$alias]['url']);
    }

    public function test_visibility_matrix_never_leaks_a_title(): void
    {
        $draft = $this->draftDoc($this->owner, 'Secret merger memo');
        $issue = $this->item(['title' => 'Confidential issue']);
        $trashed = $this->item(['title' => 'Trashed issue']);
        $trashed->delete();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $this->outsider->id]);
        $foreignProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'created_by' => $this->outsider->id]);
        $foreign = $this->item(['title' => 'Foreign issue'], Board::factory()->create(['project_id' => $foreignProject->id, 'created_by' => $this->outsider->id]));

        $refs = ["page:{$draft->uuid}", "item:{$issue->id}", "item:{$trashed->id}", "item:{$foreign->id}", 'item:999999', 'nonsense', 'page:not-a-uuid'];

        // the editor of the project: the owner's draft is restricted, the rest of the project ok
        $editor = $this->links($this->editor, $refs)->assertOk();
        $this->assertSame('restricted', $editor->json("links.page:{$draft->uuid}.state"));
        $this->assertSame('ok', $editor->json("links.item:{$issue->id}.state"));
        $this->assertSame('not_found', $editor->json("links.item:{$trashed->id}.state"));
        $this->assertSame('not_found', $editor->json("links.item:{$foreign->id}.state"), 'another workspace: not found');
        $this->assertSame('not_found', $editor->json('links.item:999999.state'));
        $this->assertSame('not_found', $editor->json('links.nonsense.state'));
        $this->assertSame('not_found', $editor->json('links.page:not-a-uuid.state'));
        $this->assertStringNotContainsString('Secret merger memo', $editor->getContent());
        $this->assertStringNotContainsString('Trashed issue', $editor->getContent());
        $this->assertStringNotContainsString('Foreign issue', $editor->getContent());

        // a member of another project of the workspace: restricted, no title
        $member = $this->links($this->member, $refs)->assertOk();
        $this->assertSame('restricted', $member->json("links.item:{$issue->id}.state"));
        $this->assertSame(['state' => 'restricted'], $member->json("links.item:{$issue->id}"));
        $this->assertStringNotContainsString('Confidential issue', $member->getContent());

        // the workspace admin (author of the draft) sees everything of the workspace
        $owner = $this->links($this->owner, $refs)->assertOk();
        $this->assertSame('ok', $owner->json("links.page:{$draft->uuid}.state"));
        $this->assertSame('Draft', $owner->json("links.page:{$draft->uuid}.status.name"));
        $this->assertSame('not_found', $owner->json("links.item:{$foreign->id}.state"));

        // the draft's own author (not an admin) sees it
        $authorDraft = $this->draftDoc($this->editor, 'My draft');
        $this->assertSame('ok', $this->links($this->editor, ["page:{$authorDraft->uuid}"])->json("links.page:{$authorDraft->uuid}.state"));
    }

    public function test_cross_workspace_and_invalid_requests_are_refused(): void
    {
        $item = $this->item();

        $this->links($this->outsider, ["item:{$item->id}"])->assertNotFound();
        $this->actingAs($this->editor)->getJson(route('smart-links.index', $this->workspace->slug))->assertUnprocessable();
        $this->links($this->editor, array_map(fn ($i) => "item:{$i}", range(1, SmartLinks::MAX_REFS + 1)))->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->getJson(route('smart-links.index', $this->workspace->slug).'?refs[]=item:1')->assertUnauthorized();
    }

    public function test_the_query_count_does_not_grow_with_the_batch(): void
    {
        $count = function (int $n): int {
            $refs = [];
            foreach (range(1, $n) as $i) {
                $refs[] = 'item:'.$this->item()->id;
                $refs[] = "page:{$this->doc()->uuid}";
                $refs[] = "ticket:{$this->ticket(['key' => "WEB-T{$n}{$i}"])->id}";
                $refs[] = 'plan:'.$this->plan(['name' => "Plan {$n} {$i}"])->id;
            }
            $this->actingAs($this->editor);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->links($this->editor, $refs)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count(1); // warms up per-process lookups (instance settings)
        $this->assertSame($count(2), $count(6));
    }

    public function test_picker_search_by_project_with_accessible_projects_to_switch_to(): void
    {
        $this->item(['title' => 'Checkout bug']);
        $this->doc('<p>x</p>', ['name' => 'Checkout runbook']);
        $this->draftDoc($this->owner, 'Checkout secret draft');

        $response = $this->actingAs($this->editor)
            ->getJson(route('smart-links.search', $this->workspace->slug).'?project='.$this->project->slug.'&q=checkout')
            ->assertOk();
        $this->assertEqualsCanonicalizing(['Checkout bug', 'Checkout runbook'], array_column($response->json('results'), 'title'));
        $this->assertContains('item:'.Item::where('title', 'Checkout bug')->value('id'), array_column($response->json('results'), 'ref'));
        $this->assertSame([$this->project->slug], array_column($response->json('projects'), 'slug'), 'only projects the editor can open');

        // the owner (workspace admin) can switch to every project
        $owner = $this->actingAs($this->owner)->getJson(route('smart-links.search', $this->workspace->slug).'?project='.$this->otherProject->slug)->assertOk();
        $this->assertEqualsCanonicalizing([$this->project->slug, $this->otherProject->slug], array_column($owner->json('projects'), 'slug'));

        // a project the user can't open
        $this->actingAs($this->member)->getJson(route('smart-links.search', $this->workspace->slug).'?project='.$this->project->slug)->assertNotFound();
        $this->actingAs($this->outsider)->getJson(route('smart-links.search', $this->workspace->slug).'?project='.$this->project->slug)->assertNotFound();
    }

    // ── Derived references ──────────────────────────────────────────────────────────

    public function test_smart_links_in_a_doc_become_backlinks_of_the_same_project_only(): void
    {
        $item = $this->item(['title' => 'Fix login']);
        $other = $this->doc('<p>x</p>', ['name' => 'Glossary']);
        $ticket = $this->ticket();
        $plan = $this->plan();
        $crossProject = $this->item([], Board::factory()->create(['project_id' => $this->otherProject->id, 'created_by' => $this->owner->id]));

        $doc = $this->doc('<p>'.$this->anchor("item:{$item->id}").$this->anchor("item:{$item->id}", 'again', 'card')
            .$this->anchor("page:{$other->uuid}").$this->anchor("ticket:{$ticket->id}")
            .$this->anchor("plan:{$this->project->slug}/{$plan->slug}").$this->anchor("item:{$crossProject->id}").'</p>', ['name' => 'Release notes']);

        $this->assertEqualsCanonicalizing(
            ["item:{$item->id}", "page:{$other->id}", "ticket:{$ticket->id}", "plan:{$plan->id}"],
            ContentReference::where('source_type', 'page')->where('source_id', $doc->id)->get()->map(fn ($r) => "{$r->target_type}:{$r->target_id}")->all(),
        );
        $this->assertTrue(ContentReference::where('source_id', $doc->id)->where('source_type', 'page')->get()->every(fn ($r) => $r->isDerived()));
        $this->assertSame(0, ContentReference::where('target_type', 'item')->where('target_id', $crossProject->id)->count(), 'cross-project: no reference');

        $referencedBy = $this->actingAs($this->editor)
            ->getJson(route('references.index', [$this->workspace->slug, $this->project->slug])."?type=item&id={$item->id}")
            ->assertOk()->json('referenced_by');
        $this->assertSame(["page:{$doc->id}"], array_column($referencedBy, 'key'));
        $this->assertSame('Smart link', $referencedBy[0]['derived_label']);

        // removing the link from the doc prunes the backlink
        $doc->update(['content' => ['type' => 'lexical', 'html' => '<p>'.$this->anchor("ticket:{$ticket->id}").'</p>']]);
        $this->assertSame([], $this->backlinks($this->editor, 'item', $item->id));
        $this->assertSame(["page:{$doc->id}"], $this->backlinks($this->editor, 'ticket', $ticket->id));
    }

    public function test_a_published_doc_derives_from_its_published_content_not_the_draft(): void
    {
        $item = $this->item();
        $doc = $this->doc('<p>nothing yet</p>');

        $doc->saveDraft($this->editor, ['content' => ['type' => 'lexical', 'html' => '<p>'.$this->anchor("item:{$item->id}").'</p>']]);
        $this->assertSame([], $this->backlinks($this->editor, 'item', $item->id), 'an autosaved draft isn’t live yet');

        $doc->refresh()->publishChanges($this->editor);
        $this->assertSame(["page:{$doc->id}"], $this->backlinks($this->editor, 'item', $item->id));
    }

    public function test_a_smart_link_in_an_issue_description_becomes_a_backlink(): void
    {
        $doc = $this->doc('<p>x</p>', ['name' => 'Spec']);
        $target = $this->item(['title' => 'Blocked by this']);
        $item = $this->item(['description' => '<p>See '.$this->anchor("page:{$doc->uuid}").'</p>']);

        $this->assertSame(["item:{$item->id}"], $this->backlinks($this->editor, 'page', $doc->id));

        $item->update(['description' => '<p>'.$this->anchor("item:{$target->id}").$this->anchor("item:{$item->id}").'</p>']);
        $this->assertSame([], $this->backlinks($this->editor, 'page', $doc->id));
        $this->assertSame(["item:{$item->id}"], $this->backlinks($this->editor, 'item', $target->id));
        $this->assertSame(0, ContentReference::where('source_id', $item->id)->where('target_id', $item->id)->where('target_type', 'item')->count(), 'no self-link');

        $item->update(['description' => '<p>plain</p>']);
        $this->assertSame([], $this->backlinks($this->editor, 'item', $target->id));
    }

    public function test_a_private_draft_with_smart_links_does_not_show_as_a_backlink_to_others(): void
    {
        $item = $this->item();
        $draft = $this->draftDoc($this->owner, 'Owner draft');
        $draft->update(['content' => ['type' => 'lexical', 'html' => '<p>'.$this->anchor("item:{$item->id}").'</p>']]);

        $this->assertSame(["page:{$draft->id}"], $this->backlinks($this->owner, 'item', $item->id));
        $this->assertSame([], $this->backlinks($this->editor, 'item', $item->id));
    }

    // ── Degrading outside the editor ────────────────────────────────────────────────

    public function test_the_sanitizer_keeps_the_smart_link_attributes_and_nothing_else(): void
    {
        $html = '<p><a href="https://app.test/b#item-1" data-smart-link="item:1" data-smart-link-view="card" data-other="x" onclick="evil()">WEB-1 Fix</a></p>';

        $clean = SafeHtml::clean($html);

        $this->assertStringContainsString('data-smart-link="item:1"', $clean);
        $this->assertStringContainsString('data-smart-link-view="card"', $clean);
        $this->assertStringNotContainsString('data-other', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
    }

    public function test_the_client_portal_and_share_page_degrade_to_plain_text_and_links(): void
    {
        $item = $this->item(['title' => 'Fix login']);
        $url = route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug])."#item-{$item->id}";
        $anchor = '<a href="'.$url.'" data-smart-link="item:'.$item->id.'" data-smart-link-view="inline">'.$item->key.' Fix login</a>';

        // client portal: internal links become their text, no data attributes
        $this->assertSame("<p>See {$item->key} Fix login</p>", DeliverableHtml::clean("<p>See {$anchor}</p>"));

        // share page: the stored anchor is a normal link
        $doc = $this->doc("<p>See {$anchor}</p>", ['name' => 'Shared doc']);
        $shareUrl = $this->actingAs($this->editor)->postJson(route('share-links.store'), ['type' => 'page', 'id' => $doc->uuid])
            ->assertCreated()->json('links.0.url');
        $this->app['auth']->forgetGuards();
        $this->get($shareUrl)->assertOk()->assertInertia(fn ($p) => $p->where('shared.html', fn ($html) => str_contains($html, 'href="'.$url.'"') && str_contains($html, "{$item->key} Fix login</a>")));
    }

    public function test_refs_are_parsed_from_html(): void
    {
        $uuid = '0b9f8f2e-6a43-4a1e-9f5c-6f1f0a8c1d2e';
        $html = '<p><a data-smart-link-view="card" data-smart-link="item:5">a</a><a data-smart-link=\'page:'.strtoupper($uuid).'\'>b</a>'
            .'<a data-smart-link="item:5">dup</a><a data-smart-link="plan:web/q4-roadmap">c</a><a data-smart-link="item:0">bad</a><a data-smart-link="user:1">bad</a></p>';

        $this->assertSame(['item:5', 'page:'.strtoupper($uuid), 'plan:web/q4-roadmap'], SmartLinks::refsInHtml($html));
        $this->assertSame(['type' => 'page', 'uuid' => $uuid], SmartLinks::parse('page:'.strtoupper($uuid)));
        $this->assertNull(SmartLinks::parse('item:abc'));
    }
}
