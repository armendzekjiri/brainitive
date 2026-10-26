<?php

namespace Tests\Feature\Imports;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Helpdesk;
use App\Models\ImportRun;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemLink;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectExport;
use App\Models\Release;
use App\Models\Sprint;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use App\Notifications\ProjectExportReadyNotification;
use App\Support\Imports\Archive\ProjectArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;
use ZipArchive;

/**
 * Portable project export + restore (v1.41.0, BRAI-81): round trip with new ids and remapped
 * references, integrity checks before any write, permissions, resume, and no secrets in the ZIP.
 */
class ProjectArchiveTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        $this->owner = User::findOrFail($this->workspace->owner_id);
        $this->project->forceFill(['changelog_token' => 'CHANGELOG-SECRET-123'])->save();
    }

    /** A project with one of everything the archive carries, plus secrets it must not. */
    private function seedProject(): array
    {
        $bug = $this->project->labels()->create(['name' => 'Bug', 'color' => '#ef4444']);
        $field = $this->project->itemFields()->create(['name' => 'Size', 'type' => 'select', 'position' => 1]);
        $large = $field->options()->create(['label' => 'Large', 'position' => 1]);
        $release = Release::create(['project_id' => $this->project->id, 'name' => 'v1', 'status' => 'unreleased', 'public_token' => 'RELEASE-SECRET-456', 'created_by' => $this->owner->id]);
        $sprint = Sprint::factory()->create(['board_id' => $this->board->id, 'name' => 'Sprint 1']);

        Storage::disk('public')->put('uploads/images/aaaa-bbbb.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $parent = $this->item([
            'title' => 'Ünïcode parent ✓',
            'description' => '<p>See <img src="http://localhost/storage/uploads/images/aaaa-bbbb.png"> and <span data-mention-user-id="'.$this->editor->id.'">@Ed</span></p>',
            'assigned_to' => $this->editor->id,
            'sprint_id' => $sprint->id,
            'release_id' => $release->id,
        ]);
        $parent->labels()->sync([$bug->id]);
        $parent->fieldValues()->create(['item_field_id' => $field->id, 'option_id' => $large->id]);
        $child = $this->item(['title' => 'Child', 'parent_id' => $parent->id]);
        ItemLink::create(['source_item_id' => $parent->id, 'target_item_id' => $child->id, 'link_type' => 'blocks', 'created_by' => $this->owner->id]);
        $comment = Comment::create(['item_id' => $parent->id, 'user_id' => $this->editor->id, 'body' => '<p>First!</p><script>alert(1)</script>']);
        Comment::create(['item_id' => $parent->id, 'user_id' => $this->owner->id, 'parent_id' => $comment->id, 'body' => '<p>Reply</p>']);
        Storage::disk('public')->put('attachments/'.$parent->id.'/f.pdf', '%PDF-1.4 test');
        Attachment::create(['item_id' => $parent->id, 'user_id' => $this->editor->id, 'filename' => 'f.pdf', 'original_filename' => 'Spec.pdf', 'path' => 'attachments/'.$parent->id.'/f.pdf', 'mime_type' => 'application/pdf', 'size' => 13]);

        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Handbook']);
        Page::factory()->published()->create(['project_id' => $this->project->id, 'parent_id' => $folder->id, 'created_by' => $this->owner->id, 'name' => 'Welcome', 'content' => ['type' => 'lexical', 'html' => '<p>Hello docs</p>']]);
        Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Board', 'content' => [
            'type' => 'excalidraw', 'appState' => [], 'files' => [],
            'elements' => [['id' => 'e1', 'type' => 'rectangle', 'customData' => ['brainitive' => 'issue', 'itemId' => $parent->id], 'link' => 'javascript:alert(1)']],
        ]]);

        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Chores']);
        TaskListItem::factory()->create(['task_list_id' => $list->id, 'created_by' => $this->owner->id, 'title' => 'Water plants', 'assignee_id' => $this->editor->id]);
        Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'share_token' => 'PLAN-SECRET-789', 'data' => [
            'rows' => [['id' => 'r1', 'name' => 'Linked', 'level' => 0, 'itemId' => $parent->id, 'bars' => []]], 'dependencies' => [],
        ]]);
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->owner->id, 'settings' => ['welcome_message' => 'Hi!']]);
        $helpdesk->forceFill(['forwarding_local_part' => 'FORWARD-SECRET-000'])->save();

        return compact('parent', 'child', 'field');
    }

    private function export(?User $as = null): ProjectExport
    {
        $this->actingAs($as ?? $this->owner)
            ->postJson(route('projects.export.store', [$this->workspace->slug, $this->project->slug]))
            ->assertStatus(202);

        return ProjectExport::latest('id')->firstOrFail();
    }

    private function zipPath(ProjectExport $export): string
    {
        return Storage::disk('local')->path($export->file_path);
    }

    private function upload(string $zipPath, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(route('imports.store', $this->workspace->slug), [
            'source' => 'brainitive',
            'file' => UploadedFile::fake()->createWithContent('export.zip', (string) file_get_contents($zipPath)),
        ]);
    }

    /** A copy of the archive with entries replaced / added. */
    private function tampered(string $zipPath, array $entries): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'arch').'.zip';
        copy($zipPath, $copy);
        $zip = new ZipArchive;
        $zip->open($copy);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $copy;
    }

    public function test_export_writes_a_verified_zip_and_notifies_the_exporter(): void
    {
        $this->seedProject();
        $export = $this->export();

        $this->assertSame(ProjectExport::READY, $export->status);
        $this->assertTrue($export->expires_at->between(now()->addHours(23), now()->addHours(25)));
        Notification::assertSentTo($this->owner, ProjectExportReadyNotification::class, function ($n) {
            $data = $n->toDatabase($this->owner);

            return $data['type'] === 'export_ready' && str_contains($data['url'], '/exports/') && str_contains($data['url'], 'signature=');
        });
        $this->assertSame('workspace', NotificationCatalog::category('export_ready'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->zipPath($export)));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame([ProjectArchive::FORMAT, ProjectArchive::VERSION], [$manifest['format'], $manifest['version']]);
        foreach ($manifest['files'] as $path => $hash) {
            $this->assertSame($hash, hash('sha256', $zip->getFromName($path)), $path);
        }
        $this->assertSame(2, $manifest['counts']['issues']);
        $this->assertSame(1, $manifest['counts']['attachments']);
        $this->assertSame(1, $manifest['counts']['media']);
        $this->assertContains('Helpdesk tickets and contacts', array_column($manifest['omitted'], 'what'));
        $zip->close();
    }

    public function test_secrets_and_tokens_are_never_exported(): void
    {
        $this->seedProject();
        $export = $this->export();

        $zip = new ZipArchive;
        $zip->open($this->zipPath($export));
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= $zip->getFromIndex($i);
        }
        $zip->close();

        foreach (['CHANGELOG-SECRET-123', 'RELEASE-SECRET-456', 'PLAN-SECRET-789', 'FORWARD-SECRET-000', 'share_token', 'public_token', 'changelog_token', 'password', 'remember_token', 'data-mention-user-id'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertStringContainsString($this->editor->email, $all); // people metadata, by design
    }

    public function test_download_is_signed_expiring_and_only_for_the_exporter(): void
    {
        $this->seedProject();
        $export = $this->export();
        $url = $export->fresh()->downloadUrl();

        $this->actingAs($this->owner)->get($url)->assertOk()->assertHeader('content-type', 'application/zip');
        $this->actingAs($this->owner)->get($url, ['X-Inertia' => 'true'])->assertStatus(409); // Inertia::location → full download
        $this->actingAs($this->owner)->get(route('projects.export.download', [$this->workspace->slug, $export->uuid]))->assertForbidden();
        $this->actingAs($this->editor)->get($url)->assertForbidden();

        $this->travel(25)->hours();
        $this->actingAs($this->owner)->get($url)->assertRedirect(route('projects.edit', [$this->workspace->slug, $this->project->slug, 'tab' => 'general']));
        $this->artisan('schedule:test', ['--name' => 'exports:cleanup'])->assertSuccessful();
        $this->assertSame(ProjectExport::EXPIRED, $export->fresh()->status);
        Storage::disk('local')->assertMissing($export->file_path);
    }

    public function test_only_project_admins_can_export(): void
    {
        $this->actingAs($this->editor)->postJson(route('projects.export.store', [$this->workspace->slug, $this->project->slug]))->assertForbidden();
        $this->actingAs($this->outsider)->postJson(route('projects.export.store', [$this->workspace->slug, $this->project->slug]))->assertForbidden();
        $this->actingAs($this->editor)->getJson(route('projects.export.index', [$this->workspace->slug, $this->project->slug]))->assertForbidden();
        $this->assertSame(0, ProjectExport::count());
    }

    public function test_round_trip_restores_a_new_project_with_remapped_references(): void
    {
        ['parent' => $oldParent, 'child' => $oldChild] = $this->seedProject();
        $export = $this->export();
        $before = Project::count();

        $this->upload($this->zipPath($export))->assertRedirect();
        $run = ImportRun::where('source', 'brainitive')->sole();
        $this->assertSame(ImportRun::PREVIEWED, $run->status, (string) $run->error);
        $this->assertSame($before, Project::count()); // the preview writes nothing
        $this->assertSame(2, $run->preview['counts']['issues']);
        $this->assertSame('BRAIA', $run->preview['suggested']['key']);
        $editorRef = collect($run->preview['people'])->firstWhere('email', $this->editor->email);
        $this->assertSame($this->editor->id, $editorRef['member_id']); // matched by email

        $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]), ['options' => [
            'name' => 'Restored copy', 'key' => 'rest', 'people' => [$editorRef['ref'] => $this->editor->id],
        ]])->assertRedirect();
        $run->refresh();
        $this->assertSame(ImportRun::COMPLETED, $run->status, (string) $run->error);

        $new = Project::findOrFail($run->result['project_id']);
        $this->assertSame(['Restored copy', 'REST'], [$new->name, $new->key]);
        $this->assertNotSame($this->project->id, $new->id);
        $this->assertSame('admin', $this->owner->projectRole($new));
        $this->assertSame('editor', $this->editor->projectRole($new)); // mapped → added, not invited

        $parent = Item::where('project_id', $new->id)->where('title', 'Ünïcode parent ✓')->sole();
        $child = Item::where('project_id', $new->id)->where('title', 'Child')->sole();
        $this->assertNotSame($oldParent->id, $parent->id);
        $this->assertSame(['REST-1', 'REST-2'], [$parent->key, $child->key]);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame($this->editor->id, $parent->assigned_to);
        $this->assertSame(['Bug'], $parent->labels->pluck('name')->all());
        $this->assertNotContains($parent->labels->first()->id, $this->project->labels()->pluck('id')->all());
        $this->assertSame('Sprint 1', Sprint::find($parent->sprint_id)->name);
        $this->assertNotSame($this->board->id, $parent->board_id);
        $this->assertSame('Large', ItemField::where('project_id', $new->id)->sole()->options()->find($parent->fieldValues()->sole()->option_id)->label);
        $this->assertTrue(ItemLink::where('source_item_id', $parent->id)->where('target_item_id', $child->id)->where('link_type', 'blocks')->exists());
        $this->assertStringNotContainsString('data-mention', (string) $parent->description);
        $this->assertMatchesRegularExpression('#/storage/uploads/images/[0-9a-f-]+-m1\.png#', (string) $parent->description);
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage/', '', preg_replace('#.*src="([^"]+)".*#s', '$1', $parent->description)), '/'));

        $comments = Comment::where('item_id', $parent->id)->orderBy('id')->get();
        $this->assertCount(2, $comments);
        $this->assertStringContainsString('Originally by '.e($this->editor->name), $comments[0]->body);
        $this->assertStringNotContainsString('<script', $comments[0]->body);
        $this->assertSame($comments[0]->id, $comments[1]->parent_id);
        $this->assertSame($this->owner->id, $comments[0]->user_id);

        $attachment = Attachment::where('item_id', $parent->id)->sole();
        $this->assertSame('Spec.pdf', $attachment->original_filename);
        $this->assertStringStartsWith('attachments/'.$parent->id.'/', $attachment->path);
        $this->assertSame('%PDF-1.4 test', Storage::disk('public')->get($attachment->path));

        $folder = Page::where('project_id', $new->id)->where('name', 'Handbook')->sole();
        $welcome = Page::where('project_id', $new->id)->where('name', 'Welcome')->sole();
        $this->assertSame($folder->id, $welcome->parent_id);
        $this->assertSame('published', $welcome->status);
        $this->assertStringContainsString('Hello docs', $welcome->content['html']);
        $board = Page::where('project_id', $new->id)->where('name', 'Board')->sole();
        $this->assertSame($parent->id, $board->content['elements'][0]['customData']['itemId']);
        $this->assertNull($board->content['elements'][0]['link']);

        $this->assertSame($this->editor->id, TaskListItem::whereIn('task_list_id', TaskList::where('project_id', $new->id)->select('id'))->sole()->assignee_id);
        $plan = Plan::where('project_id', $new->id)->sole();
        $this->assertSame($parent->id, $plan->data['rows'][0]['itemId']);
        $this->assertNull($plan->share_token);
        $helpdesk = Helpdesk::where('project_id', $new->id)->sole();
        $this->assertSame('Hi!', $helpdesk->settings['welcome_message']);
        $this->assertNull($helpdesk->forwarding_local_part);
        $this->assertSame(0, $helpdesk->tickets()->count());
        $this->assertSame(2, $run->report['created']['issues']);

        // the source is untouched
        $this->assertSame(['BRAI-1', 'BRAI-2'], Item::where('project_id', $this->project->id)->orderBy('id')->pluck('key')->all());
        Storage::disk('local')->assertMissing('imports/'.$run->uuid.'/archive');
    }

    public function test_a_tampered_file_is_refused_before_anything_is_written(): void
    {
        $this->seedProject();
        $zip = $this->tampered($this->zipPath($this->export()), ['issues.json' => '{"issues":[],"links":[]}']);
        $before = Project::count();

        $this->upload($zip);
        $run = ImportRun::where('source', 'brainitive')->sole();
        $this->assertSame(ImportRun::FAILED, $run->status);
        $this->assertStringContainsString('checksum of issues.json', $run->error);
        $this->assertSame($before, Project::count());
    }

    public function test_an_unlisted_file_is_refused(): void
    {
        $zip = $this->tampered($this->zipPath($this->export()), ['notes.txt' => 'hello']);
        $this->upload($zip);
        $this->assertStringContainsString('doesn’t list (notes.txt)', ImportRun::where('source', 'brainitive')->sole()->error);
    }

    public function test_an_unsupported_version_is_refused(): void
    {
        $path = $this->zipPath($this->export());
        $zip = new ZipArchive;
        $zip->open($path);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->upload($this->tampered($path, ['manifest.json' => json_encode(['version' => 2] + $manifest)]));
        $this->assertStringContainsString('newer version of Brainitive', ImportRun::where('source', 'brainitive')->latest('id')->first()->error);

        $this->upload($this->tampered($path, ['manifest.json' => json_encode(['format' => 'trello'] + $manifest)]));
        $this->assertStringContainsString('isn’t a Brainitive project export', ImportRun::where('source', 'brainitive')->latest('id')->first()->error);
    }

    public function test_path_traversal_in_the_archive_is_refused(): void
    {
        $path = $this->zipPath($this->export());
        $this->upload($this->tampered($path, ['../evil.json' => '{}']));
        $this->assertStringContainsString('unsafe file name', ImportRun::where('source', 'brainitive')->latest('id')->first()->error);

        // a manifest that points outside its folder
        $zip = new ZipArchive;
        $zip->open($path);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();
        $manifest['files']['../../etc/passwd'] = str_repeat('a', 64);
        $this->upload($this->tampered($path, ['manifest.json' => json_encode($manifest)]));
        $this->assertStringContainsString('unsafe file name', ImportRun::where('source', 'brainitive')->latest('id')->first()->error);
        Storage::disk('local')->assertMissing('evil.json');
    }

    public function test_restoring_needs_permission_to_create_projects(): void
    {
        $zip = $this->zipPath($this->export());
        // the editor is a workspace "member": they can't create projects
        $this->upload($zip, $this->editor)->assertForbidden();
        $this->upload($zip, $this->outsider)->assertForbidden();
        $this->assertSame(0, ImportRun::where('source', 'brainitive')->count());
    }

    public function test_the_key_and_name_must_be_free(): void
    {
        $this->upload($this->zipPath($this->export()));
        $run = ImportRun::where('source', 'brainitive')->sole();
        $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]), ['options' => ['name' => $this->project->name, 'key' => 'brai']])
            ->assertSessionHasErrors(['options.name', 'options.key']);
        $this->assertSame(ImportRun::PREVIEWED, $run->fresh()->status);
    }

    public function test_a_crash_halfway_resumes_without_duplicates(): void
    {
        $this->seedProject();
        $this->upload($this->zipPath($this->export()));
        $run = ImportRun::where('source', 'brainitive')->sole();

        $crash = true;
        Comment::creating(function () use (&$crash) {
            if ($crash) {
                throw new \RuntimeException('database went away');
            }
        });
        try {
            $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]), ['options' => ['name' => 'Resumed', 'key' => 'RES']]);
        } catch (\RuntimeException) {
            // the sync queue rethrows; the job marked the run failed
        }
        $this->assertSame(ImportRun::FAILED, $run->fresh()->status);
        $new = Project::where('name', 'Resumed')->sole();
        $this->assertSame(1, Item::where('project_id', $new->id)->count()); // the parent, then its comment crashed

        $crash = false;
        $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]))->assertRedirect();
        $this->assertSame(ImportRun::COMPLETED, $run->fresh()->status);
        $this->assertSame(1, Project::where('name', 'Resumed')->count());
        $this->assertSame(['RES-1', 'RES-2'], Item::where('project_id', $new->id)->orderBy('id')->pluck('key')->all());
        $this->assertSame(2, Comment::whereIn('item_id', Item::where('project_id', $new->id)->select('id'))->count());
        $this->assertSame(1, Attachment::whereIn('item_id', Item::where('project_id', $new->id)->select('id'))->count());
        $this->assertSame(3, Page::where('project_id', $new->id)->count());
        $this->assertSame(1, ItemLink::whereIn('source_item_id', Item::where('project_id', $new->id)->select('id'))->count());
        $report = $run->fresh()->report['created'];
        $this->assertSame([1, 2, 2, 1, 3], [$report['project'], $report['issues'], $report['comments'], $report['attachments'], $report['pages']]); // counted once, despite the crash
    }

    public function test_the_settings_card_lists_the_exporters_exports(): void
    {
        $export = $this->export();
        $this->actingAs($this->owner)->getJson(route('projects.export.index', [$this->workspace->slug, $this->project->slug]))
            ->assertOk()
            ->assertJsonPath('exports.0.uuid', $export->uuid)
            ->assertJsonPath('exports.0.status', 'ready');
    }
}
