<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Item;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use App\Notifications\NotificationCatalog;
use App\Notifications\WebhookDisabledNotification;
use App\Support\Events\DomainEvents;
use App\Support\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * DomainEvents → deliveries → DeliverWebhook (BRAI-104): signing, subscription filter, after-commit,
 * retries, auto-disable, sync-queue behaviour and pruning.
 */
class WebhookDeliveryTest extends TestCase
{
    use InteractsWithBoards;
    use InteractsWithWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function delivery(Webhook $webhook, string $event = 'item.created'): WebhookDelivery
    {
        return $webhook->deliveries()->create([
            'event' => $event,
            'event_id' => '6f1c1c3e-0000-4000-8000-000000000001',
            'payload' => DomainEvents::envelope($this->workspace, $event, ['hello' => 'world'], null),
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);
    }

    public function test_deliveries_are_signed_with_the_timestamp_and_carry_the_envelope(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->hook(['item.created']);

        $this->actingAs($this->editor);
        $item = $this->item(['title' => 'Signed issue']);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($item) {
            $body = $request->body();
            $timestamp = $request->header('X-Brainitive-Timestamp')[0];
            $payload = json_decode($body, true);
            $delivery = WebhookDelivery::firstOrFail();

            return $request->url() === self::HOOK_URL
                && $request->method() === 'POST'
                && $request->header('Content-Type')[0] === 'application/json'
                && $request->header('X-Brainitive-Event')[0] === 'item.created'
                && $request->header('X-Brainitive-Delivery')[0] === $delivery->uuid
                && abs((int) $timestamp - time()) < 5
                && $request->header('X-Brainitive-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test')
                && array_keys($payload) === ['id', 'event', 'occurred_at', 'workspace', 'actor', 'data']
                && $payload['id'] === $delivery->event_id
                && $payload['workspace'] === ['id' => $this->workspace->id, 'slug' => $this->workspace->slug, 'name' => $this->workspace->name]
                && $payload['actor'] === ['id' => $this->editor->id, 'name' => $this->editor->name]
                && $payload['data']['item']['id'] === $item->id
                && $payload['data']['item']['title'] === 'Signed issue';
        });

        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame([WebhookDelivery::STATUS_SUCCESS, 1, 200, 'ok'], [$delivery->status, $delivery->attempts, $delivery->response_code, $delivery->response_body]);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNotNull(Webhook::firstOrFail()->last_delivery_at);
    }

    public function test_only_active_webhooks_of_the_workspace_subscribed_to_the_event_get_it(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $issues = $this->hook(['item.created']);
        $tickets = $this->hook(['ticket.created']);
        $all = $this->hook(['*']);
        $inactive = $this->hook(['*'], ['active' => false]);
        $owner = User::factory()->create();
        $foreign = Workspace::factory()->create(['owner_id' => $owner->id]);
        $elsewhere = $this->hook(['*'], [], $foreign);

        $this->item();

        $this->assertEqualsCanonicalizing([$issues->id, $all->id], WebhookDelivery::where('event', 'item.created')->pluck('webhook_id')->all());
        foreach ([$tickets, $inactive, $elsewhere] as $webhook) {
            $this->assertSame(0, $webhook->deliveries()->count());
        }
    }

    public function test_nothing_is_sent_for_a_rolled_back_change(): void
    {
        Http::fake();
        $this->hook();

        try {
            DB::transaction(function () {
                $this->item(['title' => 'Never happened']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, WebhookDelivery::count());
        Http::assertNothingSent();

        // committed: delivered after the commit
        DB::transaction(fn () => $this->item(['title' => 'Committed']));
        $this->assertSame(1, WebhookDelivery::count());
    }

    public function test_server_errors_and_network_errors_are_retried_with_backoff(): void
    {
        $webhook = $this->hook();
        $answer = Http::response('down', 503);
        Http::fake(function () use (&$answer) {
            return $answer;
        });

        $delivery = $this->delivery($webhook);
        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased(30);
        $delivery->refresh();
        $this->assertSame([WebhookDelivery::STATUS_PENDING, 1, 503, 'HTTP 503'], [$delivery->status, $delivery->attempts, $delivery->response_code, $delivery->error]);
        $this->assertSame(0, $webhook->fresh()->failure_count); // not final yet

        // second attempt: network error → 2 minutes
        $answer = Http::failedConnection('Connection refused');
        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle();
        $job->assertReleased(120);
        $this->assertStringContainsString('Connection failed', $delivery->fresh()->error);

        // last attempt fails for good
        $answer = Http::response('down', 500);
        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        $job->job->attempts = 4;
        $job->handle();
        $job->assertNotReleased();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->fresh()->status);
        $this->assertSame(1, $webhook->fresh()->failure_count);
        $this->assertSame([30, 120, 600], $job->backoff());
        $this->assertSame(4, $job->tries);
    }

    public function test_client_errors_fail_without_retry_and_a_success_resets_the_failures(): void
    {
        $webhook = $this->hook(['*'], ['failure_count' => 3]);
        $answer = Http::response(str_repeat('x', 5000), 410);
        Http::fake(function () use (&$answer) {
            return $answer;
        });

        $delivery = $this->delivery($webhook);
        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotReleased();
        $delivery->refresh();
        $this->assertSame([WebhookDelivery::STATUS_FAILED, 410], [$delivery->status, $delivery->response_code]);
        $this->assertSame(WebhookDelivery::RESPONSE_LIMIT, strlen($delivery->response_body)); // truncated to 2 KB
        $this->assertSame(4, $webhook->fresh()->failure_count);

        $answer = Http::response('ok');
        (new DeliverWebhook($this->delivery($webhook)->id))->withFakeQueueInteractions()->handle();
        $this->assertSame(0, $webhook->fresh()->failure_count);
    }

    public function test_redirects_are_not_followed(): void
    {
        $webhook = $this->hook();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);

        $job = (new DeliverWebhook($this->delivery($webhook)->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotReleased();
        Http::assertSentCount(1);
        $this->assertSame(302, WebhookDelivery::firstOrFail()->response_code);
        $this->assertSame(WebhookDelivery::STATUS_FAILED, WebhookDelivery::firstOrFail()->status);
    }

    public function test_ten_failed_deliveries_in_a_row_disable_the_webhook_and_notify_the_admins(): void
    {
        $this->assertSame('workspace', NotificationCatalog::category(WebhookDisabledNotification::TYPE));
        Notification::fake();
        Http::fake(['*' => Http::response('nope', 500)]);
        $webhook = $this->hook(['*'], ['failure_count' => Webhook::MAX_CONSECUTIVE_FAILURES - 2]);

        (new DeliverWebhook($this->delivery($webhook)->id))->handle(); // not queued: one attempt
        $this->assertTrue($webhook->fresh()->active);

        (new DeliverWebhook($this->delivery($webhook)->id))->handle();
        $webhook->refresh();
        $this->assertFalse($webhook->active);
        $this->assertNotNull($webhook->disabled_at);
        $this->assertSame(Webhook::MAX_CONSECUTIVE_FAILURES, $webhook->failure_count);

        Notification::assertSentTo($this->admin(), WebhookDisabledNotification::class, function (WebhookDisabledNotification $n) {
            $data = $n->toDatabase($this->admin());

            return $n instanceof ShouldQueue
                && $n->via($this->admin()) === ['database']
                && $data['type'] === 'webhook_disabled'
                && str_contains((string) $data['body'], 'Last error: HTTP 500')
                && str_contains($data['title'], 'Receiver')
                && $data['url'] === route('webhooks.index', $this->workspace->slug);
        });
        Notification::assertNotSentTo($this->editor, WebhookDisabledNotification::class);
        Notification::assertSentTimes(WebhookDisabledNotification::class, 1);

        // disabled: no new deliveries, queued ones are not sent
        $sent = Http::recorded()->count();
        $before = WebhookDelivery::count();
        $this->item();
        $this->assertSame($before, WebhookDelivery::count());
        (new DeliverWebhook($this->delivery($webhook)->id))->handle();
        $this->assertSame($sent, Http::recorded()->count());
        Notification::assertSentTimes(WebhookDisabledNotification::class, 1);
    }

    public function test_the_url_is_checked_again_before_sending(): void
    {
        Http::fake();
        // saved before the check existed, or DNS now points inside: never sent
        $webhook = $this->hook(['*'], ['url' => 'http://127.0.0.1:8080/internal']);
        config(['webhooks.allow_private_targets' => true]); // ignored outside APP_ENV=e2e

        (new DeliverWebhook($this->delivery($webhook)->id))->handle();

        Http::assertNothingSent();
        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('URL not allowed', $delivery->error);
    }

    public function test_on_a_sync_queue_web_requests_deliver_after_the_response(): void
    {
        Bus::fake([DeliverWebhook::class]);
        $webhook = $this->hook();
        $this->assertTrue(WebhookDispatcher::syncQueue());

        $console = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false); // as under php-fpm / artisan serve
        try {
            $this->item();
            Bus::assertDispatchedAfterResponse(DeliverWebhook::class);
            Bus::assertNotDispatchedSync(DeliverWebhook::class);

            // manual sends (test / redeliver) run inline so the admin sees the result
            WebhookDispatcher::queue($webhook, DomainEvents::envelope($this->workspace, 'ping', [], null), manual: true);
            Bus::assertDispatchedSync(DeliverWebhook::class);
        } finally {
            $console->setValue($this->app, true);
        }

        // a bulk action on a sync queue: 25 per request go after the response (one was used above), the rest wait for Redeliver
        $console->setValue($this->app, false);
        try {
            Item::factory()->count(WebhookDispatcher::MAX_AFTER_RESPONSE + 1)->create(['board_id' => $this->board->id, 'created_by' => $this->editor->id]);
        } finally {
            $console->setValue($this->app, true);
        }
        $this->assertSame(2, WebhookDelivery::where('status', WebhookDelivery::STATUS_FAILED)->where('error', 'like', 'Not sent:%')->count());
        Bus::assertDispatched(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->afterResponse && ! $job->manual);

        // a real queue: queued (and retried there)
        config(['queue.default' => 'database']);
        $this->item();
        Bus::assertDispatched(DeliverWebhook::class, fn (DeliverWebhook $job) => ! $job->manual);
    }

    public function test_after_a_response_deliveries_share_a_time_budget(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $webhook = $this->hook();
        $envelope = DomainEvents::envelope($this->workspace, 'ping', [], null);
        $first = $webhook->deliveries()->create(['event' => 'ping', 'event_id' => $envelope['id'], 'payload' => $envelope, 'status' => WebhookDelivery::STATUS_PENDING]);
        $second = $webhook->deliveries()->create(['event' => 'ping', 'event_id' => $envelope['id'], 'payload' => $envelope, 'status' => WebhookDelivery::STATUS_PENDING]);

        $job = new DeliverWebhook($first->id);
        $job->afterResponse = true;
        dispatch_sync($job);
        $this->assertSame(WebhookDelivery::STATUS_SUCCESS, $first->fresh()->status);
        Http::assertSent(fn ($request) => true);

        // the budget is used up (slow receivers): the rest is logged, not sent, and not held against the webhook
        $this->app->instance('webhooks.after-response-deadline', hrtime(true));
        $job = new DeliverWebhook($second->id);
        $job->afterResponse = true;
        dispatch_sync($job);
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $second->fresh()->status);
        $this->assertStringStartsWith('Not sent:', $second->fresh()->error);
        $this->assertSame(0, $webhook->fresh()->failure_count);
        Http::assertSentCount(1);
    }

    public function test_a_failing_endpoint_never_breaks_the_users_request(): void
    {
        Http::fake(['*' => Http::failedConnection('timeout')]);
        $this->hook();

        $this->actingAs($this->editor)->post(route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]), [
            'title' => 'Still created',
            'column_id' => $this->column('to-do'),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Item::where('title', 'Still created')->first());
        $this->assertSame(WebhookDelivery::STATUS_FAILED, WebhookDelivery::where('event', 'item.created')->value('status'));
    }

    public function test_old_deliveries_are_pruned_after_30_days(): void
    {
        $webhook = $this->hook();
        $old = $this->delivery($webhook);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $recent = $this->delivery($webhook);
        $recent->forceFill(['created_at' => now()->subDays(29)])->save();

        $this->artisan('model:prune', ['--model' => [WebhookDelivery::class]])->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertStringContainsString('WebhookDelivery::class', file_get_contents(base_path('routes/console.php')));
    }

    public function test_unknown_event_names_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DomainEvents::dispatch($this->workspace, 'item.exploded', []);
    }
}
