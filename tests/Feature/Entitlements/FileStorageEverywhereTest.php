<?php

namespace Tests\Feature\Entitlements;

use App\Models\Bookmark;
use App\Models\EditorUpload;
use App\Models\Helpdesk;
use App\Models\TicketContact;
use App\Models\User;
use App\Models\WorkspaceUsage;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\WorkspaceUsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Codex web review C: every user-controlled stored file counts against the workspace's file storage, reserved
 * atomically before it's kept, released when it's deleted or superseded; abandoned temporary uploads are pruned and
 * files from before the accounting can be backfilled.
 */
class FileStorageEverywhereTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Storage::fake('public');
        $this->owner = User::find($this->workspace->owner_id);
    }

    private function fileLimit(int $bytes): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'file_bytes' => $bytes]));
        WorkspaceUsage::updateOrCreate(['workspace_id' => $this->workspace->id], ['file_bytes' => 0]);
    }

    private function usage(): int
    {
        return (int) WorkspaceUsage::where('workspace_id', $this->workspace->id)->value('file_bytes');
    }

    public function test_logos_are_reserved_counted_and_the_superseded_one_is_deleted(): void
    {
        $this->fileLimit(12000);
        $update = fn () => $this->actingAs($this->owner)->put(route('workspaces.update', $this->workspace->slug), [
            'name' => $this->workspace->name, 'logo' => UploadedFile::fake()->image('logo.png')->size(5),
        ]);

        $update()->assertSessionHasNoErrors();
        $first = $this->workspace->fresh()->logo;
        $update()->assertSessionHasNoErrors();
        $second = $this->workspace->fresh()->logo;

        // the replaced logo is gone and released: only one logo is stored and counted
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->assertSame(5120, $this->usage());
        $this->assertSame(5120, app(WorkspaceUsageMeter::class)->fileBytes($this->workspace));

        // a header that doesn't fit is refused before it's kept
        $this->actingAs($this->owner)->put(route('workspaces.update', $this->workspace->slug), [
            'name' => $this->workspace->name, 'header_image' => UploadedFile::fake()->image('h.png')->size(8),
        ])->assertSessionHas('limit_reached');
        $this->assertNull($this->workspace->fresh()->header_image);
        $this->assertCount(1, Storage::disk('public')->allFiles('workspaces'));
    }

    public function test_sequential_issue_attachments_stop_at_the_limit_and_deleting_releases(): void
    {
        $this->fileLimit(6000);
        $item = $this->item();
        $url = route('boards.items.attachments.store', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]);

        $this->actingAs($this->editor)->post($url, ['file' => UploadedFile::fake()->create('a.txt', 5)])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post($url, ['file' => UploadedFile::fake()->create('b.txt', 5)])->assertSessionHas('limit_reached');

        $this->assertSame(1, $item->attachments()->count());
        $this->assertSame(5120, $this->usage());
        $this->assertCount(1, Storage::disk('public')->allFiles('attachments'));

        $attachment = $item->attachments()->first();
        $this->actingAs($this->editor)->delete(route('boards.items.attachments.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id, $attachment->id]));
        $this->assertSame(0, $this->usage());
    }

    public function test_public_ticket_attachments_are_reserved_without_revealing_the_plan(): void
    {
        $this->fileLimit(6000);
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->owner->id]);
        $contact = TicketContact::create(['email' => 'c@example.com', 'uuid' => (string) str()->uuid()]);
        $ticket = $helpdesk->tickets()->create(['subject' => 'Help', 'column_id' => $helpdesk->columns()->first()->id, 'ticket_contact_id' => $contact->id, 'key' => 'T-1', 'position' => 1]);
        $url = route('helpdesks.tickets.attachments.store-public', [$helpdesk->uuid, $ticket->uuid]);

        $this->postJson($url, ['access_token' => $ticket->publicAccessToken(), 'file' => UploadedFile::fake()->create('a.pdf', 5)])->assertOk();
        $this->postJson($url, ['access_token' => $ticket->publicAccessToken(), 'file' => UploadedFile::fake()->create('b.pdf', 5)])
            ->assertStatus(402)->assertJsonMissingPath('limit_reached');
        $this->assertSame(1, $ticket->attachments()->count());
        $this->assertSame(5120, $this->usage());
    }

    public function test_bookmark_files_icons_and_temp_uploads_count_and_are_cleaned_up(): void
    {
        $this->fileLimit(100000);
        $this->workspace->users()->updateExistingPivot($this->editor->id, ['role' => 'editor']);
        $bookmark = Bookmark::create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'name' => 'Links', 'visibility' => 'private']);

        $this->actingAs($this->editor)->post(route('bookmarks.items.store', [$this->workspace->slug, $bookmark->slug]), [
            'type' => 'file', 'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $this->assertSame(10240, $this->usage());
        $this->assertSame(10240, app(WorkspaceUsageMeter::class)->fileBytes($this->workspace));

        $item = $bookmark->items()->first();
        $this->actingAs($this->editor)->delete(route('bookmarks.items.destroy', [$this->workspace->slug, $bookmark->slug, $item->id]));
        $this->assertSame(0, $this->usage());
        $this->assertSame(0, EditorUpload::count());

        // a temporary icon counts until it is pruned a day later
        $this->actingAs($this->editor)->postJson(route('bookmarks.icon.upload-temp', $this->workspace->slug), ['icon' => UploadedFile::fake()->image('i.png')->size(3)])->assertOk();
        $this->assertSame(3072, $this->usage());
        $this->travel(25)->hours();
        $this->artisan('files:prune-temp')->assertSuccessful();
        $this->assertSame(0, $this->usage());
        $this->assertSame([], Storage::disk('public')->allFiles('bookmark-icons/temp'));
    }

    public function test_files_from_before_the_accounting_are_backfilled_with_force_only(): void
    {
        Storage::disk('public')->put('workspaces/logos/old.png', str_repeat('x', 700));
        $this->workspace->forceFill(['logo' => 'workspaces/logos/old.png'])->save();

        $this->artisan('files:backfill-usage')->expectsOutputToContain('1 file(s) not counted yet')->assertSuccessful();
        $this->assertSame(0, EditorUpload::count());

        $this->artisan('files:backfill-usage', ['--force' => true])->assertSuccessful();
        $this->assertSame(['workspace_logo'], EditorUpload::pluck('kind')->all());
        $this->assertSame(700, app(WorkspaceUsageMeter::class)->fileBytes($this->workspace));
        $this->artisan('files:backfill-usage')->expectsOutputToContain('already counted')->assertSuccessful();
    }
}
