<?php

namespace Tests\Feature\AppApi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\TestCase;

/** App API v1 notifications (BRAI-393): inbox, badge count, read / archive, preferences. */
class NotificationsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->appToken($this->user);
    }

    private function notification(array $data, array $attributes = [], ?User $user = null): DatabaseNotification
    {
        return ($user ?? $this->user)->notifications()->create($attributes + [
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'data' => $data,
        ]);
    }

    public function test_lists_the_inbox_newest_first_with_filters_and_pages(): void
    {
        $old = $this->notification(['type' => 'item_assigned', 'item_title' => 'Old'], ['created_at' => now()->subDays(2)]);
        $mention = $this->notification(['type' => 'mentioned', 'actor_name' => 'Ada', 'url' => '/x'], ['created_at' => now()->subDay()]);
        $read = $this->notification(['type' => 'comment_added'], ['read_at' => now()]);
        $this->notification(['type' => 'mentioned'], ['archived_at' => now(), 'read_at' => now()]);
        $this->notification(['type' => 'mentioned'], [], User::factory()->create()); // someone else's

        $all = $this->getJson('/api/v1/notifications', $this->appHeaders($this->token))->assertOk();
        $this->assertSame([$read->id, $mention->id, $old->id], array_column($all->json('data'), 'id'));
        $this->assertSame(['data', 'next_cursor'], array_keys($all->json()));
        $all->assertJsonPath('data.1.type', 'mentioned')
            ->assertJsonPath('data.1.category', 'mentions')
            ->assertJsonPath('data.1.data.actor_name', 'Ada')
            ->assertJsonPath('data.1.read', false)
            ->assertJsonPath('data.0.read', true);
        $this->assertSame(['id', 'type', 'category', 'data', 'read', 'read_at', 'archived_at', 'created_at'], array_keys($all->json('data.0')));
        $this->assertMatchesRegularExpression('/Z$/', $all->json('data.0.created_at'));

        $ids = fn (string $query) => array_column($this->getJson('/api/v1/notifications?'.$query, $this->appHeaders($this->token))->assertOk()->json('data'), 'id');
        $this->assertSame([$mention->id, $old->id], $ids('filter=unread'));
        $this->assertSame([$mention->id], $ids('filter=mentions'));
        $this->assertSame([$old->id], $ids('filter=assigned'));
        $this->assertCount(1, $ids('archived=1'));

        $page = $this->getJson('/api/v1/notifications?limit=2', $this->appHeaders($this->token))->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$old->id], array_column($this->getJson('/api/v1/notifications?limit=2&cursor='.urlencode($page->json('next_cursor')), $this->appHeaders($this->token))->json('data'), 'id'));

        $this->getJson('/api/v1/notifications?filter=everything', $this->appHeaders($this->token))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('filter');
    }

    public function test_an_empty_data_payload_is_an_object(): void
    {
        $this->notification([]);

        $response = $this->getJson('/api/v1/notifications', $this->appHeaders($this->token))->assertOk();
        $this->assertStringContainsString('"data":{}', $response->getContent());
        $response->assertJsonPath('data.0.type', null)->assertJsonPath('data.0.category', null);
    }

    public function test_unread_count_read_archive_and_read_all(): void
    {
        $a = $this->notification(['type' => 'mentioned']);
        $b = $this->notification(['type' => 'mentioned']);
        $c = $this->notification(['type' => 'mentioned']);
        $foreign = $this->notification(['type' => 'mentioned'], [], User::factory()->create());

        $this->getJson('/api/v1/notifications/unread-count', $this->appHeaders($this->token))->assertOk()->assertExactJson(['data' => ['unread_count' => 3]]);

        $this->postJson("/api/v1/notifications/{$a->id}/read", [], $this->appHeaders($this->token))->assertOk()->assertJsonPath('data.unread_count', 2);
        $this->assertNotNull($a->fresh()->read_at);

        // archiving takes it out of the inbox and marks it read
        $this->postJson("/api/v1/notifications/{$b->id}/archive", [], $this->appHeaders($this->token))->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->assertNotNull($b->fresh()->archived_at);
        $this->assertNotNull($b->fresh()->read_at);

        // another person's notification, an unknown id, a malformed id
        $this->postJson("/api/v1/notifications/{$foreign->id}/read", [], $this->appHeaders($this->token))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->postJson('/api/v1/notifications/'.Str::uuid().'/archive', [], $this->appHeaders($this->token))->assertNotFound();
        $this->postJson('/api/v1/notifications/123/read', [], $this->appHeaders($this->token))->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);

        $this->postJson('/api/v1/notifications/read-all', [], $this->appHeaders($this->token))->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull($c->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_preferences_read_and_partial_update(): void
    {
        $response = $this->getJson('/api/v1/me/notification-preferences', $this->appHeaders($this->token))->assertOk();
        $mentions = collect($response->json('data.categories'))->firstWhere('key', 'mentions');
        $this->assertSame(['in_app' => true, 'push' => true, 'email' => false], $mentions['channels']);
        $this->assertSame(['key', 'label', 'description', 'channels'], array_keys($mentions));

        $updated = $this->putJson('/api/v1/me/notification-preferences', ['preferences' => ['mentions' => ['push' => false, 'email' => true]]], $this->appHeaders($this->token))->assertOk();
        $mentions = collect($updated->json('data.categories'))->firstWhere('key', 'mentions');
        $this->assertSame(['in_app' => true, 'push' => false, 'email' => true], $mentions['channels']);
        $this->assertFalse($this->user->fresh()->notification_preferences['mentions']['push']);
        // other categories keep their defaults
        $this->assertSame(['in_app' => true, 'push' => true, 'email' => false], collect($updated->json('data.categories'))->firstWhere('key', 'assignments')['channels']);

        $this->putJson('/api/v1/me/notification-preferences', ['preferences' => ['spam' => ['push' => true]]], $this->appHeaders($this->token))
            ->assertStatus(422)->assertJsonValidationErrors('preferences');
        $this->putJson('/api/v1/me/notification-preferences', ['preferences' => ['mentions' => ['sms' => true]]], $this->appHeaders($this->token))
            ->assertStatus(422)->assertJsonValidationErrors('preferences.mentions');
        $this->putJson('/api/v1/me/notification-preferences', [], $this->appHeaders($this->token))->assertStatus(422);
    }

    public function test_tokens_are_checked(): void
    {
        $id = $this->notification(['type' => 'mentioned'])->id;
        $passport = $this->passportToken($this->user);

        foreach ([
            ['GET', '/api/v1/notifications'],
            ['GET', '/api/v1/notifications/unread-count'],
            ['POST', "/api/v1/notifications/{$id}/read"],
            ['POST', '/api/v1/notifications/read-all'],
            ['POST', "/api/v1/notifications/{$id}/archive"],
            ['GET', '/api/v1/me/notification-preferences'],
            ['PUT', '/api/v1/me/notification-preferences'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
            $this->json($method, $uri, [], $this->appHeaders($passport))->assertUnauthorized();
        }
        $this->assertNull(DatabaseNotification::find($id)->read_at);
    }
}
