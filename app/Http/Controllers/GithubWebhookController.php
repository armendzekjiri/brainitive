<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGithubDelivery;
use App\Models\GithubConnection;
use App\Models\GithubDelivery;
use App\Support\Github\DevelopmentSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives GitHub webhooks for one project connection. Verifies the signature over the raw
 * body, stores the delivery once (X-GitHub-Delivery) and answers fast; a queued job does the work.
 */
class GithubWebhookController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __invoke(Request $request, string $uuid): JsonResponse
    {
        $connection = GithubConnection::where('uuid', $uuid)->first();
        if (! $connection) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BYTES) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $body, $connection->secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        $deliveryId = substr((string) $request->header('X-GitHub-Delivery', ''), 0, 100);
        if ($deliveryId === '' || ! in_array($event, DevelopmentSync::EVENTS, true)) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        // createOrFirst: the insert runs in a savepoint, so a duplicate doesn't abort an
        // enclosing Postgres transaction
        $delivery = GithubDelivery::createOrFirst(
            ['github_connection_id' => $connection->id, 'delivery_id' => $deliveryId],
            ['event' => $event, 'payload' => $body],
        );
        if (! $delivery->wasRecentlyCreated) {
            if ($delivery->status !== 'failed') {
                return response()->json(['message' => 'Already received.'], 200);
            }
            // GitHub "Redeliver" of a failed delivery: process it again.
            $delivery->update(['status' => 'pending', 'error' => null]);
        }

        $connection->forceFill(['last_delivery_at' => now(), 'last_event' => $event])->save();
        ProcessGithubDelivery::dispatch($delivery->id);

        return response()->json(['message' => 'Accepted.'], 202);
    }
}
