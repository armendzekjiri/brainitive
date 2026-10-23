<?php

namespace Tests\Feature\Releases;

use App\Models\ActivityLog;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class ReleaseTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function r(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, ...$extra]);
    }

    private function release(array $attributes = []): Release
    {
        return Release::factory()->create($attributes + ['project_id' => $this->project->id]);
    }

    public function test_editors_plan_releases_viewers_only_read(): void
    {
        $this->actingAs($this->viewer)->post($this->r('releases.store'), ['name' => 'v1.0'])->assertForbidden();
        $this->actingAs($this->editor)->post($this->r('releases.store'), ['name' => 'v1.0', 'start_date' => '2026-11-01', 'release_date' => '2026-11-15'])->assertRedirect();
        $this->actingAs($this->editor)->post($this->r('releases.store'), ['name' => 'v1.0'])->assertSessionHasErrors('name');
        $this->actingAs($this->editor)->post($this->r('releases.store'), ['name' => 'v2', 'start_date' => '2026-11-15', 'release_date' => '2026-11-01'])->assertSessionHasErrors('release_date');

        $release = Release::sole();
        $this->actingAs($this->viewer)->get($this->r('releases.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Releases/Index')->where('canManage', false)->has('releases', 1));
        $this->actingAs($this->outsider)->get($this->r('releases.index'))->assertForbidden();

        // A release of another project isn't reachable through this one.
        $foreign = Release::factory()->create();
        $this->actingAs($this->editor)->get($this->r('releases.show', [$foreign->id]))->assertNotFound();
        $this->actingAs($this->viewer)->get($this->r('releases.show', [$release->id]))->assertOk();
    }

    public function test_fix_version_on_issues_and_progress(): void
    {
        $release = $this->release(['name' => 'v1.0']);
        $archived = $this->release(['name' => 'old', 'status' => Release::STATUS_ARCHIVED]);
        $foreign = Release::factory()->create();
        [$todo, $doing, $done] = [$this->item(), $this->item(['column_id' => $this->column('in-progress')]), $this->item(['column_id' => $this->column('done'), 'completed_at' => now()])];

        $update = fn ($item, $releaseId) => $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['release_id' => $releaseId]);
        $update($todo, $foreign->id)->assertSessionHasErrors('release_id');
        $update($todo, $archived->id)->assertSessionHasErrors('release_id');
        foreach ([$todo, $doing] as $item) {
            $update($item, $release->id)->assertSessionHasNoErrors();
        }
        $this->actingAs($this->editor)->post($this->r('releases.items.add', [$release->id]), ['item_ids' => [$done->id, $foreign->project_id * 1000]])->assertRedirect();

        $this->assertSame(['total' => 3, 'done' => 1, 'in_progress' => 1, 'todo' => 1], $release->progress());
        $this->assertTrue(ActivityLog::where('loggable_id', $todo->id)->where('changes', 'like', '%v1.0%')->exists(), 'fix version change is in the history');

        $this->actingAs($this->viewer)->getJson($this->itemRoute('boards.items.details', $todo))
            ->assertJsonPath('release_id', $release->id)
            ->assertJsonMissing(['name' => 'old']);
    }

    public function test_releasing_moves_unfinished_issues_where_asked(): void
    {
        $release = $this->release(['name' => 'v1.0']);
        $next = $this->release(['name' => 'v1.1']);
        $open = $this->item(['release_id' => $release->id]);
        $done = $this->item(['release_id' => $release->id, 'completed_at' => now()]);

        $this->actingAs($this->editor)->post($this->r('releases.release', [$release->id]), ['release_date' => '2026-10-23', 'move_unfinished_to' => $release->id])->assertSessionHasErrors();
        $this->actingAs($this->editor)->post($this->r('releases.release', [$release->id]), ['release_date' => '2026-10-23', 'move_unfinished_to' => $next->id])->assertSessionHasNoErrors();

        $this->assertSame(Release::STATUS_RELEASED, $release->fresh()->status);
        $this->assertSame($next->id, $open->fresh()->release_id);
        $this->assertSame($release->id, $done->fresh()->release_id);
        $this->actingAs($this->editor)->post($this->r('releases.release', [$release->id]), ['release_date' => '2026-10-23'])->assertSessionHasErrors('release');

        // Without a target unfinished issues lose their fix version.
        $this->item(['release_id' => $next->id]);
        $this->actingAs($this->editor)->post($this->r('releases.release', [$next->id]), ['release_date' => '2026-10-24']);
        $this->assertSame(0, $next->items()->whereNull('completed_at')->count());

        // Archive / reopen.
        $this->actingAs($this->editor)->post($this->r('releases.status', [$release->id]), ['status' => 'archived'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post($this->r('releases.status', [$release->id]), ['status' => 'unreleased'])->assertSessionHasNoErrors();
        $this->assertNull($release->fresh()->released_at);
    }

    public function test_notes_draft_publish_update_and_unpublish(): void
    {
        $bug = ItemType::where('slug', 'bug')->value('id');
        $story = ItemType::where('slug', 'story')->value('id');
        $release = $this->release(['name' => 'v2.0']);
        $this->item(['release_id' => $release->id, 'completed_at' => now(), 'title' => 'Login with Google', 'item_type_id' => $story]);
        $fixed = $this->item(['release_id' => $release->id, 'completed_at' => now(), 'title' => 'Crash on <empty> board', 'item_type_id' => $bug]);
        $this->item(['release_id' => $release->id, 'title' => 'Unfinished secret thing']);

        $draft = $this->actingAs($this->editor)->getJson($this->r('releases.notes.draft', [$release->id]))->assertOk()->json('html');
        $this->assertStringContainsString('<h2>Features</h2><ul><li>Login with Google</li></ul>', $draft);
        $this->assertStringContainsString('<h2>Fixes</h2><ul><li>Crash on &lt;empty&gt; board</li></ul>', $draft);
        $this->assertStringNotContainsString('Unfinished secret', $draft);
        $this->assertStringNotContainsString($fixed->key, $draft);
        $this->actingAs($this->viewer)->getJson($this->r('releases.notes.draft', [$release->id]))->assertForbidden();

        $this->actingAs($this->editor)->post($this->r('releases.publish', [$release->id]))->assertSessionHasErrors('notes');
        $this->actingAs($this->editor)->put($this->r('releases.notes.save', [$release->id]), ['notes' => $draft.'<script>alert(1)</script><a href="javascript:alert(1)">x</a>'])->assertRedirect();
        $this->actingAs($this->editor)->post($this->r('releases.publish', [$release->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post($this->r('releases.publish', [$release->id]));
        $release->refresh();
        $token = $release->public_token;

        $this->get(route('releases.public', $token))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Releases/Public')->where('releases.0.name', 'v2.0')
                ->where('releases.0.html', fn ($html) => str_contains($html, 'Login with Google') && ! str_contains($html, '<script') && ! str_contains($html, 'javascript:')));

        // Draft edits stay private until published again.
        $this->actingAs($this->editor)->put($this->r('releases.notes.save', [$release->id]), ['notes' => '<p>Draft only</p>']);
        $this->get(route('releases.public', $token))->assertInertia(fn (AssertableInertia $p) => $p->where('releases.0.html', fn ($html) => ! str_contains($html, 'Draft only')));

        $changelog = route('changelog.public', $this->project->fresh()->changelog_token);
        $this->get($changelog)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('releases', 1));

        $this->actingAs($this->editor)->delete($this->r('releases.unpublish', [$release->id]))->assertRedirect();
        $this->get(route('releases.public', $token))->assertNotFound();
        $this->get($changelog)->assertNotFound();
    }

    public function test_deleting_a_release_keeps_its_issues(): void
    {
        $release = $this->release();
        $item = $this->item(['release_id' => $release->id]);
        $this->actingAs($this->viewer)->delete($this->r('releases.destroy', [$release->id]))->assertForbidden();
        $this->actingAs($this->editor)->delete($this->r('releases.destroy', [$release->id]))->assertRedirect();
        $this->assertNull($item->fresh()->release_id);
        $this->assertModelMissing($release);
        $this->assertNotNull(Project::find($this->project->id));
    }
}
