<?php

namespace Tests\Feature\Ai;

use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class ProjectAiTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->workspace->update(['settings' => [
            'ai_endpoint' => 'https://93.184.216.34',
            'ai_api_key' => Crypt::encryptString('sk-test'),
            'ai_model' => 'test-model',
        ]]);
        $this->project->update(['name' => 'Invoice Portal', 'description' => '<p>A portal where small businesses upload, approve and pay supplier invoices, with an approval flow for managers and CSV export for accountants.</p>']);
        Http::fake(['93.184.216.34/*' => Http::response(['choices' => [['message' => ['content' => '<p>Generated</p>']]]])]);
    }

    private function systemPrompt(): string
    {
        $sent = Http::recorded()->last()[0];

        return $sent->data()['messages'][0]['content'];
    }

    public function test_editor_ai_gets_the_project_as_context(): void
    {
        $this->actingAs($this->editor)->postJson(route('ai.generate', $this->workspace->slug), [
            'content' => '<p>fix export</p>',
            'project_id' => $this->project->id,
        ])->assertOk()->assertJson(['success' => true, 'content' => '<p>Generated</p>']);

        $this->assertStringContainsString('Project: Invoice Portal', $this->systemPrompt());
        $this->assertStringContainsString('approval flow for managers', $this->systemPrompt());

        // A project the user can't open adds nothing.
        $outsiderProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'name' => 'Secret Merger', 'description' => 'Top secret plans']);
        $this->actingAs($this->editor)->postJson(route('ai.generate', $this->workspace->slug), ['content' => 'x', 'project_id' => $outsiderProject->id])->assertOk();
        $this->assertStringNotContainsString('Secret Merger', $this->systemPrompt());
    }

    public function test_project_assistant_modes(): void
    {
        $route = route('ai.project-assist', [$this->workspace->slug, $this->project->slug]);

        $this->actingAs($this->viewer)->postJson($route, ['mode' => 'summary'])->assertOk()->assertJsonPath('content', '<p>Generated</p>');
        $this->assertStringContainsString('concise project summary', $this->systemPrompt());

        $this->actingAs($this->viewer)->postJson($route, ['mode' => 'task'])->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->actingAs($this->viewer)->postJson($route, ['mode' => 'prompt', 'request' => 'Password reset'])->assertOk();
        $this->assertSame('Work to get a prompt for: Password reset', Http::recorded()->last()[0]->data()['messages'][1]['content']);

        $this->actingAs($this->outsider)->postJson($route, ['mode' => 'summary'])->assertForbidden();
    }

    public function test_short_description_asks_for_more_context(): void
    {
        $this->project->update(['description' => 'Invoices']);
        Http::fake();

        $this->actingAs($this->editor)->postJson(route('ai.project-assist', [$this->workspace->slug, $this->project->slug]), ['mode' => 'summary'])
            ->assertUnprocessable()
            ->assertJson(['needs_description' => true]);
        Http::assertNothingSent();
    }

    public function test_save_result_as_a_sanitized_draft_doc(): void
    {
        $route = route('ai.project-assist.save', [$this->workspace->slug, $this->project->slug]);

        $url = $this->actingAs($this->editor)->postJson($route, ['title' => 'Invoice Portal — summary', 'html' => '<p>Good</p><img src=x onerror=alert(1)>'])
            ->assertOk()->json('url');

        $page = Page::where('name', 'Invoice Portal — summary')->firstOrFail();
        $this->assertStringContainsString($page->uuid, $url);
        $this->assertNotSame('published', $page->status);
        $this->assertStringNotContainsString('onerror', $page->content['html']);

        $this->actingAs($this->viewer)->postJson($route, ['title' => 'x', 'html' => '<p>x</p>'])->assertForbidden();
    }
}
