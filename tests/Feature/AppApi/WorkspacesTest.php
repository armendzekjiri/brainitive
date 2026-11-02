<?php

namespace Tests\Feature\AppApi;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\TestCase;

/**
 * App API v1 workspaces: the list and one workspace (BRAI-388), and the first-run "create a
 * workspace" (BRAI-386) with the web form's rules and limits.
 */
class WorkspacesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use RefreshDatabase;

    private const SUMMARY_FIELDS = ['id', 'slug', 'name', 'role', 'is_owner', 'logo_url', 'url', 'created_at', 'updated_at'];

    public function test_creates_a_workspace_with_the_user_as_admin(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/workspaces', ['name' => 'My team', 'description' => 'Hello'], $this->appHeaders($this->appToken($user)))
            ->assertCreated();

        $workspace = Workspace::where('owner_id', $user->id)->sole();
        $response->assertJson(['data' => ['id' => $workspace->id, 'slug' => $workspace->slug, 'name' => 'My team', 'role' => 'admin', 'is_owner' => true]]);
        $this->assertSame(self::SUMMARY_FIELDS, array_keys($response->json('data')));
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

    public function test_lists_the_users_workspaces_by_name_with_their_role(): void
    {
        $user = User::factory()->create();
        $beta = Workspace::factory()->create(['name' => 'Beta']);
        $beta->addUser($user, 'viewer');
        $alpha = Workspace::factory()->create(['name' => 'Alpha', 'owner_id' => $user->id]);
        $alpha->addUser($user, 'admin');
        Workspace::factory()->create(['name' => 'Aaa not mine']);

        $response = $this->getJson('/api/v1/workspaces', $this->appHeaders($this->appToken($user)))->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('next_cursor', null)
            ->assertJsonPath('data.0.slug', $alpha->slug)
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.0.is_owner', true)
            ->assertJsonPath('data.1.slug', $beta->slug)
            ->assertJsonPath('data.1.role', 'viewer')
            ->assertJsonPath('data.1.is_owner', false);
        $this->assertSame(['data', 'next_cursor'], array_keys($response->json()));
        $this->assertSame(self::SUMMARY_FIELDS, array_keys($response->json('data.0')));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $response->json('data.0.updated_at'));
    }

    public function test_the_list_is_cursor_paginated_and_filters_by_updated_since(): void
    {
        $user = User::factory()->create();
        foreach (['A', 'B', 'C'] as $name) {
            Workspace::factory()->create(['name' => $name, 'updated_at' => now()->subDays(10)])->addUser($user, 'member');
        }
        $token = $this->appToken($user);

        $first = $this->getJson('/api/v1/workspaces?limit=2', $this->appHeaders($token))->assertOk();
        $first->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'A')->assertJsonPath('data.1.name', 'B');
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $this->getJson('/api/v1/workspaces?limit=2&cursor='.urlencode($cursor), $this->appHeaders($token))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'C')->assertJsonPath('next_cursor', null);

        Workspace::where('name', 'B')->update(['updated_at' => now()]);
        $this->getJson('/api/v1/workspaces?updated_since='.urlencode(now()->subDay()->toIso8601ZuluString()), $this->appHeaders($token))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'B');

        $this->getJson('/api/v1/workspaces?limit=101', $this->appHeaders($token))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('limit');
        $this->getJson('/api/v1/workspaces?updated_since=yesterday-ish', $this->appHeaders($token))
            ->assertStatus(422)->assertJsonValidationErrors('updated_since');
    }

    public function test_shows_one_workspace_with_permissions_counts_and_limits(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'Acme', 'timezone' => 'Europe/Berlin']);
        $workspace->addUser($user, 'editor');
        $workspace->addUser(User::factory()->create(), 'member');
        $mine = Project::factory()->create(['workspace_id' => $workspace->id]);
        $mine->addUser($user, 'editor');
        Project::factory()->create(['workspace_id' => $workspace->id]); // not a member: not counted for them

        $response = $this->getJson("/api/v1/workspaces/{$workspace->slug}", $this->appHeaders($this->appToken($user)))->assertOk();

        $response->assertJsonPath('data.slug', $workspace->slug)
            ->assertJsonPath('data.role', 'editor')
            ->assertJsonPath('data.timezone', 'Europe/Berlin')
            ->assertJsonPath('data.counts.projects', 1)
            ->assertJsonPath('data.counts.members', 2)
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.invite_members', false)
            ->assertJsonPath('data.can.create_projects', true)
            ->assertJsonPath('data.limits.unlimited', true)
            ->assertJsonPath('data.usage.projects', 2)
            ->assertJsonPath('data.usage.members', 2)
            ->assertJsonPath('data.billing_url', null);
        $this->assertArrayNotHasKey('settings', $response->json('data'));
        $this->assertArrayNotHasKey('owner_id', $response->json('data'));
    }

    public function test_show_reports_cloud_limits(): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'members' => 3, 'projects' => 5]));
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->addUser($user, 'admin');

        $this->getJson("/api/v1/workspaces/{$workspace->slug}", $this->appHeaders($this->appToken($user)))->assertOk()
            ->assertJsonPath('data.limits_enforced', true)
            ->assertJsonPath('data.limits.members', 3)
            ->assertJsonPath('data.limits.projects', 5)
            ->assertJsonPath('data.limits.unlimited', false)
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.invite_members', true);
    }

    public function test_another_workspace_is_a_404_and_tokens_are_checked(): void
    {
        $user = User::factory()->create();
        $foreign = Workspace::factory()->create();
        $token = $this->appToken($user);

        $this->getJson("/api/v1/workspaces/{$foreign->slug}", $this->appHeaders($token))
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson('/api/v1/workspaces/does-not-exist', $this->appHeaders($token))->assertNotFound();

        $passport = $this->passportToken($user);
        foreach (['/api/v1/workspaces', "/api/v1/workspaces/{$foreign->slug}"] as $uri) {
            $this->getJson($uri)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
            $this->getJson($uri, $this->appHeaders($passport))->assertUnauthorized();
        }

        $suspended = User::factory()->create(['suspended_at' => now()]);
        $this->getJson('/api/v1/workspaces', $this->appHeaders($this->appToken($suspended)))->assertForbidden();
    }
}
