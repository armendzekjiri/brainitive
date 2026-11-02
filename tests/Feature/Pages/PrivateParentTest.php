<?php

namespace Tests\Feature\Pages;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/**
 * Codex web review G: a project editor must not reach another author's private draft through a child page — not by
 * creating a child under its guessed id, and not through the child's serialized parent or breadcrumbs.
 */
class PrivateParentTest extends TestCase
{
    use ActsAsAppClient;
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
    }

    public function test_a_child_cannot_be_created_under_a_draft_the_editor_cannot_view(): void
    {
        $draft = $this->draftPage(['name' => 'Secret plan', 'content' => $this->html('<p>Layoffs in March</p>')]);
        $this->assertFalse($this->editor->can('view', $draft));

        $this->actingAs($this->editor)->post(route('pages.store', [$this->workspace->slug, $this->project->slug]), [
            'name' => 'Child', 'type' => 'page', 'parent_id' => $draft->id,
        ])->assertSessionHasErrors('parent_id');
        $this->assertSame(0, Page::where('parent_id', $draft->id)->count());

        $this->actingAs($this->editor)->postJson(route('pages.store', [$this->workspace->slug, $this->project->slug]), [
            'name' => 'Child', 'type' => 'page', 'parent_id' => $draft->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        // the author still can
        $this->actingAs($this->author)->post(route('pages.store', [$this->workspace->slug, $this->project->slug]), [
            'name' => 'Child', 'type' => 'page', 'parent_id' => $draft->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Page::where('parent_id', $draft->id)->count());
    }

    public function test_a_visible_child_never_serializes_an_invisible_parent(): void
    {
        $draft = $this->draftPage(['name' => 'Secret plan', 'content' => $this->html('<p>Layoffs in March</p>')]);
        $child = $this->publishedPage(['name' => 'Public child', 'parent_id' => $draft->id]);
        $this->assertTrue($this->editor->can('view', $child));

        $response = $this->actingAs($this->editor)->get($this->pageRoute('pages.show', $child))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('page.parent')
                ->where('breadcrumbs', fn ($crumbs) => collect($crumbs)->pluck('name')->all() === ['Public child'])
            );
        $response->assertDontSee('Layoffs in March')->assertDontSee('Secret plan');

        // App API detail: breadcrumbs skip it too
        $this->getJson('/api/v1/pages/'.$child->uuid, $this->appHeaders($this->appToken($this->editor)))->assertOk()
            ->assertJsonPath('data.breadcrumbs', []);

        // the author sees the parent in the breadcrumbs, still without its content
        $this->actingAs($this->author)->get($this->pageRoute('pages.show', $child))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('page.parent')
                ->where('breadcrumbs', fn ($crumbs) => collect($crumbs)->pluck('name')->all() === ['Secret plan', 'Public child'])
            );
    }

    public function test_the_app_api_refuses_an_invisible_parent(): void
    {
        $draft = $this->draftPage(['name' => 'Secret plan']);

        $this->postJson('/api/v1/projects/'.$this->project->id.'/pages', ['name' => 'Child', 'kind' => 'doc', 'parent_uuid' => $draft->uuid],
            $this->appHeaders($this->appToken($this->editor)))->assertStatus(422);
        $this->assertSame(0, Page::where('parent_id', $draft->id)->count());
    }

    public function test_a_duplicate_does_not_land_under_an_invisible_parent(): void
    {
        $draft = $this->draftPage(['name' => 'Secret plan']);
        $child = $this->publishedPage(['name' => 'Public child', 'parent_id' => $draft->id]);

        $this->actingAs($this->editor)->post($this->pageRoute('pages.duplicate', $child))->assertRedirect();
        $copy = Page::where('name', 'Copy of Public child')->firstOrFail();
        $this->assertNull($copy->parent_id);

        $this->actingAs($this->author)->post($this->pageRoute('pages.duplicate', $child))->assertRedirect();
        $this->assertSame($draft->id, Page::where('name', 'Copy of Public child')->where('created_by', $this->author->id)->value('parent_id'));
    }
}
