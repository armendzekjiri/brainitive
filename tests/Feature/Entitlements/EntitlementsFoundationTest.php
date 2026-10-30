<?php

namespace Tests\Feature\Entitlements;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUsage;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\EntitlementsPayload;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use App\Support\Entitlements\UnlimitedEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** v1.46.0: the plan-limit contract. Self-hosted = unlimited; the config driver blocks at the limit. */
class EntitlementsFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function useLimits(array $config): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements([...['enforced' => true], ...$config]));
    }

    private function workspaceWithMembers(int $members, string $slug = 'acme'): Workspace
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id, 'slug' => $slug]);
        $workspace->addUser($owner, 'admin');
        for ($i = 1; $i < $members; $i++) {
            $workspace->addUser(User::factory()->create(), 'editor');
        }

        return $workspace;
    }

    public function test_self_hosted_is_unlimited_and_shares_no_entitlements(): void
    {
        $this->assertInstanceOf(UnlimitedEntitlements::class, app(Entitlements::class));
        $workspace = $this->workspaceWithMembers(5);

        app(LimitGate::class)->ensureCanAddMembers($workspace, 100);
        app(LimitGate::class)->ensureCanCreateProject($workspace, 100);
        app(LimitGate::class)->ensureCanStoreFiles($workspace, PHP_INT_MAX);
        app(LimitGate::class)->ensureCanCreateWorkspace($workspace->owner);

        $this->assertNull(EntitlementsPayload::for($workspace));
    }

    public function test_the_fourth_member_of_a_free_workspace_is_blocked_and_invitations_count(): void
    {
        $this->useLimits(['members' => 3]);
        $workspace = $this->workspaceWithMembers(2);

        app(LimitGate::class)->ensureCanAddMembers($workspace); // the 3rd is fine
        $workspace->invitations()->create(['email' => 'third@example.com', 'role' => 'editor', 'token' => 'tok-3', 'invited_by' => $workspace->owner_id, 'expires_at' => now()->addDays(7)]);

        try {
            app(LimitGate::class)->ensureCanAddMembers($workspace);
            $this->fail('the 4th member was allowed');
        } catch (LimitReached $e) {
            $this->assertSame('members', $e->kind);
            $this->assertSame(3, $e->limit);
            $this->assertSame(3, $e->usage);
        }
    }

    public function test_nothing_is_blocked_while_limits_are_not_enforced(): void
    {
        $this->useLimits(['enforced' => false, 'members' => 1, 'projects' => 0, 'free_workspaces_per_owner' => 0]);
        $workspace = $this->workspaceWithMembers(3);

        app(LimitGate::class)->ensureCanAddMembers($workspace);
        app(LimitGate::class)->ensureCanCreateProject($workspace);
        app(LimitGate::class)->ensureCanCreateWorkspace($workspace->owner);

        $payload = EntitlementsPayload::for($workspace);
        $this->assertFalse($payload['enforced']);
        $this->assertSame(1, $payload['limits']['members']);
        $this->assertSame(3, $payload['usage']['members']);
    }

    public function test_projects_files_and_free_workspaces_are_limited(): void
    {
        $this->useLimits(['projects' => 1, 'file_bytes' => 1000, 'free_workspaces_per_owner' => 1]);
        $workspace = $this->workspaceWithMembers(1);
        Project::factory()->create(['workspace_id' => $workspace->id]);
        WorkspaceUsage::create(['workspace_id' => $workspace->id, 'file_bytes' => 900]);

        foreach ([
            'projects' => fn () => app(LimitGate::class)->ensureCanCreateProject($workspace),
            'files' => fn () => app(LimitGate::class)->ensureCanStoreFiles($workspace->fresh(), 101),
            'workspaces' => fn () => app(LimitGate::class)->ensureCanCreateWorkspace($workspace->owner),
        ] as $kind => $check) {
            try {
                $check();
                $this->fail("{$kind} was not limited");
            } catch (LimitReached $e) {
                $this->assertSame($kind, $e->kind);
            }
        }
        app(LimitGate::class)->ensureCanStoreFiles($workspace->fresh(), 100); // exactly at the limit is fine
    }

    public function test_limits_can_apply_to_matching_workspaces_only(): void
    {
        $this->useLimits(['members' => 1, 'only_slug_prefix' => 'plan-limit-', 'billing_url' => '/billing']);

        $seeded = $this->workspaceWithMembers(3, 'seeded');
        app(LimitGate::class)->ensureCanAddMembers($seeded);
        // no limits and no billing link: no plan UI at all, like self-hosted
        $this->assertNull(app(Entitlements::class)->billingUrl($seeded));
        $this->assertNull(EntitlementsPayload::for($seeded));
        $this->expectException(LimitReached::class);
        app(LimitGate::class)->ensureCanAddMembers($this->workspaceWithMembers(1, 'plan-limit-test'));
    }

    public function test_limit_reached_is_402_json_or_a_flash_for_inertia(): void
    {
        $e = new LimitReached('members', 3, 3, 'Free', billingUrl: 'https://example.com/billing');

        $json = $e->render(Request::create('/x', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));
        $this->assertSame(402, $json->getStatusCode());
        $this->assertSame('members', $json->getData(true)['limit_reached']['kind']);
        $this->assertSame('https://example.com/billing', $json->getData(true)['limit_reached']['billingUrl']);

        $redirect = $e->render(Request::create('/x', 'POST', server: ['HTTP_X_INERTIA' => 'true']));
        $this->assertSame(302, $redirect->getStatusCode());
        $this->assertSame('members', session('limit_reached')['kind']);
    }
}
