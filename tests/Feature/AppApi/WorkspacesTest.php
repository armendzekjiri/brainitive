<?php

namespace Tests\Feature\AppApi;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\TestCase;

/** App API v1 first-run "create a workspace" (BRAI-386): the web form's rules and limits. */
class WorkspacesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use RefreshDatabase;

    public function test_creates_a_workspace_with_the_user_as_admin(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/workspaces', ['name' => 'My team', 'description' => 'Hello'], $this->appHeaders($this->appToken($user)))
            ->assertCreated();

        $workspace = Workspace::where('owner_id', $user->id)->sole();
        $response->assertExactJson(['data' => ['id' => $workspace->id, 'slug' => $workspace->slug, 'name' => 'My team', 'role' => 'admin']]);
        $this->assertSame('Hello', $workspace->description);
        $this->assertSame('admin', $user->workspaceRole($workspace));
    }

    public function test_validates_like_the_web_form(): void
    {
        $user = User::factory()->create();
        $token = $this->appToken($user);

        $this->postJson('/api/v1/workspaces', ['name' => ''], $this->appHeaders($token))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/workspaces', ['name' => str_repeat('a', 256), 'description' => str_repeat('b', 1001)], $this->appHeaders($token))
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'description']);

        // a name is unique per owner, not globally
        Workspace::factory()->create(['name' => 'Taken', 'owner_id' => $user->id]);
        Workspace::factory()->create(['name' => 'Elsewhere']);
        $this->postJson('/api/v1/workspaces', ['name' => 'Taken'], $this->appHeaders($token))
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/workspaces', ['name' => 'Elsewhere'], $this->appHeaders($token))->assertCreated();
    }

    public function test_the_free_workspaces_per_owner_limit_answers_402(): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'free_workspaces_per_owner' => 1]));
        $user = User::factory()->create();
        $token = $this->appToken($user);

        $this->postJson('/api/v1/workspaces', ['name' => 'First'], $this->appHeaders($token))->assertCreated();
        $this->postJson('/api/v1/workspaces', ['name' => 'Second'], $this->appHeaders($token))
            ->assertStatus(402)
            ->assertJsonPath('code', 'limit_reached')
            ->assertJsonPath('limit.kind', 'workspaces')
            ->assertJsonPath('limit.limit', 1);

        $this->assertSame(1, Workspace::where('owner_id', $user->id)->count());
    }

    public function test_an_unverified_email_cannot_create_one(): void
    {
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/v1/workspaces', ['name' => 'Mine'], $this->appHeaders($this->appToken($user)))
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->assertSame(0, Workspace::where('owner_id', $user->id)->count());
    }

    public function test_passport_tokens_and_no_token_get_401(): void
    {
        $user = User::factory()->create();
        $passport = $this->passportToken($user);

        $this->postJson('/api/v1/workspaces', ['name' => 'Mine'])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/workspaces', ['name' => 'Mine'], $this->appHeaders($passport))->assertStatus(401);
        $this->assertSame(0, Workspace::where('owner_id', $user->id)->count());
    }
}
