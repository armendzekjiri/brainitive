<?php

namespace Tests\Feature\Bookmarks;

use App\Models\Bookmark;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Bookmarks\Bookmarks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bookmark page header (GitHub #152): description + collaborators with roles and avatars, managed
 * from the header popover through the existing collaborator routes.
 */
class BookmarkHeaderDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $editor;

    private User $viewer;

    private User $outsider;

    private Bookmark $bookmark;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->owner = User::factory()->create(['name' => 'Olga Owner', 'avatar' => 'avatars/olga.png']);
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->addUser($this->owner, 'admin');
        $this->editor = $this->member('Eddie Editor');
        $this->viewer = $this->member('Vera Viewer');
        $this->outsider = $this->member('Otto Other');

        $this->bookmark = Bookmarks::createGroup($this->workspace, $this->owner, [
            'name' => 'Reading list',
            'description' => "Articles to read\nbefore the offsite",
        ]);
        $this->bookmark->collaborators()->attach([
            $this->editor->id => ['role' => 'editor'],
            $this->viewer->id => ['role' => 'viewer'],
        ]);
    }

    private function member(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->workspace->addUser($user, 'editor');

        return $user;
    }

    private function url(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->bookmark->slug, ...$extra]);
    }

    public function test_show_sends_the_description_owner_and_collaborators_with_roles_and_avatars(): void
    {
        $response = $this->actingAs($this->viewer)->get($this->url('bookmarks.show'))->assertOk();
        $bookmark = json_decode(json_encode($response->viewData('page')['props']['bookmark']), true);

        $this->assertSame("Articles to read\nbefore the offsite", $bookmark['description']);
        $this->assertSame('Olga Owner', $bookmark['creator']['name']);
        $this->assertStringEndsWith('storage/avatars/olga.png', $bookmark['creator']['avatar_display']);

        $roles = collect($bookmark['collaborators'])->mapWithKeys(fn (array $c) => [$c['name'] => $c['pivot']['role']])->all();
        $this->assertSame(['Eddie Editor' => 'editor', 'Vera Viewer' => 'viewer'], $roles);
        $this->assertArrayHasKey('avatar_display', $bookmark['collaborators'][0]);
        $this->assertFalse($response->viewData('page')['props']['canEdit']);
    }

    public function test_an_editor_collaborator_manages_people_from_the_header(): void
    {
        $this->actingAs($this->editor)
            ->post($this->url('bookmarks.collaborators.invite'), ['user_id' => $this->outsider->id, 'role' => 'viewer'])
            ->assertSessionHas('success');
        $this->actingAs($this->editor)
            ->put($this->url('bookmarks.collaborators.update', [$this->outsider->id]), ['role' => 'editor'])
            ->assertSessionHas('success');
        $this->assertSame('editor', $this->bookmark->collaborators()->find($this->outsider->id)->pivot->role);

        $this->actingAs($this->editor)
            ->delete($this->url('bookmarks.collaborators.remove', [$this->outsider->id]))
            ->assertSessionHas('success');
        $this->assertFalse($this->bookmark->collaborators()->where('user_id', $this->outsider->id)->exists());
    }

    public function test_a_viewer_collaborator_can_not_change_people_or_the_description(): void
    {
        $this->actingAs($this->viewer)
            ->post($this->url('bookmarks.collaborators.invite'), ['user_id' => $this->outsider->id, 'role' => 'editor'])
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->put($this->url('bookmarks.collaborators.update', [$this->editor->id]), ['role' => 'viewer'])
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->delete($this->url('bookmarks.collaborators.remove', [$this->editor->id]))
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->put($this->url('bookmarks.update'), ['name' => 'Reading list', 'description' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame("Articles to read\nbefore the offsite", $this->bookmark->fresh()->description);
    }

    public function test_the_header_description_saves_and_clears(): void
    {
        $this->actingAs($this->editor)
            ->put($this->url('bookmarks.update'), ['name' => 'Reading list', 'description' => 'Only the good ones'])
            ->assertSessionHas('success');
        $this->assertSame('Only the good ones', $this->bookmark->fresh()->description);

        $this->actingAs($this->editor)
            ->put($this->url('bookmarks.update'), ['name' => 'Reading list', 'description' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->bookmark->fresh()->description);
    }
}
