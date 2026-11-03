<?php

namespace Tests\Feature\AppApi;

use App\Models\InstanceAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /docs/api (Scramble UI) and /docs/api.json (BRAI-381): open in the `local` environment, otherwise
 * only for instance admins (gate `viewApiDocs`, AppApiDocsServiceProvider). The committed
 * docs/api/openapi.json is the public copy; the live pages are not.
 */
class ApiDocsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // RestrictedDocsAccess lets `local` through: test the way production behaves
        $this->withoutVite();
        $this->app['env'] = 'production';
    }

    public function test_guests_cannot_open_the_api_docs(): void
    {
        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_a_normal_user_cannot_open_the_api_docs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/docs/api')->assertForbidden();
        $this->actingAs($user)->get('/docs/api.json')->assertForbidden();
    }

    public function test_an_instance_admin_can_open_the_api_docs(): void
    {
        $admin = User::factory()->create();
        InstanceAdmin::create(['user_id' => $admin->id]);

        $this->actingAs($admin)->get('/docs/api')->assertOk()->assertSee('Brainitive App API');
        $this->actingAs($admin)->get('/docs/api.json')->assertOk()
            ->assertJsonPath('info.version', '1.0.0')
            ->assertJsonPath('servers.0.url', 'https://{instance}/api/v1');
    }

    public function test_the_local_environment_opens_the_docs_without_signing_in(): void
    {
        $this->app['env'] = 'local';

        $this->get('/docs/api.json')->assertOk();
    }
}
