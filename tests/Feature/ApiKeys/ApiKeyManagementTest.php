<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiKey;
use App\Models\Board;
use App\Models\User;
use App\Notifications\ApiKeyCreatedNotification;
use App\Support\ApiKeys\ApiKeys;
use App\Support\Mail\EmailPreviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\ApiKeys\Concerns\UsesApiKeys;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Developer API (v1.52.0): Profile → API keys — the key is shown once (only in the create / regenerate
 * response, never stored or in the session), the owner gets an email, the scope can only name what the user can
 * open, revoke / regenerate are owner-only, keys show up under Connected apps, workspace admins and the instance
 * can turn keys off.
 */
class ApiKeyManagementTest extends TestCase
{
    use ActsAsAppClient;
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesApiKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Deploy script',
            'scope_type' => 'boards',
            'scope_ids' => [$this->board->id],
            'abilities' => ['issues' => 'write', 'docs' => 'read'],
            'can_delete' => false,
            'expires_in_days' => 90,
        ], $overrides);
    }

    public function test_creating_a_key_shows_the_secret_once_and_emails_the_owner(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->editor)->post(route('profile.api-keys.store'), $this->payload())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Profile/ApiKeys')
                ->where('created.key.name', 'Deploy script')
                ->where('created.key.scope_type', 'boards')
                ->where('created.key.scope.0.name', $this->board->name)
                ->where('created.key.abilities.issues', 'write')
                ->where('created.key.abilities.members', 'none')
                ->where('created.key.can_delete', false)
                ->where('created.key.status', 'active'));

        $secret = $response->viewData('page')['props']['created']['secret'];
        $key = ApiKey::query()->sole();
        $this->assertSame(hash('sha256', $secret), $key->token_hash);
        $this->assertSame($this->editor->id, $key->user_id);
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, $key->expires_at->timestamp, 5);
        $this->assertNull(session('created'));
        Notification::assertSentTo($this->editor, ApiKeyCreatedNotification::class, fn ($n) => $n->key->is($key));

        // never again: the list has the prefix, not the secret
        $index = $this->actingAs($this->editor)->get(route('profile.api-keys.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Profile/ApiKeys')->where('created', null)->has('apiKeys', 1)
                ->where('apiKeys.0.prefix', $key->prefix));
        $this->assertStringNotContainsString($secret, $index->getContent());
        $this->assertStringNotContainsString($key->token_hash, $index->getContent());

        // and it works
        $this->getJson("/api/v1/boards/{$this->board->id}", $this->keyHeaders($secret))->assertOk();
    }

    public function test_the_scope_can_only_name_what_the_user_can_open(): void
    {
        $foreign = Board::factory()->create();

        $this->actingAs($this->editor)->post(route('profile.api-keys.store'), $this->payload(['scope_ids' => [$foreign->id]]))
            ->assertSessionHasErrors('scope_ids');
        $this->actingAs($this->editor)->post(route('profile.api-keys.store'), $this->payload(['scope_ids' => []]))
            ->assertSessionHasErrors('scope_ids');
        $this->actingAs($this->editor)->post(route('profile.api-keys.store'), $this->payload(['abilities' => ['issues' => 'none']]))
            ->assertSessionHasErrors('abilities');
        $this->actingAs($this->editor)->post(route('profile.api-keys.store'), $this->payload(['scope_type' => 'galaxy']))
            ->assertSessionHasErrors('scope_type');
        $this->actingAs($this->editor)->post(route('profile.api-keys.store'), $this->payload(['expires_in_days' => 7]))
            ->assertSessionHasErrors('expires_in_days');
        $this->assertDatabaseCount('api_keys', 0);

        // unknown areas are dropped, read-only areas capped
        $this->actingAs($this->editor)->post(route('profile.api-keys.store'), $this->payload(['scope_type' => 'all', 'abilities' => ['issues' => 'read', 'members' => 'write', 'billing' => 'write']]))->assertOk();
        $this->assertSame('read', ApiKey::query()->sole()->abilities['members']);
        $this->assertArrayNotHasKey('billing', ApiKey::query()->sole()->abilities);
        $this->assertNull(ApiKey::query()->sole()->scope_ids);
    }

    public function test_revoke_and_regenerate_are_owner_only(): void
    {
        ['key' => $key, 'secret' => $old] = $this->makeApiKey($this->editor);

        $this->actingAs($this->viewer)->delete(route('profile.api-keys.destroy', $key->id))->assertNotFound();
        $this->actingAs($this->viewer)->post(route('profile.api-keys.regenerate', $key->id))->assertNotFound();
        $this->assertNull($key->fresh()->revoked_at);

        $response = $this->actingAs($this->editor)->post(route('profile.api-keys.regenerate', $key->id))->assertOk();
        $new = $response->viewData('page')['props']['created']['secret'];
        $this->assertNotSame($old, $new);
        $this->getJson('/api/v1/me', $this->keyHeaders($old))->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $this->keyHeaders($new))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->editor)->delete(route('profile.api-keys.destroy', $key->id))->assertRedirect(route('profile.api-keys.index'));
        $this->assertSame('user', $key->fresh()->revoked_reason);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $this->keyHeaders($new))->assertUnauthorized()->assertJsonPath('code', 'api_key_revoked');
    }

    public function test_keys_are_listed_under_connected_apps(): void
    {
        ['key' => $key] = $this->makeApiKey($this->editor, ['issues' => 'write', 'docs' => 'read'], canDelete: true);

        $this->actingAs($this->editor)->get(route('profile.edit'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('apiKeys', 1)->where('apiKeys.0.name', 'Test key')->where('apiKeysEnabled', true));

        $token = $this->appToken($this->editor);
        $this->getJson('/api/v1/me/connected-apps', $this->appHeaders($token))->assertOk()
            ->assertJsonFragment(['id' => 'api_key:'.$key->id, 'kind' => 'api_key', 'scopes' => ['issues:write', 'docs:read', 'delete']]);
        $this->deleteJson('/api/v1/me/connected-apps/api_key:'.$key->id, [], $this->appHeaders($token))->assertNoContent();
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->deleteJson('/api/v1/me/connected-apps/api_key:'.$key->id, [], $this->appHeaders($token))->assertNotFound();
    }

    public function test_an_api_key_cant_manage_connected_apps_or_keys(): void
    {
        $secret = $this->apiKey($this->editor, $this->everything(), canDelete: true);
        $this->getJson('/api/v1/me/connected-apps', $this->keyHeaders($secret))->assertForbidden()->assertJsonPath('code', 'key_endpoint_not_allowed');
        // the web pages need a browser session (a fresh app per request in production: the web guard again)
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->get(route('profile.api-keys.index'), $this->keyHeaders($secret))->assertRedirect(route('login'));
    }

    public function test_workspace_admins_toggle_api_keys(): void
    {
        $owner = User::findOrFail($this->workspace->owner_id);
        $this->actingAs($this->editor)->patch(route('workspaces.api-keys.update', $this->workspace), ['api_keys_enabled' => false]);
        $this->assertTrue($this->workspace->fresh()->api_keys_enabled);

        $this->actingAs($owner)->patch(route('workspaces.api-keys.update', $this->workspace), ['api_keys_enabled' => false])->assertRedirect();
        $this->assertFalse($this->workspace->fresh()->api_keys_enabled);
    }

    public function test_the_instance_switch_hides_creation(): void
    {
        config(['api-keys.enabled' => false]);

        $this->actingAs($this->editor)->get(route('profile.api-keys.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('enabled', false));
        $this->actingAs($this->editor)->post(route('profile.api-keys.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('api_keys', 0);
    }

    public function test_the_created_email_renders_without_the_secret(): void
    {
        ['key' => $key, 'secret' => $secret] = $this->makeApiKey($this->editor);
        $html = (string) (new ApiKeyCreatedNotification($key))->toMail($this->editor)->render();

        $this->assertStringContainsString('Test key', $html);
        $this->assertStringContainsString('203.0.113.x', $html);
        $this->assertStringNotContainsString($secret, $html);
        $this->assertArrayHasKey('api-key-created', (new EmailPreviews)->entries());
    }

    public function test_a_failing_mail_doesnt_lose_the_key(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));
        $created = ApiKeys::create($this->editor, 'x', 'all', [], ['issues' => 'read'], false, null, null);
        $this->assertTrue($created['key']->exists);
    }
}
