<?php

namespace Tests\Feature\AppApi;

use App\Models\FeedbackPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Feedback\InteractsWithFeedback;
use Tests\TestCase;

/**
 * App API v1 feedback portals, team side (BRAI-422): the portal and its moderation tabs, an idea's public status
 * and moderation through FeedbackModeration (shared with the web). Project admins only (403 for other members,
 * 404 for outsiders); voters are portal users, never App API users.
 */
class PortalsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithFeedback;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFeedback();
        $this->token = $this->appToken($this->admin);
    }

    public function test_the_portal_and_its_moderation_tabs(): void
    {
        $author = $this->identity('voter@example.test', 'Vera Voter');
        $low = $this->feedbackPost(['votes_count' => 1, 'author_identity_id' => $author->id]);
        $top = $this->feedbackPost(['votes_count' => 9]);
        $pending = $this->feedbackPost(['moderation' => 'pending', 'approved_at' => null]);
        $headers = $this->appHeaders($this->token);

        $portal = $this->getJson("/api/v1/projects/{$this->project->id}/feedback-portal", $headers)->assertOk()
            ->assertJsonPath('data.slug', 'acme-ideas')
            ->assertJsonPath('data.counts', ['pending' => 1, 'published' => 2, 'rejected' => 0, 'merged' => 0])
            ->json('data');

        $first = $this->getJson("/api/v1/feedback-portals/{$portal['id']}/posts?limit=1", $headers)->assertOk()
            ->assertJsonPath('data.0.id', $top->id);
        $this->getJson("/api/v1/feedback-portals/{$portal['id']}/posts?limit=1&cursor=".urlencode($first->json('next_cursor')), $headers)->assertOk()
            ->assertJsonPath('data.0.id', $low->id)
            ->assertJsonPath('data.0.author', ['name' => 'Vera Voter', 'email' => 'voter@example.test']);
        $this->getJson("/api/v1/feedback-portals/{$portal['id']}/posts?tab=pending", $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id)->assertJsonPath('data.0.moderation', 'pending');
        $this->getJson("/api/v1/feedback-portals/{$portal['id']}/posts?tab=spam", $headers)->assertUnprocessable()->assertJsonValidationErrors('tab');

        // members who aren't admins: 403 (as the web's Feedback page); outsiders: 404
        $editor = $this->appHeaders($this->appToken($this->editor));
        $this->getJson("/api/v1/projects/{$this->project->id}/feedback-portal", $editor)->assertForbidden();
        $this->getJson("/api/v1/feedback-portals/{$portal['id']}/posts", $editor)->assertForbidden();
        $outsider = $this->appHeaders($this->appToken($this->outsider));
        $this->getJson("/api/v1/projects/{$this->project->id}/feedback-portal", $outsider)->assertNotFound();
        $this->getJson("/api/v1/feedback-portals/{$portal['id']}/posts", $outsider)->assertNotFound();
    }

    public function test_status_and_moderation(): void
    {
        $post = $this->feedbackPost();
        $pending = $this->feedbackPost(['moderation' => 'pending', 'approved_at' => null]);
        $headers = $this->appHeaders($this->token);

        $this->patchJson("/api/v1/feedback-posts/{$post->id}", ['status' => 'planned', 'note' => 'Q1'], $headers)->assertOk()
            ->assertJsonPath('data.status', 'planned')->assertJsonPath('data.status_note', 'Q1');
        $this->postJson("/api/v1/feedback-posts/{$pending->id}/moderate", ['decision' => 'approve'], $headers)->assertOk()
            ->assertJsonPath('data.moderation', 'approved');
        $this->assertSame('approved', $pending->fresh()->moderation);

        $this->patchJson("/api/v1/feedback-posts/{$post->id}", ['status' => 'someday'], $headers)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson("/api/v1/feedback-posts/{$post->id}", ['status' => 'released'], $this->appHeaders($this->appToken($this->editor)))->assertForbidden();
        $this->patchJson("/api/v1/feedback-posts/{$post->id}", ['status' => 'nope'], $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
        $this->postJson('/api/v1/feedback-posts/abc/moderate', ['decision' => 'reject'], $headers)->assertNotFound();

        $merged = $this->feedbackPost(['merged_into_id' => $post->id]);
        $this->patchJson("/api/v1/feedback-posts/{$merged->id}", ['status' => 'released'], $headers)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('planned', FeedbackPost::find($post->id)->status);
    }

    public function test_tokens_of_other_clients_and_no_token_are_401(): void
    {
        $this->getJson("/api/v1/projects/{$this->project->id}/feedback-portal")->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson("/api/v1/projects/{$this->project->id}/client-portal", $this->appHeaders($this->passportToken($this->admin)))->assertUnauthorized();
    }
}
