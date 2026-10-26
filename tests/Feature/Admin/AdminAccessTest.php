<?php

namespace Tests\Feature\Admin;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    public function test_regular_users_cannot_open_the_admin_console(): void
    {
        $user = User::factory()->create();

        foreach (['admin.overview', 'admin.users.index', 'admin.workspaces.index', 'admin.settings.edit', 'admin.audit-log'] as $route) {
            $this->actingAs($user)->get(route($route))->assertForbidden();
        }
    }

    public function test_instance_admins_see_the_overview_with_counts(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Overview')
                ->where('stats.users', 2)
                ->where('stats.workspaces', 1)
                ->where('stats.projects', 1)
                ->has('system.php'));
    }

    public function test_instance_admins_no_longer_see_other_peoples_workspaces_or_content(): void
    {
        $page = Page::factory()->published()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'name' => 'Secret plans',
            'content' => ['type' => 'lexical', 'html' => '<p>Secret</p>'],
        ]);

        $this->actingAs($this->admin)
            ->get(route('workspaces.show', $this->workspace->slug))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('pages.show', [$this->workspace->slug, $this->project->slug, $page->uuid]))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('workspaces.index'))
            ->assertInertia(fn (Assert $p) => $p->where('workspaces', [])->has('auth.isInstanceAdmin'));

        $this->actingAs($this->admin)
            ->getJson(route('search.quick', ['q' => 'Secret']))
            ->assertOk()
            ->assertDontSee('Secret plans');
    }

    public function test_the_admin_flag_is_shared_with_the_frontend(): void
    {
        $this->actingAs($this->admin)->get(route('workspaces.index'))
            ->assertInertia(fn (Assert $p) => $p->where('auth.isInstanceAdmin', true));

        $this->actingAs($this->owner)->get(route('workspaces.index'))
            ->assertInertia(fn (Assert $p) => $p->where('auth.isInstanceAdmin', false));
    }

    public function test_the_promote_command_verifies_the_email_so_the_console_is_reachable_without_mail(): void
    {
        $user = User::factory()->unverified()->create();

        $this->artisan('make:instance-admin', ['email' => $user->email])->assertSuccessful();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->actingAs($user->fresh())->get(route('admin.overview'))->assertOk();
    }
}
