<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGithubAppDelivery;
use App\Models\GithubAppDelivery;
use App\Support\Github\AppDeliveries;
use App\Support\Github\GithubAppConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The GitHub App's one webhook (BRAI-61). Verifies X-Hub-Signature-256 with the app's webhook
 * secret over the raw body, stores each X-GitHub-Delivery once and answers fast; a queued job
 * routes it (installation + repository → project) and syncs.
 */
class GithubAppWebhookController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __invoke(Request $request): JsonResponse
    {
        $app = GithubAppConfig::current();
        if (! $app) {
            return response()->json(['message' => 'No GitHub App is configured.'], 404);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BYTES) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        if (! hash_equals('sha256='.hash_hmac('sha256', $body, $app->webhookSecret), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        $deliveryId = substr((string) $request->header('X-GitHub-Delivery', ''), 0, 100);
        if ($deliveryId === '' || ! in_array($event, AppDeliveries::EVENTS, true)) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $installationId = (int) data_get(json_decode($body, true), 'installation.id') ?: null;

        // createOrFirst: the insert runs in a savepoint, so a duplicate doesn't abort an
        // enclosing Postgres transaction
        $delivery = GithubAppDelivery::createOrFirst(
            ['delivery_id' => $deliveryId],
            ['event' => $event, 'payload' => $body, 'installation_id' => $installationId],
        );
        if (! $delivery->wasRecentlyCreated) {
            if ($delivery->status !== 'failed') {
                return response()->json(['message' => 'Already received.'], 200);
            }
            // GitHub "Redeliver" of a failed delivery: process it again.
            $delivery->update(['status' => 'pending', 'error' => null]);
        }

        ProcessGithubAppDelivery::dispatch($delivery->id);

        return response()->json(['message' => 'Accepted.'], 202);
    }
}
