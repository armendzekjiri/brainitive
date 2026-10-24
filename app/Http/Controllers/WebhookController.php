<?php

namespace App\Http\Controllers;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use App\Support\Events\DomainEvents;
use App\Support\Webhooks\WebhookDispatcher;
use App\Support\Webhooks\WebhookUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace settings → Webhooks (v1.39.0, BRAI-104). Workspace admins only.
 * The page is Inertia; every action answers JSON (the secret is returned only by store, secret and
 * rotate — never as an Inertia prop). Deliveries are loaded per webhook on demand.
 */
class WebhookController extends Controller
{
    public const MAX_PER_WORKSPACE = 20;

    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('update', $workspace);

        return Inertia::render('Workspaces/Webhooks', [
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'webhooks' => $this->list($workspace),
            'eventGroups' => DomainEvents::grouped(),
            'requiresHttps' => WebhookUrl::requiresHttps(),
            'maxFailures' => Webhook::MAX_CONSECUTIVE_FAILURES,
        ]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('update', $workspace);
        $validated = $this->validated($request);
        if (Webhook::where('workspace_id', $workspace->id)->count() >= self::MAX_PER_WORKSPACE) {
            throw ValidationException::withMessages(['name' => 'A workspace can have at most '.self::MAX_PER_WORKSPACE.' webhooks.']);
        }

        $secret = Webhook::generateSecret();
        $webhook = Webhook::create($validated + [
            'workspace_id' => $workspace->id,
            'secret' => $secret,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['webhook' => $this->present($webhook), 'secret' => $secret], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($workspace, $webhook);
        $validated = $this->validated($request);

        // switching it back on starts a fresh failure count
        if ($validated['active'] && (! $webhook->active || $webhook->disabled_at)) {
            $validated += ['failure_count' => 0, 'disabled_at' => null];
        }
        $webhook->update($validated);

        return response()->json(['webhook' => $this->present($webhook)]);
    }

    public function destroy(Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($workspace, $webhook);
        $webhook->delete();

        return response()->json(['deleted' => true]);
    }

    public function secret(Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($workspace, $webhook);

        return response()->json(['secret' => $webhook->secret])->header('Cache-Control', 'no-store');
    }

    public function rotate(Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($workspace, $webhook);
        $secret = Webhook::generateSecret();
        $webhook->update(['secret' => $secret]);

        return response()->json(['secret' => $secret])->header('Cache-Control', 'no-store');
    }

    /** Sends a `ping` event (runs inline on a sync queue so the result shows at once). */
    public function test(Request $request, Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($workspace, $webhook);

        $envelope = DomainEvents::envelope($workspace, DomainEvents::PING, [
            'webhook' => ['id' => $webhook->id, 'name' => $webhook->name],
            'message' => 'Hello from Brainitive! Your webhook endpoint is reachable.',
        ], $request->user());
        $delivery = WebhookDispatcher::queue($webhook, $envelope, manual: true);

        return response()->json(['delivery' => $this->presentDelivery($delivery->fresh())]);
    }

    public function deliveries(Request $request, Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($workspace, $webhook);
        $before = $request->integer('before');

        $rows = $webhook->deliveries()
            ->when($before > 0, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(26)
            ->get();

        return response()->json([
            'data' => $rows->take(25)->map(fn (WebhookDelivery $d) => $this->presentDelivery($d))->values(),
            'has_more' => $rows->count() > 25,
            'webhook' => $this->present($webhook->fresh()->setAttribute('last_status', $before > 0 ? null : $rows->first()?->status)),
        ]);
    }

    /** Sends the same envelope again (same event id, new delivery id). */
    public function redeliver(Workspace $workspace, Webhook $webhook, WebhookDelivery $delivery): JsonResponse
    {
        $this->authorizeWebhook($workspace, $webhook);
        abort_unless($delivery->webhook_id === $webhook->id, 404);

        $copy = WebhookDispatcher::queue($webhook, $delivery->payload, manual: true);

        return response()->json(['delivery' => $this->presentDelivery($copy->fresh())]);
    }

    private function authorizeWebhook(Workspace $workspace, Webhook $webhook): void
    {
        Gate::authorize('update', $workspace);
        abort_unless($webhook->workspace_id === $workspace->id, 404);
    }

    /**
     * @return array{name: string, url: string, events: list<string>, active: bool}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => WebhookUrl::rules(),
            'events' => ['required', 'array', 'min:1', 'max:'.(count(DomainEvents::EVENTS) + 1)],
            'events.*' => ['string', 'distinct', Rule::in([Webhook::ALL_EVENTS, ...array_keys(DomainEvents::EVENTS)])],
            'active' => ['boolean'],
        ]);

        return [
            'name' => trim($validated['name']),
            'url' => trim($validated['url']),
            'events' => Webhook::normalizeEvents($validated['events']),
            'active' => (bool) ($validated['active'] ?? true),
        ];
    }

    private function list(Workspace $workspace): array
    {
        return Webhook::where('workspace_id', $workspace->id)
            ->addSelect(['last_status' => WebhookDelivery::select('status')->whereColumn('webhook_id', 'webhooks.id')->orderByDesc('id')->limit(1)])
            ->with('creator:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Webhook $webhook) => $this->present($webhook))
            ->all();
    }

    private function present(Webhook $webhook): array
    {
        return [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'url' => $webhook->url,
            'events' => $webhook->events ?? [],
            'active' => $webhook->active,
            'failure_count' => $webhook->failure_count,
            'disabled_at' => $webhook->disabled_at?->toIso8601String(),
            'last_delivery_at' => $webhook->last_delivery_at?->toIso8601String(),
            'last_status' => $webhook->getAttribute('last_status'),
            'created_by' => $webhook->relationLoaded('creator') ? $webhook->creator?->name : null,
            'created_at' => $webhook->created_at?->toIso8601String(),
        ];
    }

    private function presentDelivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'uuid' => $delivery->uuid,
            'event' => $delivery->event,
            'event_id' => $delivery->event_id,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'response_code' => $delivery->response_code,
            'duration_ms' => $delivery->duration_ms,
            'error' => $delivery->error,
            'payload' => $delivery->payload,
            'response_body' => $delivery->response_body,
            'created_at' => $delivery->created_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
        ];
    }
}
