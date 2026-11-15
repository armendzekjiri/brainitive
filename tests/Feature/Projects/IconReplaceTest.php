<?php

namespace Tests\Feature\Projects;

use App\Models\Bookmark;
use App\Models\EditorUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * GitHub #154: every icon upload was stored as `…/{id}/icon.<ext>`, so replacing a custom icon returned the same
 * URL — the settings form stayed clean and browsers / CDNs kept the cached old picture. Each upload now gets its
 * own name, and the superseded file (new or old-style name) is still deleted.
 */
class IconReplaceTest extends TestCase
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

    private function uploadProjectIcon(): string
    {
        $display = $this->actingAs($this->owner)
            ->post(route('projects.icon.upload', [$this->workspace->slug, $this->project->slug]), ['icon' => UploadedFile::fake()->image('logo.png', 32, 32)])
            ->assertOk()->json('icon_display');
        $this->assertSame(asset('storage/'.$this->project->fresh()->getRawOriginal('icon')), $display);

        return $this->project->fresh()->getRawOriginal('icon');
    }

    public function test_replacing_a_project_icon_changes_its_url_and_deletes_the_old_file(): void
    {
        $first = $this->uploadProjectIcon();
        $this->assertMatchesRegularExpression('#^project-icons/'.$this->project->id.'/icon-[a-z0-9]{16}\.png$#', $first);
        Storage::disk('public')->assertExists($first);

        $second = $this->uploadProjectIcon();

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
        $this->assertSame([$second], EditorUpload::where('kind', 'project_icon')->pluck('path')->all());
    }

    public function test_the_settings_form_upload_replaces_the_icon_too(): void
    {
        $first = $this->uploadProjectIcon();

        $this->actingAs($this->owner)->put(route('projects.update', [$this->workspace->slug, $this->project->slug]), [
            'name' => $this->project->name, 'icon_image' => UploadedFile::fake()->image('new.png', 32, 32),
        ])->assertSessionHasNoErrors();

        $second = $this->project->fresh()->getRawOriginal('icon');
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_an_old_style_project_icon_is_deleted_when_replaced(): void
    {
        $old = 'project-icons/'.$this->project->id.'/icon.png';
        Storage::disk('public')->put($old, 'old');
        $this->project->forceFill(['icon' => $old])->save();

        $new = $this->uploadProjectIcon();

        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_a_stale_form_keeps_the_current_icon(): void
    {
        $first = $this->uploadProjectIcon();
        $second = $this->uploadProjectIcon(); // e.g. in another tab; the first file is gone

        // the form opened before the second upload sends the first icon's URL back
        $this->actingAs($this->owner)->put(route('projects.update', [$this->workspace->slug, $this->project->slug]), [
            'name' => 'Renamed', 'icon' => asset('storage/'.$first),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $this->project->fresh()->name);
        $this->assertSame($second, $this->project->fresh()->getRawOriginal('icon'));

        // the current icon sent back unchanged, an emoji, and a file of another project behave as before
        $this->actingAs($this->owner)->put(route('projects.update', [$this->workspace->slug, $this->project->slug]), [
            'name' => 'Renamed', 'icon' => asset('storage/'.$second),
        ])->assertSessionHasNoErrors();
        $this->assertSame($second, $this->project->fresh()->getRawOriginal('icon'));

        $this->actingAs($this->owner)->put(route('projects.update', [$this->workspace->slug, $this->project->slug]), [
            'name' => 'Renamed', 'icon' => 'project-icons/'.($this->project->id + 1).'/icon-abcdefgh12345678.png',
        ])->assertSessionHasErrors('icon');

        $this->actingAs($this->owner)->put(route('projects.update', [$this->workspace->slug, $this->project->slug]), [
            'name' => 'Renamed', 'icon' => '🚀',
        ])->assertSessionHasNoErrors();
        $this->assertSame('🚀', $this->project->fresh()->getRawOriginal('icon'));
    }

    public function test_replacing_a_bookmark_icon_changes_its_url_and_deletes_the_old_file(): void
    {
        $bookmark = Bookmark::create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id, 'name' => 'Links', 'visibility' => 'private']);
        $old = 'bookmark-icons/'.$bookmark->id.'/icon.png';
        Storage::disk('public')->put($old, 'old');
        $bookmark->forceFill(['icon' => $old])->save();

        $upload = fn () => $this->actingAs($this->owner)
            ->post(route('bookmarks.icon.upload', [$this->workspace->slug, $bookmark->slug]), ['icon' => UploadedFile::fake()->image('i.png', 32, 32)])
            ->assertOk()->json('icon_display');

        $firstUrl = $upload();
        $first = $bookmark->fresh()->getRawOriginal('icon');
        $this->assertMatchesRegularExpression('#^bookmark-icons/'.$bookmark->id.'/icon-[a-z0-9]{16}\.png$#', $first);
        Storage::disk('public')->assertMissing($old); // old-style name cleaned up

        $secondUrl = $upload();
        $second = $bookmark->fresh()->getRawOriginal('icon');
        $this->assertNotSame($firstUrl, $secondUrl);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);

        // the bookmark form's icon_image upload as well
        $this->actingAs($this->owner)->put(route('bookmarks.update', [$this->workspace->slug, $bookmark->slug]), [
            'icon_image' => UploadedFile::fake()->image('j.png', 32, 32),
        ])->assertSessionHasNoErrors();
        $third = $bookmark->fresh()->getRawOriginal('icon');
        $this->assertNotSame($second, $third);
        Storage::disk('public')->assertExists($third);
        Storage::disk('public')->assertMissing($second);
    }
}
