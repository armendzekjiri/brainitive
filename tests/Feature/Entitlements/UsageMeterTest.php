<?php

namespace Tests\Feature\Entitlements;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUsage;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\WorkspaceUsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** v1.46.0: usage:measure writes members, projects, content and file bytes per workspace. */
class UsageMeterTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $slug): Workspace
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id, 'slug' => $slug]);
        $workspace->addUser($owner, 'admin');

        return $workspace;
    }

    /** Random text: compression can't shrink it, so the measured size really grows. */
    private function text(int $bytes): string
    {
        return Str::random($bytes);
    }

    private function ticketIn(Project $project): int
    {
        $helpdesk = Helpdesk::create(['project_id' => $project->id, 'name' => 'Support', 'slug' => 'support-'.Str::lower(Str::random(6)), 'created_by' => $project->created_by]);
        $contact = DB::table('ticket_contacts')->insertGetId(['uuid' => (string) Str::uuid(), 'email' => Str::random(6).'@example.test', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('tickets')->insertGetId([
            'uuid' => (string) Str::uuid(), 'helpdesk_id' => $helpdesk->id, 'column_id' => $helpdesk->columns()->value('id'),
            'ticket_contact_id' => $contact, 'key' => 'SUP-1', 'subject' => 'Help', 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_measures_members_projects_content_and_files_of_one_workspace(): void
    {
        $workspace = $this->workspace('acme');
        $workspace->addUser(User::factory()->create(), 'editor');
        $workspace->invitations()->create(['email' => 'third@example.com', 'role' => 'editor', 'token' => 'tok-3', 'invited_by' => $workspace->owner_id, 'expires_at' => now()->addDays(7)]);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        Project::factory()->create(['workspace_id' => $workspace->id]);
        $other = Project::factory()->create(['workspace_id' => $this->workspace('other')->id]);

        $meter = app(WorkspaceUsageMeter::class);
        $empty = $meter->measure($workspace);
        $this->assertSame(3, $empty->members);
        $this->assertSame(2, $empty->projects);
        $this->assertSame(0, $empty->file_bytes);
        $this->assertNotNull($empty->measured_at);

        // content: a doc + its version, an issue + comment, a ticket reply, a snippet
        $page = Page::factory()->create(['project_id' => $project->id, 'content' => ['html' => $this->text(20_000)]]);
        $page->versions()->create(['version' => 1, 'name' => 'v1', 'content' => ['html' => $this->text(20_000)]]);
        $item = Item::factory()->create(['board_id' => Board::factory()->create(['project_id' => $project->id])->id, 'description' => $this->text(10_000)]);
        DB::table('comments')->insert(['item_id' => $item->id, 'user_id' => $workspace->owner_id, 'body' => $this->text(10_000), 'created_at' => now(), 'updated_at' => now()]);
        $ticket = $this->ticketIn($project);
        DB::table('ticket_replies')->insert(['ticket_id' => $ticket, 'body' => $this->text(10_000), 'created_at' => now(), 'updated_at' => now()]);
        $collection = DB::table('snippet_collections')->insertGetId(['workspace_id' => $workspace->id, 'created_by' => $workspace->owner_id, 'uuid' => (string) Str::uuid(), 'slug' => 'snips', 'name' => 'Snips', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('snippets')->insert(['collection_id' => $collection, 'created_by' => $workspace->owner_id, 'uuid' => (string) Str::uuid(), 'slug' => 's', 'title' => 'S', 'content' => $this->text(10_000), 'created_at' => now(), 'updated_at' => now()]);

        // files: an issue attachment and a ticket attachment
        DB::table('attachments')->insert(['item_id' => $item->id, 'user_id' => $workspace->owner_id, 'filename' => 'a', 'original_filename' => 'a', 'path' => 'p', 'size' => 1_500, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ticket_attachments')->insert(['ticket_id' => $ticket, 'filename' => 'f', 'original_filename' => 'f', 'path' => 'p', 'size' => 500, 'created_at' => now(), 'updated_at' => now()]);

        // another workspace's content and files never count
        $otherItem = Item::factory()->create(['board_id' => Board::factory()->create(['project_id' => $other->id])->id, 'description' => $this->text(50_000)]);
        DB::table('attachments')->insert(['item_id' => $otherItem->id, 'user_id' => $workspace->owner_id, 'filename' => 'a', 'original_filename' => 'a', 'path' => 'p', 'size' => 99_999, 'created_at' => now(), 'updated_at' => now()]);

        $usage = $meter->measure($workspace);
        $this->assertSame($empty->id, $usage->id, 'one row per workspace, updated in place');
        $this->assertSame(1, WorkspaceUsage::where('workspace_id', $workspace->id)->count());
        $this->assertSame(2_000, $usage->file_bytes);
        // 80 KB of incompressible text went in; rows add some overhead
        $this->assertGreaterThan($empty->content_bytes + 80_000, $usage->content_bytes);
        $this->assertLessThan($empty->content_bytes + 100_000, $usage->content_bytes);
        $this->assertSame($usage->id, $workspace->usage->id);
    }

    public function test_the_command_skips_unlimited_installs_unless_one_workspace_is_asked_for(): void
    {
        $workspace = $this->workspace('acme');

        $this->artisan('usage:measure')->expectsOutputToContain('nothing to measure')->assertSuccessful();
        $this->assertSame(0, WorkspaceUsage::count());

        $this->artisan('usage:measure', ['--workspace' => 'acme'])->expectsOutputToContain('acme: 1 member(s)')->assertSuccessful();
        $this->artisan('usage:measure', ['--workspace' => (string) $workspace->id])->assertSuccessful();
        $this->assertSame(1, WorkspaceUsage::count());

        $this->artisan('usage:measure', ['--workspace' => 'missing'])->assertFailed();
    }

    public function test_the_command_measures_every_workspace_when_limits_exist(): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => false, 'members' => 3]));
        $first = $this->workspace('first');
        $second = $this->workspace('second');
        Project::factory()->count(2)->create(['workspace_id' => $second->id]);

        $this->artisan('usage:measure')->expectsOutputToContain('Measured 2 workspace(s).')->assertSuccessful();

        $this->assertSame(0, $first->usage()->first()->projects);
        $this->assertSame(2, $second->usage()->first()->projects);
    }
}
