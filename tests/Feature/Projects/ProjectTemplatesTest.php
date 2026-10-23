<?php

namespace Tests\Feature\Projects;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemFieldValue;
use App\Models\ItemLink;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\ProjectTemplateRun;
use App\Models\TaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Templates\BuiltinTemplates;
use App\Support\Templates\TemplateDocument;
use App\Support\Templates\TemplateException;
use Database\Seeders\ItemTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ProjectTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private User $editor;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->seed(ItemTypeSeeder::class);

        $this->admin = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $this->workspace->addUser($this->admin, 'admin');
        $this->editor = User::factory()->create();
        $this->workspace->addUser($this->editor, 'editor');
        $this->viewer = User::factory()->create();
        $this->workspace->addUser($this->viewer, 'viewer');
    }

    private function createFromTemplate(string $template, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->editor)->post(route('projects.store', $this->workspace), $extra + [
            'name' => 'Relaunch',
            'key' => 'REL',
            'template' => $template,
            'start_date' => '2026-11-02',
        ]);
    }

    private function project(string $key = 'REL'): Project
    {
        return Project::where('workspace_id', $this->workspace->id)->where('key', $key)->firstOrFail();
    }

    // ── Built-in templates ───────────────────────────────────────────────────

    public function test_every_built_in_template_creates_a_usable_project(): void
    {
        foreach (BuiltinTemplates::all() as $index => $template) {
            $key = 'T'.chr(65 + $index);
            $this->createFromTemplate('builtin:'.$template['key'], ['name' => $template['name'], 'key' => $key])
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $project = $this->project($key);
            $doc = BuiltinTemplates::document($template['key']);

            $this->assertTrue($project->users()->whereKey($this->editor->id)->wherePivot('role', 'admin')->exists());
            $this->assertSame(count($doc['boards']), $project->boards()->count());
            foreach ($project->boards()->orderBy('position')->get() as $i => $board) {
                $this->assertSame(array_column($doc['boards'][$i]['columns'], 'name'), $board->columns()->orderBy('position')->pluck('name')->all());
            }
            $this->assertSame(count($doc['items']), Item::where('project_id', $project->id)->count());
            $this->assertSame("{$key}-1", Item::where('project_id', $project->id)->orderBy('id')->value('key'));
            $this->assertSame(count($doc['pages']), Page::where('project_id', $project->id)->count());
            $this->assertSame(0, Page::where('project_id', $project->id)->where('type', 'page')->where('status', '!=', 'published')->count(), 'Docs are published so members can read them.');
            $this->assertSame(count($doc['task_lists']), TaskList::where('project_id', $project->id)->count());
            $this->assertSame(count($doc['plans']), Plan::where('project_id', $project->id)->count());

            // Every issue link and plan row points at an issue of the new project
            $itemIds = Item::where('project_id', $project->id)->pluck('id')->all();
            foreach (Item::where('project_id', $project->id)->whereNotNull('parent_id')->pluck('parent_id') as $parentId) {
                $this->assertContains($parentId, $itemIds);
            }
            foreach (Plan::where('project_id', $project->id)->get() as $plan) {
                foreach ($plan->data['rows'] as $row) {
                    if (isset($row['itemId'])) {
                        $this->assertContains($row['itemId'], $itemIds);
                    }
                }
            }
        }
    }

    public function test_viewer_can_open_the_project_and_read_its_docs_after_being_added(): void
    {
        $this->createFromTemplate('builtin:software-development')->assertSessionHasNoErrors();
        $project = $this->project();
        $project->addUser($this->viewer, 'viewer');
        $doc = Page::where('project_id', $project->id)->where('name', 'Definition of done')->firstOrFail();

        $this->actingAs($this->viewer)->get(route('projects.show', [$this->workspace, $project]))->assertOk();
        $this->actingAs($this->viewer)->get(route('pages.show', [$this->workspace->slug, $project->slug, $doc->uuid]))->assertOk();
    }

    public function test_dates_shift_from_the_start_date_across_month_and_year_boundaries(): void
    {
        $this->createFromTemplate('builtin:client-website', ['start_date' => '2026-12-28'])->assertSessionHasNoErrors();
        $project = $this->project();

        // "Go live" is due 52 days after day 0
        $this->assertSame('2027-02-18', Item::where('project_id', $project->id)->where('title', 'Go live')->first()->due_date->toDateString());
        $plan = Plan::where('project_id', $project->id)->first();
        $this->assertSame('2026-12-28', $plan->data['rows'][0]['bars'][0]['start']);
        $this->assertSame('2027-01-04', $plan->data['rows'][0]['bars'][0]['end']);
    }

    public function test_sections_can_be_left_out_when_creating(): void
    {
        $this->createFromTemplate('builtin:product-launch', ['include' => ['pages']])->assertSessionHasNoErrors();
        $project = $this->project();

        $this->assertSame(1, $project->boards()->count(), 'Boards and their columns always come along.');
        $this->assertSame(0, Item::where('project_id', $project->id)->count());
        $this->assertSame(0, TaskList::where('project_id', $project->id)->count());
        $this->assertSame(0, Plan::where('project_id', $project->id)->count());
        $this->assertSame(1, Page::where('project_id', $project->id)->count());

        // "none" = structure only (forms can't send an empty array)
        $this->createFromTemplate('builtin:client-website', ['name' => 'Bare', 'key' => 'BARE', 'include' => ['none']])->assertSessionHasNoErrors();
        $bare = $this->project('BARE');
        $this->assertSame(4, $bare->boards()->first()->columns()->count());
        $this->assertSame(0, Item::where('project_id', $bare->id)->count() + Page::where('project_id', $bare->id)->count() + TaskList::where('project_id', $bare->id)->count() + Plan::where('project_id', $bare->id)->count());
    }

    // ── Capture and reuse ────────────────────────────────────────────────────

    private function sourceProject(): Project
    {
        $source = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id, 'key' => 'SRC', 'name' => 'Source']);
        $source->addUser($this->admin, 'admin');
        $board = Board::factory()->create(['project_id' => $source->id, 'created_by' => $this->admin->id, 'name' => 'Delivery']);
        $board->columns()->where('slug', 'in-progress')->update(['name' => 'Doing', 'wip_limit' => 3]);
        $label = $source->labels()->create(['name' => 'Urgent', 'color' => '#ef4444']);
        $type = $source->itemTypes()->create(['name' => 'Request', 'slug' => 'request', 'color' => '#14b8a6', 'is_active' => true]);
        $field = $source->itemFields()->create(['name' => 'Tier', 'type' => 'select', 'board_ids' => [$board->id]]);
        $gold = $field->options()->create(['label' => 'Gold', 'position' => 1]);
        $board->update(['settings' => ['swimlanes' => ['mode' => 'custom', 'rest_name' => 'Rest', 'lanes' => [
            ['id' => 'lane-a', 'name' => 'Urgent', 'color' => '#ef4444', 'rules' => ['label_ids' => [$label->id], 'assignee_ids' => [0, $this->admin->id]]],
        ]]]]);

        $todo = $board->columns()->where('slug', 'to-do')->value('id');
        $parent = Item::factory()->create(['board_id' => $board->id, 'project_id' => $source->id, 'column_id' => $todo, 'created_by' => $this->admin->id,
            'title' => 'Parent', 'item_type_id' => $type->id, 'assigned_to' => $this->admin->id, 'due_date' => '2026-03-10',
            'description' => '<p>Ask <span data-mention-user-id="'.$this->admin->id.'" data-mention-name="Admin">@Admin</span>, see <a href="https://app.test/workspaces/w/projects/src/boards/b#item-9">the old issue</a> and <a href="https://example.com">docs</a></p>']);
        $child = Item::factory()->create(['board_id' => $board->id, 'project_id' => $source->id, 'column_id' => $todo, 'created_by' => $this->admin->id,
            'title' => 'Child', 'parent_id' => $parent->id, 'start_date' => '2026-03-01']);
        $parent->labels()->attach($label->id);
        ItemFieldValue::create(['item_id' => $parent->id, 'item_field_id' => $field->id, 'option_id' => $gold->id]);
        ItemLink::create(['source_item_id' => $child->id, 'target_item_id' => $parent->id, 'link_type' => 'is_blocked_by', 'created_by' => $this->admin->id]);
        $parent->comments()->create(['user_id' => $this->admin->id, 'body' => 'Secret comment']);

        $folder = Page::factory()->folder()->create(['project_id' => $source->id, 'created_by' => $this->admin->id, 'name' => 'Handbook']);
        Page::factory()->published()->create(['project_id' => $source->id, 'created_by' => $this->admin->id, 'name' => 'Guide', 'parent_id' => $folder->id]);
        $draft = Page::factory()->create(['project_id' => $source->id, 'created_by' => $this->admin->id, 'name' => 'Private draft']);
        Page::factory()->published()->create(['project_id' => $source->id, 'created_by' => $this->admin->id, 'name' => 'Under draft', 'parent_id' => $draft->id]);

        $list = TaskList::create(['project_id' => $source->id, 'name' => 'Checklist', 'slug' => 'checklist', 'created_by' => $this->admin->id]);
        $list->items()->create(['title' => 'Book venue', 'status' => 'done', 'due_date' => '2026-03-05', 'assignee_id' => $this->admin->id, 'created_by' => $this->admin->id,
            'description' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => $this->admin->id, 'label' => 'Admin']]]]]]]);

        Plan::create(['project_id' => $source->id, 'name' => 'Timeline', 'slug' => 'timeline', 'created_by' => $this->admin->id, 'share_token' => Str::random(40), 'data' => [
            'rows' => [['id' => 'r1', 'name' => 'Parent', 'level' => 0, 'who' => 'Admin', 'itemId' => $parent->id, 'bars' => [['id' => 'b1', 'start' => '2026-03-01', 'end' => '2026-03-10', 'type' => 'bar', 'color' => '#3B82F6']]]],
            'dependencies' => [],
        ]]);

        return $source;
    }

    private function saveAsTemplate(Project $source, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('projects.templates.store', [$this->workspace, $source]), $extra + ['name' => 'Agency project']);
    }

    public function test_saved_template_recreates_the_structure_with_new_ids_and_without_private_data(): void
    {
        $source = $this->sourceProject();
        $this->saveAsTemplate($source)->assertSessionHasNoErrors();
        $template = ProjectTemplate::firstWhere('name', 'Agency project');
        $this->assertSame(1, $template->latestVersion->version);

        $this->createFromTemplate('template:'.$template->uuid, ['start_date' => '2027-01-01'])->assertSessionHasNoErrors();
        $project = $this->project();

        // Structure with fresh rows of the new project
        $board = $project->boards()->firstOrFail();
        $this->assertSame(['To Do', 'Doing', 'Done'], $board->columns()->orderBy('position')->pluck('name')->all());
        $this->assertSame(3, $board->columns()->where('name', 'Doing')->value('wip_limit'));
        $label = $project->labels()->firstOrFail();
        $this->assertSame('Urgent', $label->name);
        $this->assertSame([$label->id], $board->settings['swimlanes']['lanes'][0]['rules']['label_ids']);
        $this->assertSame([0], $board->settings['swimlanes']['lanes'][0]['rules']['assignee_ids'], 'People never come along.');
        $field = $project->itemFields()->firstOrFail();
        $this->assertSame([$board->id], $field->board_ids);

        $parent = Item::where('project_id', $project->id)->where('title', 'Parent')->firstOrFail();
        $child = Item::where('project_id', $project->id)->where('title', 'Child')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame('Request', $parent->itemType->name);
        $this->assertSame($project->id, $parent->itemType->project_id);
        $this->assertSame([$label->id], $parent->labels()->pluck('labels.id')->all());
        $this->assertSame($field->options()->value('id'), ItemFieldValue::where('item_id', $parent->id)->value('option_id'));
        $this->assertTrue(ItemLink::where('source_item_id', $child->id)->where('target_item_id', $parent->id)->exists());
        $this->assertSame('REL-1', $parent->key);

        // Dates: day 0 = the earliest source date (Mar 1) → Jan 1
        $this->assertSame('2027-01-01', $child->start_date->toDateString());
        $this->assertSame('2027-01-10', $parent->due_date->toDateString());

        // Nothing private or personal
        $this->assertNull($parent->assigned_to);
        $this->assertSame(0, $parent->comments()->count());
        $this->assertStringNotContainsString('data-mention-user-id', $parent->description);
        $this->assertStringContainsString('@Admin', $parent->description);
        $this->assertStringNotContainsString('/workspaces/', $parent->description, 'Links into the source project keep only their text.');
        $this->assertStringContainsString('href="https://example.com"', $parent->description);
        $this->assertSame(['Guide', 'Handbook', 'Under draft'], Page::where('project_id', $project->id)->orderBy('name')->pluck('name')->all());
        $this->assertNull(Page::where('project_id', $project->id)->where('name', 'Under draft')->value('parent_id'), 'A doc under an unpublished one moves up.');
        $task = TaskList::where('project_id', $project->id)->firstOrFail()->items()->firstOrFail();
        $this->assertSame('todo', $task->status);
        $this->assertNull($task->assignee_id);
        $this->assertSame('2027-01-05', $task->due_date->toDateString());
        $this->assertSame(['type' => 'text', 'text' => '@Admin'], $task->description['content'][0]['content'][0]);

        $plan = Plan::where('project_id', $project->id)->firstOrFail();
        $this->assertNull($plan->share_token);
        $this->assertSame($parent->id, $plan->data['rows'][0]['itemId']);
        $this->assertArrayNotHasKey('who', $plan->data['rows'][0]);

        // The stored template itself holds no database ids of the source project
        $json = json_encode($template->latestVersion->document);
        $this->assertStringNotContainsString('"itemId"', $json);
        $this->assertStringNotContainsString('Secret comment', $json);
        $this->assertStringNotContainsString('Private draft', $json);
    }

    public function test_preview_reports_what_would_be_saved(): void
    {
        $source = $this->sourceProject();

        $this->actingAs($this->admin)
            ->getJson(route('projects.templates.preview', [$this->workspace, $source, 'include' => ['pages']]))
            ->assertOk()
            ->assertJsonPath('summary.issues', 0)
            ->assertJsonPath('summary.docs', 2)
            ->assertJsonPath('summary.folders', 1)
            ->assertJsonPath('summary.boards.0.columns', ['To Do', 'Doing', 'Done']);
    }

    public function test_saving_again_adds_a_version_and_existing_projects_stay_independent(): void
    {
        $source = $this->sourceProject();
        $this->saveAsTemplate($source);
        $template = ProjectTemplate::firstOrFail();

        $this->createFromTemplate('template:'.$template->uuid, ['name' => 'First', 'key' => 'ONE'])->assertSessionHasNoErrors();
        $this->createFromTemplate('template:'.$template->uuid, ['name' => 'Second', 'key' => 'TWO'])->assertSessionHasNoErrors();
        $first = $this->project('ONE');
        $second = $this->project('TWO');
        $this->assertNotSame($first->boards()->value('id'), $second->boards()->value('id'));

        $source->labels()->create(['name' => 'Later', 'color' => '#22c55e']);
        $this->saveAsTemplate($source, ['template' => $template->uuid, 'name' => 'Agency project v2'])->assertSessionHasNoErrors();

        $this->assertSame(2, $template->fresh()->latestVersion->version);
        $this->assertSame(['Urgent'], $first->labels()->pluck('name')->all(), 'Old projects never follow template edits.');
        $this->createFromTemplate('template:'.$template->uuid, ['name' => 'Third', 'key' => 'THR'])->assertSessionHasNoErrors();
        $this->assertSame(['Later', 'Urgent'], $this->project('THR')->labels()->orderBy('name')->pluck('name')->all());
    }

    // ── Idempotency & failure ────────────────────────────────────────────────

    public function test_retrying_with_the_same_request_id_creates_one_project(): void
    {
        $requestId = (string) Str::uuid();

        $this->createFromTemplate('builtin:scrum', ['request_id' => $requestId])->assertSessionHasNoErrors();
        $this->createFromTemplate('builtin:scrum', ['request_id' => $requestId])->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', [$this->workspace, $this->project()]));

        $this->assertSame(1, Project::where('key', 'REL')->count());
        $this->assertSame(ProjectTemplateRun::STATUS_COMPLETED, ProjectTemplateRun::firstWhere('request_id', $requestId)->status);
    }

    public function test_a_failure_midway_leaves_no_project_and_can_be_retried(): void
    {
        $requestId = (string) Str::uuid();
        $fail = true;
        Page::creating(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('disk full');
            }
        });

        $this->createFromTemplate('builtin:software-development', ['request_id' => $requestId])->assertSessionHasErrors('template');

        $this->assertSame(0, Project::where('key', 'REL')->count());
        $this->assertSame(0, Board::count());
        $this->assertSame(0, Item::count());
        $run = ProjectTemplateRun::firstWhere('request_id', $requestId);
        $this->assertSame(ProjectTemplateRun::STATUS_FAILED, $run->status);
        $this->assertNull($run->project_id);

        $fail = false;
        $this->createFromTemplate('builtin:software-development', ['request_id' => $requestId])->assertSessionHasNoErrors();
        $this->assertSame(1, Project::where('key', 'REL')->count());
        $this->assertSame(ProjectTemplateRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_a_stuck_run_can_be_retried_but_a_live_one_cannot(): void
    {
        $requestId = (string) Str::uuid();
        $run = ProjectTemplateRun::create(['request_id' => $requestId, 'user_id' => $this->editor->id, 'workspace_id' => $this->workspace->id, 'template' => 'builtin:scrum', 'status' => ProjectTemplateRun::STATUS_RUNNING]);

        $this->createFromTemplate('builtin:scrum', ['request_id' => $requestId])->assertSessionHasErrors('template');
        $this->assertSame(0, Project::where('key', 'REL')->count());

        // The request that owned it died (timeout): after a while it may be retried
        ProjectTemplateRun::whereKey($run->id)->update(['updated_at' => now()->subMinutes(30)]);
        $this->createFromTemplate('builtin:scrum', ['request_id' => $requestId])->assertSessionHasNoErrors();
        $this->assertSame(ProjectTemplateRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_a_request_id_belongs_to_one_user_and_workspace_and_must_be_a_uuid(): void
    {
        $requestId = (string) Str::uuid();
        $other = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $other->addUser($this->editor, 'editor');
        ProjectTemplateRun::create(['request_id' => $requestId, 'user_id' => $this->editor->id, 'workspace_id' => $other->id, 'template' => 'builtin:scrum', 'status' => ProjectTemplateRun::STATUS_FAILED]);

        $this->createFromTemplate('builtin:scrum', ['request_id' => $requestId])->assertSessionHasErrors('template');
        $this->createFromTemplate('builtin:scrum', ['request_id' => $requestId], $this->admin)->assertSessionHasErrors('template');

        // Garbage never reaches the uuid columns (Postgres would answer with a 500)
        $this->createFromTemplate('template:not-a-uuid')->assertSessionHasErrors('template');
        $this->createFromTemplate('builtin:scrum', ['request_id' => 'abc'])->assertSessionHasErrors('request_id');
    }

    // ── Permissions & validation ─────────────────────────────────────────────

    public function test_who_can_use_save_and_delete_templates(): void
    {
        $source = $this->sourceProject();
        $source->addUser($this->editor, 'admin');

        // Viewers can't create projects at all
        $this->createFromTemplate('builtin:scrum', as: $this->viewer)->assertForbidden();
        // Editors use templates but don't save or delete them
        $this->saveAsTemplate($source, as: $this->editor)->assertForbidden();
        $this->actingAs($this->editor)->getJson(route('projects.templates.preview', [$this->workspace, $source]))->assertForbidden();

        $this->saveAsTemplate($source)->assertSessionHasNoErrors();
        $template = ProjectTemplate::firstOrFail();
        $this->createFromTemplate('template:'.$template->uuid)->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->delete(route('project-templates.destroy', [$this->workspace, $template]))->assertForbidden();

        $this->actingAs($this->admin)->delete(route('project-templates.destroy', [$this->workspace, $template]))->assertSessionHasNoErrors();
        $this->assertNull($template->fresh());
        $this->assertSame(1, Project::where('key', 'REL')->count(), 'Projects made from a deleted template stay.');
    }

    public function test_templates_and_projects_of_other_workspaces_are_out_of_reach(): void
    {
        $otherAdmin = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $otherAdmin->id]);
        $other->addUser($otherAdmin, 'admin');
        $other->addUser($this->admin, 'viewer');
        $foreignProject = Project::factory()->create(['workspace_id' => $other->id, 'created_by' => $otherAdmin->id, 'key' => 'FOR']);
        $foreignProject->addUser($this->admin, 'viewer');
        $foreignTemplate = ProjectTemplate::create(['workspace_id' => $other->id, 'name' => 'Theirs']);
        $foreignTemplate->versions()->create(['version' => 1, 'format' => 1, 'document' => BuiltinTemplates::document('scrum'), 'summary' => []]);

        $this->createFromTemplate('template:'.$foreignTemplate->uuid)->assertSessionHasErrors('template');
        $this->actingAs($this->admin)->post(route('projects.templates.store', [$this->workspace, $foreignProject]), ['name' => 'Stolen'])->assertNotFound();
        $this->actingAs($this->admin)->delete(route('project-templates.destroy', [$this->workspace, $foreignTemplate]))->assertNotFound();
        $this->assertSame(0, ProjectTemplate::where('workspace_id', $this->workspace->id)->count());
    }

    public function test_key_and_slug_collisions(): void
    {
        Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id, 'key' => 'REL', 'name' => 'Old']);
        $this->createFromTemplate('builtin:scrum')->assertSessionHasErrors('key');

        $trashed = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id, 'key' => 'GONE', 'name' => 'Gone', 'slug' => 'launch']);
        $trashed->delete();
        $this->createFromTemplate('builtin:scrum', ['name' => 'Launch', 'key' => 'LCH'])->assertSessionHasNoErrors();
        $this->assertSame('launch-2', $this->project('LCH')->slug);
    }

    public function test_template_requires_a_start_date_and_a_known_template(): void
    {
        $this->createFromTemplate('builtin:scrum', ['start_date' => ''])->assertSessionHasErrors('start_date');
        $this->createFromTemplate('builtin:nope')->assertSessionHasErrors('template');
        $this->createFromTemplate('builtin:scrum', ['include' => ['comments']])->assertSessionHasErrors('include.0');
    }

    public function test_create_page_lists_built_in_and_workspace_templates(): void
    {
        $this->saveAsTemplate($this->sourceProject());

        $this->actingAs($this->editor)->get(route('projects.create', $this->workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Create')
                ->where('templates.0.id', 'builtin:software-development')
                ->where('templates.0.summary.boards.0.columns', ['Backlog', 'To Do', 'In Progress', 'In Review', 'Done'])
                ->where('templates.4.name', 'Agency project')
                ->where('templates.4.can_delete', false)
                ->missing('templates.4.document'));
        $this->assertFileExists(resource_path('js/Pages/Projects/Create.tsx'));
    }

    // ── Format compatibility ─────────────────────────────────────────────────

    public function test_document_format_is_checked_and_old_documents_are_repaired(): void
    {
        try {
            TemplateDocument::normalize(['format' => TemplateDocument::FORMAT + 1]);
            $this->fail('A newer format must be refused.');
        } catch (TemplateException $e) {
            $this->assertStringContainsString('newer version', $e->getMessage());
        }

        // A sparse / partly broken v1 document still works: dangling refs are dropped
        $doc = TemplateDocument::normalize([
            'format' => 1,
            'boards' => [['ref' => 'b1', 'name' => 'Board', 'columns' => [['ref' => 'c1', 'name' => 'Todo']]]],
            'items' => [
                ['ref' => 'i1', 'board' => 'b1', 'column' => 'c-missing', 'title' => 'Orphan column', 'parent' => 'i-missing', 'labels' => ['l-missing']],
                ['ref' => 'i2', 'board' => 'b-missing', 'title' => 'No board'],
            ],
            'links' => [['from' => 'i1', 'to' => 'i2', 'type' => 'blocks']],
        ]);

        $this->assertCount(1, $doc['items']);
        $this->assertNull($doc['items'][0]['column']);
        $this->assertNull($doc['items'][0]['parent']);
        $this->assertSame([], $doc['items'][0]['labels']);
        $this->assertSame([], $doc['links']);
        $this->assertSame([], $doc['pages']);
    }
}
