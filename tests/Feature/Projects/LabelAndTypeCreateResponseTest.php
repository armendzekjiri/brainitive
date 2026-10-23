<?php

namespace Tests\Feature\Projects;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Labels and issue types are created from Inertia forms (project settings, issue dialog) and from
 * axios (quick create). Inertia sends X-Requested-With too, and got JSON back — the settings
 * dialog stayed open (found by the e2e suite, BRAI-96). Inertia gets a page, axios the JSON.
 */
class LabelAndTypeCreateResponseTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = User::find($this->workspace->owner_id);
    }

    /** @return array<string, string> */
    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    }

    public function test_inertia_forms_get_a_page_and_axios_gets_json_for_labels(): void
    {
        $url = route('projects.labels.store', [$this->workspace->slug, $this->project->slug]);

        $this->actingAs($this->admin)->from($url)->post($url, ['name' => 'Bug', 'color' => '#ff0000'], $this->inertia())->assertRedirect();
        $this->actingAs($this->admin)->post($url, ['name' => 'Urgent', 'color' => '#ff0000'], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('name', 'Urgent');

        $this->assertEqualsCanonicalizing(['Bug', 'Urgent'], $this->project->labels()->pluck('name')->all());
    }

    public function test_inertia_forms_get_a_page_and_axios_gets_json_for_issue_types(): void
    {
        $url = route('projects.item-types.store', [$this->workspace->slug, $this->project->slug]);

        $this->actingAs($this->admin)->from($url)->post($url, ['name' => 'Spike'], $this->inertia())->assertRedirect();
        $this->actingAs($this->admin)->post($url, ['name' => 'Chore'], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('name', 'Chore');
    }
}
