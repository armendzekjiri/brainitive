<?php

namespace Tests\Feature\Live;

use App\Models\User;
use App\Support\Live\Live;
use App\Support\Live\LiveConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

use function Illuminate\Support\defer;

/**
 * Live channels (BRAI-151): Live::publish — the payload rule, after-commit delivery, never failing
 * the caller — and the bell's `notification.created`. Plan: docs/ai/tasks/feat-live-foundation.md.
 */
class LivePublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['collab.url' => 'wss://brainitive.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false, 'live.enabled' => true, 'live.url' => null, 'live.publish_url' => 'http://127.0.0.1:6299']);
        Cache::forget('live:publish:paused');
        // deferred callbacks run at once here; test_messages_of_one_request_go_out_in_one_post defers them
        $this->withoutDefer();
    }

    public function test_publish_posts_to_the_realtime_server_with_the_publish_key(): void
    {
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 1], 202)]);

        Live::publish('board.12', 'item.moved', ['id' => 5, 'column_id' => 3, 'ids' => [5, 6], 'updated_at' => '2026-11-02T10:00:00Z']);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:6299/internal/live/publish'
            && $request->method() === 'POST'
            && $request->header('Authorization')[0] === 'Bearer '.LiveConfig::publishKey()
            && json_decode($request->body(), true) === ['messages' => [['channel' => 'board.12', 'event' => 'item.moved', 'payload' => ['id' => 5, 'column_id' => 3, 'ids' => [5, 6], 'updated_at' => '2026-11-02T10:00:00Z']]]]);
    }

    public function test_an_empty_payload_is_sent_as_an_object(): void
    {
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 0], 202)]);
        Live::publish('user.1', 'ping');
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '"payload":{}'));
    }

    public function test_publish_waits_for_the_transaction_and_a_rollback_publishes_nothing(): void
    {
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 1], 202)]);

        DB::transaction(function () {
            Live::publish('item.1', 'item.updated', ['id' => 1]);
            Http::assertNothingSent();
        });
        Http::assertSentCount(1);

        try {
            DB::transaction(function () {
                Live::publish('item.2', 'item.updated', ['id' => 2]);
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        Http::assertSentCount(1);
    }

    public function test_a_dead_realtime_server_never_breaks_the_caller_and_pauses_publishing(): void
    {
        // nothing listens there: connection refused
        config(['live.publish_url' => 'http://127.0.0.1:1']);
        $started = microtime(true);
        Live::publish('user.1', 'notification.created', ['id' => 'a']);
        $this->assertLessThan(2, microtime(true) - $started);
        $this->assertTrue(Cache::get('live:publish:paused'));

        // paused: the next publishes don't even try
        Http::fake();
        Live::publish('user.1', 'notification.created', ['id' => 'b']);
        Http::assertNothingSent();
    }

    public function test_a_refusing_realtime_server_is_swallowed_too(): void
    {
        Http::fake(['127.0.0.1:6299/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
        Live::publish('user.1', 'notification.created', ['id' => 'a']);
        $this->assertTrue(Cache::get('live:publish:paused'));

        Cache::forget('live:publish:paused');
        Http::fake(['127.0.0.1:6299/*' => fn () => throw new ConnectionException('timeout')]);
        Live::publish('user.1', 'notification.created', ['id' => 'b']);
        $this->assertTrue(Cache::get('live:publish:paused'));
    }

    public function test_messages_of_one_request_go_out_in_one_post_after_the_response(): void
    {
        $this->withDefer();
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 3], 202)]);

        foreach ([1, 2, 3] as $id) {
            Live::publish("user.{$id}", 'notification.created', ['id' => "n{$id}"]);
        }
        Http::assertNothingSent();

        defer()->invoke();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => count(json_decode($request->body(), true)['messages']) === 3);
    }

    public function test_nothing_is_sent_while_live_is_off_or_without_a_publish_url(): void
    {
        Http::fake();
        config(['live.publish_url' => null]); // wss://… public URL: never a publish target
        Live::publish('user.1', 'notification.created', ['id' => 'a']);
        config(['live.publish_url' => 'http://127.0.0.1:6299', 'live.enabled' => false]);
        Live::publish('user.1', 'notification.created', ['id' => 'a']);
        config(['live.enabled' => true, 'collab.url' => null]);
        Live::publish('user.1', 'notification.created', ['id' => 'a']);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<mixed>}>
     */
    public static function brokenMessages(): array
    {
        return [
            'unknown channel type' => ['workspace.1', 'x', []],
            'malformed channel' => ['item.abc', 'x', []],
            'bad event name' => ['item.1', 'Item Moved', []],
            'content: title' => ['item.1', 'item.updated', ['id' => 1, 'title' => 'Secret plan']],
            'content: body' => ['item.1', 'comment.created', ['body' => '<p>hi</p>']],
            'content: name' => ['page.123e4567-e89b-42d3-a456-426614174000', 'page.renamed', ['name' => 'Q4 layoffs']],
            'long string' => ['item.1', 'item.updated', ['status' => str_repeat('x', 65)]],
            'key outside the allow-list' => ['item.1', 'item.updated', ['subject' => 'Re: salaries']],
            'content: label' => ['board.1', 'label.created', ['label' => 'Layoffs']],
            'nested object' => ['item.1', 'item.updated', ['changes' => ['title' => 'x']]],
            'list of objects' => ['item.1', 'item.updated', ['ids' => [['id' => 1]]]],
            'too many keys' => ['item.1', 'item.updated', array_combine(array_map(fn ($i) => "k{$i}", range(1, 17)), range(1, 17))],
            'camelCase key' => ['item.1', 'item.updated', ['updatedAt' => 'x']],
            'list key' => ['item.1', 'item.updated', [1, 2]],
            'too large' => ['item.1', 'item.updated', ['ids' => array_fill(0, 100, str_repeat('x', 30))]],
        ];
    }

    /**
     * @param  array<mixed>  $payload
     */
    #[DataProvider('brokenMessages')]
    public function test_the_payload_rule_refuses_content(string $channel, string $event, array $payload): void
    {
        Http::fake();
        $this->expectException(InvalidArgumentException::class);
        try {
            Live::publish($channel, $event, $payload);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_broken_message_is_reported_and_dropped_outside_unit_tests(): void
    {
        Http::fake();
        $this->app['env'] = 'production';
        Live::publish('item.1', 'item.updated', ['title' => 'Secret']);
        Http::assertNothingSent();
    }

    public function test_storing_a_notification_rings_the_bell_of_its_user_only(): void
    {
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 1], 202)]);
        $user = User::factory()->create();

        $user->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['database'];
            }

            public function toDatabase(object $notifiable): array
            {
                return ['type' => 'test', 'title' => 'Private title'];
            }
        });

        $id = $user->notifications()->value('id');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => json_decode($request->body(), true) === ['messages' => [['channel' => "user.{$user->id}", 'event' => 'notification.created', 'payload' => ['id' => $id]]]]
            && ! str_contains($request->body(), 'Private title'));
    }

    public function test_mail_only_notifications_publish_nothing(): void
    {
        Http::fake();
        User::factory()->create()->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->line('hi');
            }
        });
        Http::assertNothingSent();
    }
}
