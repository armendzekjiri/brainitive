<?php

namespace Tests\Feature\Entitlements;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\CreateBoardItemsTool;
use App\Mcp\Tools\CreateBoardItemTool;
use App\Mcp\Tools\CreatePageTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateWorkspaceTool;
use App\Models\Helpdesk;
use App\Models\ImportRun;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceUsage;
use App\Support\Admin\UserDeletion;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\WorkspaceMembershipChanged;
use App\Support\Imports\Importer;
use App\Support\Imports\ImportProgress;
use App\Support\Imports\ImportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Concerns\UsesPassportKeys;
use Tests\TestCase;

/**
 * v1.46.0: every entry point that adds members, projects, workspaces, content or files asks
 * LimitGate first. Blocked at the limit (402 JSON / `limit_reached` flash), allowed below it, and
 * nothing changes while limits aren't enforced (self-hosted).
 */
class LimitChecksTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesPassportKeys;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->owner = User::find($this->workspace->owner_id);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        ImportRegistry::fake('restore-test', null);
        parent::tearDown();
    }

    private function useLimits(array $config): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements([...['enforced' => true], ...$config]));
    }

    private function contentFull(): void
    {
        $this->useLimits(['content_bytes' => 1000]);
        WorkspaceUsage::create(['workspace_id' => $this->workspace->id, 'content_bytes' => 1000]);
    }

    private function invite(string $email)
    {
        return $this->actingAs($this->owner)->postJson(route('workspaces.members.invite', $this->workspace->slug), [
            'email' => $email,
            'role' => 'editor',
            'confirm_send_invitation' => true,
        ]);
    }

    private function invitation(string $email): WorkspaceInvitation
    {
        return $this->workspace->invitations()->create(['email' => $email, 'role' => 'editor', 'invited_by' => $this->owner->id]);
    }

    // --- Members ------------------------------------------------------------------------

    public function test_inviting_beyond_the_member_limit_is_a_402_and_adds_nobody(): void
    {
        // owner + editor + viewer = 3 members
        $this->useLimits(['members' => 3]);
        $existing = User::factory()->create();

        $this->invite($existing->email)
            ->assertStatus(402)
            ->assertJsonPath('limit_reached.kind', 'members')
            ->assertJsonPath('limit_reached.usage', 3);
        $this->invite('new@example.com')->assertStatus(402);

        $this->assertFalse($this->workspace->hasUser($existing));
        $this->assertSame(0, $this->workspace->invitations()->count());
    }

    public function test_inviting_below_the_limit_works_and_an_open_invitation_is_not_counted_twice(): void
    {
        $this->useLimits(['members' => 4]);
        Event::fake([WorkspaceMembershipChanged::class]);

        $this->invite('new@example.com')->assertRedirect();
        $this->assertSame(1, $this->workspace->invitations()->valid()->count()); // now 4 of 4

        // refreshing the same invitation, or adding that person directly, still fits
        $this->invite('new@example.com')->assertRedirect();
        $person = User::factory()->create(['email' => 'new@example.com']);
        $this->invite('new@example.com')->assertRedirect();
        $this->assertTrue($this->workspace->hasUser($person));
        Event::assertDispatched(WorkspaceMembershipChanged::class, fn ($e) => $e->workspace->is($this->workspace));

        $this->invite('fifth@example.com')->assertStatus(402);
    }

    public function test_accepting_an_invitation_fits_at_the_limit_but_not_over_it(): void
    {
        $this->useLimits(['members' => 4]);
        $this->invitation('joiner@example.com');
        $joiner = User::factory()->create(['email' => 'joiner@example.com']);
        $token = $this->workspace->invitations()->value('token');

        $this->actingAs($joiner)->post(route('invitations.accept', $token))->assertRedirect(route('workspaces.show', $this->workspace->slug));
        $this->assertTrue($this->workspace->hasUser($joiner));

        // over the limit (e.g. after a downgrade): the open invitation can't be accepted
        $this->invitation('late@example.com');
        $this->workspace->addUser(User::factory()->create(), 'viewer');
        $late = User::factory()->create(['email' => 'late@example.com']);
        $lateToken = $this->workspace->invitations()->where('email', 'late@example.com')->value('token');

        $this->actingAs($late)->from(route('invitations.show', $lateToken))->post(route('invitations.accept', $lateToken))
            ->assertRedirect(route('invitations.show', $lateToken))
            ->assertSessionHas('limit_reached', fn ($payload) => $payload['kind'] === 'members');
        $this->assertFalse($this->workspace->hasUser($late));
        $this->assertNull($this->workspace->invitations()->where('email', 'late@example.com')->value('accepted_at'));
    }

    public function test_sign_up_keeps_invitations_to_a_workspace_over_its_limit_open(): void
    {
        $this->useLimits(['members' => 3]);
        $this->invitation('newbie@example.com'); // 4 of 3: over
        $newbie = User::factory()->create(['email' => 'newbie@example.com']);

        WorkspaceInvitation::acceptPendingForUser($newbie);

        $this->assertFalse($this->workspace->hasUser($newbie));
        $this->assertSame(1, $this->workspace->invitations()->valid()->count());
    }

    public function test_members_leaving_dispatch_the_membership_event(): void
    {
        Event::fake([WorkspaceMembershipChanged::class]);

        $this->actingAs($this->owner)->delete(route('workspaces.members.remove', [$this->workspace->slug, $this->viewer->id]))->assertRedirect();
        $this->assertFalse($this->workspace->hasUser($this->viewer));
        Event::assertDispatchedTimes(WorkspaceMembershipChanged::class, 1);

        UserDeletion::delete($this->editor);
        Event::assertDispatchedTimes(WorkspaceMembershipChanged::class, 2);
    }

    // --- Workspaces ---------------------------------------------------------------------

    public function test_creating_a_workspace_beyond_the_free_workspaces_per_owner_is_blocked(): void
    {
        $this->useLimits(['free_workspaces_per_owner' => 2]);

        $this->actingAs($this->owner)->post(route('workspaces.store'), ['name' => 'Second'])->assertRedirect();
        $this->assertSame(2, Workspace::where('owner_id', $this->owner->id)->count());

        $this->actingAs($this->owner)->from(route('workspaces.create'))->withHeader('X-Inertia', 'true')
            ->post(route('workspaces.store'), ['name' => 'Third'])
            ->assertRedirect(route('workspaces.create'))
            ->assertSessionHas('limit_reached', fn ($payload) => $payload['kind'] === 'workspaces');
        $this->assertSame(2, Workspace::where('owner_id', $this->owner->id)->count());
    }

    // --- Projects -----------------------------------------------------------------------

    public function test_creating_a_project_beyond_the_limit_is_blocked_also_from_a_template(): void
    {
        $this->useLimits(['projects' => 2]);
        $store = fn (array $data) => $this->actingAs($this->owner)->from(route('projects.create', $this->workspace->slug))
            ->post(route('projects.store', $this->workspace->slug), $data);

        $store(['name' => 'Second', 'key' => 'SEC'])->assertRedirect();
        $this->assertSame(2, $this->workspace->projects()->count());

        $store(['name' => 'Third', 'key' => 'THR'])->assertSessionHas('limit_reached', fn ($payload) => $payload['kind'] === 'projects');
        $store(['name' => 'Third', 'key' => 'THR', 'template' => 'builtin:software', 'start_date' => '2026-11-02'])
            ->assertSessionHas('limit_reached');
        $this->assertSame(2, $this->workspace->projects()->count());
    }

    public function test_moving_a_project_checks_the_target_workspace(): void
    {
        $this->useLimits(['projects' => 1]);
        $target = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $target->addUser($this->owner, 'admin');
        Project::factory()->create(['workspace_id' => $target->id, 'created_by' => $this->owner->id]);

        $this->actingAs($this->owner)->post(route('projects.move', [$this->workspace->slug, $this->project->slug]), [
            'target_workspace_id' => $target->id,
            'confirm_name' => $this->project->name,
        ])->assertSessionHas('limit_reached', fn ($payload) => $payload['kind'] === 'projects' && $payload['workspace'] === $target->slug);

        $this->assertSame($this->workspace->id, $this->project->fresh()->workspace_id);
    }

    public function test_a_restore_import_is_refused_at_the_project_limit_before_anything_runs(): void
    {
        Storage::fake('local');
        ImportRegistry::fake('restore-test', RestoreTestImporter::class);
        $upload = fn () => $this->actingAs($this->owner)->post(route('imports.store', $this->workspace->slug), [
            'source' => 'restore-test',
            'file' => UploadedFile::fake()->createWithContent('project.json', '{}'),
        ]);

        $this->useLimits(['projects' => 1]);
        $upload()->assertSessionHas('limit_reached', fn ($payload) => $payload['kind'] === 'projects');
        $this->assertSame(0, ImportRun::count());

        $this->useLimits(['projects' => 2]);
        $upload()->assertRedirect();
        $run = ImportRun::sole();

        // the workspace filled up meanwhile: starting the run is refused too
        $this->useLimits(['projects' => 1]);
        $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]))->assertSessionHas('limit_reached');
        $this->assertSame(ImportRun::PREVIEWED, $run->fresh()->status);
    }

    // --- Content ------------------------------------------------------------------------

    public function test_full_content_storage_blocks_new_pages_and_issues_but_not_comments(): void
    {
        $this->contentFull();
        $item = $this->item();

        $this->actingAs($this->editor)->post(route('pages.store', [$this->workspace->slug, $this->project->slug]), ['name' => 'Spec', 'type' => 'page'])
            ->assertSessionHas('limit_reached', fn ($payload) => $payload['kind'] === 'content');
        $this->actingAs($this->editor)->postJson(route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]), [
            'title' => 'Bug', 'column_id' => $this->column('to-do'),
        ])->assertStatus(402)->assertJsonPath('limit_reached.kind', 'content');
        $this->actingAs($this->editor)->postJson($this->itemRoute('boards.items.duplicate', $item))->assertStatus(402);
        $this->actingAs($this->owner)->post(route('projects.store', $this->workspace->slug), ['name' => 'New', 'key' => 'NEW'])
            ->assertSessionHas('limit_reached', fn ($payload) => $payload['kind'] === 'content');

        $this->assertSame(0, Page::count());
        $this->assertSame(1, Item::count());

        $this->actingAs($this->editor)->postJson($this->itemRoute('boards.items.comments.store', $item), ['body' => 'Still fine'])->assertSessionMissing('limit_reached');
        $this->assertSame(1, $item->comments()->count());
    }

    public function test_mcp_and_extension_create_tools_respect_the_content_limit(): void
    {
        $this->contentFull();

        BrainitiveServer::actingAs($this->editor)->tool(CreateBoardItemTool::class, [
            'workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug, 'title' => 'From AI',
        ])->assertHasErrors(['content storage']);
        BrainitiveServer::actingAs($this->editor)->tool(CreatePageTool::class, [
            'workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'title' => 'From AI',
        ])->assertHasErrors(['content storage']);

        $this->usePassportKeys();
        Passport::actingAs($this->editor, ['capture']);
        $this->postJson(route('ext.projects.pages.store', $this->project->id), ['title' => 'Clipped'])->assertStatus(402);
        $this->postJson(route('ext.boards.items.store', $this->board->id), ['title' => 'Clipped'])->assertStatus(402);

        $this->assertSame(0, Page::count());
        $this->assertSame(0, Item::count());
    }

    public function test_v147_project_setup_tools_respect_the_limits(): void
    {
        $this->contentFull();
        BrainitiveServer::actingAs($this->editor)->tool(CreateBoardItemsTool::class, [
            'workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug,
            'items' => [['title' => 'One'], ['title' => 'Two']], 'request_id' => 'bulk-1',
        ])->assertHasErrors(['content storage']);
        $this->assertSame(0, Item::count());

        $this->useLimits(['projects' => 1, 'free_workspaces_per_owner' => 1]);
        BrainitiveServer::actingAs($this->owner)->tool(CreateProjectTool::class, [
            'workspace' => $this->workspace->slug, 'name' => 'Second', 'key' => 'SEC', 'request_id' => 'proj-1',
        ])->assertHasErrors(['projects per workspace']);
        BrainitiveServer::actingAs($this->owner)->tool(CreateWorkspaceTool::class, [
            'name' => 'Another one', 'request_id' => 'ws-1',
        ])->assertHasErrors(['own up to 1 workspaces']);
        $this->assertSame(1, $this->workspace->projects()->count());
        $this->assertSame(1, Workspace::where('owner_id', $this->owner->id)->count());
    }

    public function test_content_below_the_limit_can_be_created(): void
    {
        $this->useLimits(['content_bytes' => 1000]);
        WorkspaceUsage::create(['workspace_id' => $this->workspace->id, 'content_bytes' => 999]);

        $this->actingAs($this->editor)->post(route('pages.store', [$this->workspace->slug, $this->project->slug]), ['name' => 'Spec', 'type' => 'page'])
            ->assertSessionMissing('limit_reached');
        $this->assertSame(1, Page::count());
    }

    // --- Files --------------------------------------------------------------------------

    public function test_uploads_beyond_the_file_storage_are_refused_before_storing(): void
    {
        Storage::fake('public');
        $this->useLimits(['file_bytes' => 100 * 1024]);
        WorkspaceUsage::create(['workspace_id' => $this->workspace->id, 'file_bytes' => 50 * 1024]);
        $item = $this->item();
        $attach = fn (int $kb) => $this->actingAs($this->editor)->postJson($this->itemRoute('boards.items.attachments.store', $item), [
            'file' => UploadedFile::fake()->create('report.pdf', $kb, 'application/pdf'),
        ]);

        $attach(60)->assertStatus(402)->assertJsonPath('limit_reached.kind', 'files');
        $this->assertSame([], Storage::disk('public')->allFiles());

        $attach(40)->assertSessionMissing('limit_reached');
        $this->assertSame(1, $item->attachments()->count());

        // the workspace logo (outside the workspaces/{workspace} group) is checked too
        $this->actingAs($this->owner)->withHeader('X-Inertia', 'true')->put(route('workspaces.update', $this->workspace->slug), [
            'name' => $this->workspace->name,
            'logo' => UploadedFile::fake()->image('logo.png')->size(80),
        ])->assertSessionHas('limit_reached', fn ($payload) => $payload['kind'] === 'files');
    }

    public function test_editor_uploads_count_against_the_workspace_they_were_sent_from(): void
    {
        Storage::fake('public');
        $this->useLimits(['file_bytes' => 10 * 1024]);
        $image = fn () => UploadedFile::fake()->image('shot.png')->size(20);
        $page = route('projects.show', [$this->workspace->slug, $this->project->slug]);

        $this->actingAs($this->editor)->withHeader('referer', $page)->postJson(route('upload.image'), ['image' => $image()])->assertStatus(402);
        $this->actingAs($this->editor)->postJson(route('upload.image'), ['image' => $image(), 'workspace' => $this->workspace->slug])->assertStatus(402);
        // a workspace the uploader doesn't belong to says nothing about them
        $this->actingAs($this->outsider)->withHeader('referer', $page)->postJson(route('upload.image'), ['image' => $image()])->assertSuccessful();
    }

    public function test_public_uploads_count_against_the_helpdesk_workspace_without_revealing_the_plan(): void
    {
        Storage::fake('public');
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->owner->id]);
        $ticket = $this->postJson(route('helpdesks.tickets.store-public', $helpdesk->uuid), [
            'subject' => 'Question', 'description' => 'Help', 'contact_email' => 'customer@example.com',
        ])->assertOk()->json();
        $this->useLimits(['file_bytes' => 10 * 1024]);

        $this->postJson(route('helpdesks.tickets.attachments.store-public', [$helpdesk->uuid, $ticket['ticket_uuid']]), [
            'file' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
            'access_token' => $ticket['access_token'],
        ])->assertStatus(402)->assertJsonMissingPath('limit_reached');

        $this->assertSame(0, Ticket::where('uuid', $ticket['ticket_uuid'])->firstOrFail()->attachments()->count());
    }

    // --- Not enforced -------------------------------------------------------------------

    public function test_nothing_is_blocked_while_limits_are_not_enforced(): void
    {
        Storage::fake('public');
        $this->useLimits(['enforced' => false, 'members' => 1, 'projects' => 1, 'content_bytes' => 1, 'file_bytes' => 1, 'free_workspaces_per_owner' => 0]);
        WorkspaceUsage::create(['workspace_id' => $this->workspace->id, 'content_bytes' => 10, 'file_bytes' => 10]);

        $this->invite(User::factory()->create()->email)->assertRedirect();
        $this->actingAs($this->owner)->post(route('workspaces.store'), ['name' => 'Another'])->assertSessionMissing('limit_reached');
        $this->actingAs($this->owner)->post(route('projects.store', $this->workspace->slug), ['name' => 'More', 'key' => 'MORE'])->assertSessionMissing('limit_reached');
        $this->actingAs($this->editor)->post(route('pages.store', [$this->workspace->slug, $this->project->slug]), ['name' => 'Spec', 'type' => 'page'])->assertSessionMissing('limit_reached');
        $this->actingAs($this->editor)->postJson($this->itemRoute('boards.items.attachments.store', $this->item()), [
            'file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        $this->assertSame(1, Item::sole()->attachments()->count());

        $this->assertSame(2, Workspace::where('owner_id', $this->owner->id)->count());
        $this->assertSame(2, $this->workspace->projects()->count());
        $this->assertSame(1, Page::count());
    }
}

/** A restore-like importer: creates its own project (needsProject = false). */
class RestoreTestImporter implements Importer
{
    public static function source(): string
    {
        return 'restore-test';
    }

    public static function label(): string
    {
        return 'Restore (test)';
    }

    public static function description(): string
    {
        return 'Test importer.';
    }

    public static function accept(): string
    {
        return '.json';
    }

    public static function fileRules(): array
    {
        return ['max:1024'];
    }

    public static function needsProject(): bool
    {
        return false;
    }

    public static function authorize(User $user, Workspace $workspace, ?Project $project): bool
    {
        return $user->can('createProjects', $workspace);
    }

    public function preview(ImportRun $run, string $path): array
    {
        return ['count' => 0];
    }

    public function optionRules(ImportRun $run): array
    {
        return [];
    }

    public function run(ImportRun $run, string $path, ImportProgress $progress): array
    {
        return [];
    }
}
