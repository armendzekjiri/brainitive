<?php

namespace Tests\Feature\AppApi;

use App\Models\InstanceAdmin;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/** The account in the desktop / mobile apps (BRAI-384): `GET/PATCH /api/v1/me`, `POST/DELETE /api/v1/me/avatar`. */
class MeTest extends TestCase
{
    use ActsAsAppClient;
    use RefreshDatabase;

    /** Every auth:app route of this module. */
    private const ROUTES = [
        ['GET', '/api/v1/me'],
        ['PATCH', '/api/v1/me'],
        ['POST', '/api/v1/me/avatar'],
        ['DELETE', '/api/v1/me/avatar'],
    ];

    public function test_me_returns_the_user_workspaces_limits_and_device(): void
    {
        $user = User::factory()->create(['name' => 'Ada', 'timezone' => 'Europe/Berlin', 'bio' => 'Hi']);
        InstanceAdmin::create(['user_id' => $user->id]);
        $own = Workspace::factory()->create(['owner_id' => $user->id, 'name' => 'Beta', 'slug' => 'beta']);
        $own->addUser($user, 'admin');
        $other = Workspace::factory()->create(['name' => 'alpha', 'slug' => 'alpha']);
        $other->addUser($user, 'editor');
        Workspace::factory()->create(['name' => 'Hidden', 'slug' => 'hidden']); // not a member
        $device = $this->appDevice($user, ['name' => 'Ada’s Mac']);

        $response = $this->getJson('/api/v1/me', $this->appHeaders($this->appToken($user, $device)))
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'name', 'email', 'email_verified', 'email_verified_at', 'avatar_url', 'bio', 'phone',
                'timezone', 'effective_timezone', 'task_digest', 'is_instance_admin', 'created_at', 'limits_enforced',
                'workspaces' => [['slug', 'name', 'role', 'is_owner', 'logo_url', 'billing_url', 'limits' => [
                    'plan_name', 'free', 'unlimited', 'members', 'projects', 'content_bytes', 'file_bytes',
                    'versions' => ['keep_latest', 'keep_days'], 'grace_until',
                ]]],
                'device' => ['id', 'name', 'client', 'platform', 'app_version'],
            ]])
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Ada')
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.timezone', 'Europe/Berlin')
            ->assertJsonPath('data.effective_timezone', 'Europe/Berlin')
            ->assertJsonPath('data.is_instance_admin', true)
            ->assertJsonPath('data.workspaces.0.slug', 'alpha')
            ->assertJsonPath('data.workspaces.0.role', 'editor')
            ->assertJsonPath('data.workspaces.0.is_owner', false)
            ->assertJsonPath('data.workspaces.1.slug', 'beta')
            ->assertJsonPath('data.workspaces.1.is_owner', true)
            ->assertJsonPath('data.workspaces.1.limits.unlimited', true)
            ->assertJsonPath('data.workspaces.1.billing_url', null)
            ->assertJsonPath('data.device', [
                'id' => $device->id, 'name' => 'Ada’s Mac', 'client' => 'desktop', 'platform' => 'macos', 'app_version' => '0.1.0',
            ])
            ->assertJsonCount(2, 'data.workspaces');

        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $response->json('data.created_at'));
        // explicit fields only: nothing from the model's toArray()
        $body = $response->getContent();
        foreach (['password', 'remember_token', 'notification_preferences', 'sidebar_preferences', 'access_token_hash'] as $field) {
            $this->assertStringNotContainsString($field, $body);
        }
    }

    public function test_me_shows_cloud_limits_from_the_entitlements_contract(): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements([
            'enforced' => true, 'plan' => 'Free', 'members' => 3, 'projects' => 5, 'versions_keep_latest' => 10,
        ]));
        $user = User::factory()->create();
        Workspace::factory()->create(['owner_id' => $user->id])->addUser($user, 'admin');

        $this->getJson('/api/v1/me', $this->appHeaders($this->appToken($user)))
            ->assertOk()
            ->assertJsonPath('data.limits_enforced', true)
            ->assertJsonPath('data.workspaces.0.limits.plan_name', 'Free')
            ->assertJsonPath('data.workspaces.0.limits.unlimited', false)
            ->assertJsonPath('data.workspaces.0.limits.members', 3)
            ->assertJsonPath('data.workspaces.0.limits.projects', 5)
            ->assertJsonPath('data.workspaces.0.limits.versions.keep_latest', 10);
    }

    public function test_patch_updates_the_profile_and_preferences(): void
    {
        $user = User::factory()->create(['name' => 'Old', 'task_digest' => true]);
        $token = $this->appToken($user);

        $this->patchJson('/api/v1/me', [
            'name' => 'New Name',
            'bio' => 'Writes code',
            'phone' => '+49 123',
            'timezone' => 'Asia/Calcutta', // old names browsers still send
            'task_digest' => false,
        ], $this->appHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.bio', 'Writes code')
            ->assertJsonPath('data.phone', '+49 123')
            ->assertJsonPath('data.timezone', 'Asia/Calcutta')
            ->assertJsonPath('data.task_digest', false);

        // a partial update leaves everything else alone
        $this->patchJson('/api/v1/me', ['bio' => null], $this->appHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.bio', null);

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertFalse($user->fresh()->task_digest);
    }

    public function test_patch_validation_fails_in_the_error_shape(): void
    {
        $user = User::factory()->create(['name' => 'Kept', 'email' => 'kept@example.test']);
        $token = $this->appToken($user);

        $this->patchJson('/api/v1/me', [
            'name' => '',
            'timezone' => 'Mars/Olympus',
            'task_digest' => 'sometimes',
            'phone' => str_repeat('1', 51),
        ], $this->appHeaders($token))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['message', 'code', 'errors' => ['name', 'timezone', 'task_digest', 'phone']]);

        // the email address is changed on the web only (it has to be verified again)
        $this->patchJson('/api/v1/me', ['email' => 'new@example.test'], $this->appHeaders($token))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Change your email address in the web app: the new one has to be verified.');

        $this->assertSame('Kept', $user->fresh()->name);
        $this->assertSame('kept@example.test', $user->fresh()->email);
    }

    public function test_avatar_upload_and_removal(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $token = $this->appToken($user);

        $response = $this->post('/api/v1/me/avatar', [
            // the client's name never reaches the disk
            'avatar' => UploadedFile::fake()->image('../../evil.php.png', 64, 64),
        ], $this->appHeaders($token))->assertOk();

        $path = $user->fresh()->avatar;
        $this->assertSame('profile-photos/'.$user->id.'/avatar.png', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('/storage/'.$path, $response->json('data.avatar_url'));

        // a new picture replaces the old file
        $this->post('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg')], $this->appHeaders($token))->assertOk();
        Storage::disk('public')->assertMissing($path);
        $jpg = $user->fresh()->avatar;
        $this->assertSame('profile-photos/'.$user->id.'/avatar.jpg', $jpg);

        $this->deleteJson('/api/v1/me/avatar', [], $this->appHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);
        Storage::disk('public')->assertMissing($jpg);
        $this->assertNull($user->fresh()->avatar);
    }

    public function test_avatar_upload_rejects_non_images(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $token = $this->appToken($user);

        $this->post('/api/v1/me/avatar', [], $this->appHeaders($token))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'code', 'errors' => ['avatar']]);

        $this->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->create('avatar.svg', 4, 'image/svg+xml'),
        ], $this->appHeaders($token))->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->image('big.png')->size(3000),
        ], $this->appHeaders($token))->assertStatus(422);

        $this->assertNull($user->fresh()->avatar);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_every_route_needs_an_app_token(): void
    {
        $user = User::factory()->create();
        $passport = $this->passportToken($user);

        foreach (self::ROUTES as [$method, $uri]) {
            $this->json($method, $uri)
                ->assertStatus(401)
                ->assertExactJson(['message' => 'Unauthenticated.', 'code' => 'unauthenticated']);

            // MCP / CLI / browser-extension tokens are Passport tokens: never accepted here
            $this->json($method, $uri, [], $this->appHeaders($passport))
                ->assertStatus(401)
                ->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_a_suspended_user_gets_403_everywhere(): void
    {
        $user = User::factory()->create(['suspended_at' => now()]);
        $token = $this->appToken($user);

        foreach (self::ROUTES as [$method, $uri]) {
            $this->json($method, $uri, [], $this->appHeaders($token))->assertStatus(403);
        }
    }
}
