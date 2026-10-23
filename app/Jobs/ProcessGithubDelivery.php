<?php

namespace App\Jobs;

use App\Models\GithubDelivery;
use App\Support\Github\DevelopmentSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessGithubDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = GithubDelivery::with('connection.project')->find($this->deliveryId);
        if (! $delivery || $delivery->status === 'processed' || ! $delivery->connection) {
            return;
        }

        $payload = json_decode($delivery->payload, true);
        if (! is_array($payload)) {
            $delivery->update(['status' => 'failed', 'error' => 'Payload is not JSON (set the webhook content type to application/json).']);
            $delivery->connection->update(['last_error' => 'Payload is not JSON — set the webhook content type to application/json.']);

            return;
        }

        DB::transaction(function () use ($delivery, $payload) {
            // One connection at a time, so deployment windows and branch history stay consistent.
            $connection = $delivery->connection->newQuery()->lockForUpdate()->find($delivery->github_connection_id);
            $connection->setRelation('project', $delivery->connection->project);
            (new DevelopmentSync($connection))->handle($delivery->event, $payload, $delivery->created_at);
            $connection->forceFill(['last_error' => null])->save();
            $delivery->update(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
        });
    }

    public function failed(Throwable $e): void
    {
        $delivery = GithubDelivery::with('connection')->find($this->deliveryId);
        $message = Str::limit(class_basename($e).': '.$e->getMessage(), 500);
        $delivery?->update(['status' => 'failed', 'error' => $message]);
        $delivery?->connection?->update(['last_error' => 'Processing a '.$delivery->event.' event failed. Redeliver it from GitHub once fixed.']);
    }
}
