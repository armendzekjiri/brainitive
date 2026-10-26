<?php

namespace App\Jobs;

use App\Models\GithubAppDelivery;
use App\Support\Github\AppDeliveries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/** One delivery to the GitHub App's webhook (BRAI-61); see AppDeliveries. */
class ProcessGithubAppDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = GithubAppDelivery::find($this->deliveryId);
        if (! $delivery || in_array($delivery->status, ['processed', 'ignored'], true)) {
            return;
        }

        AppDeliveries::process($delivery);
    }

    public function failed(Throwable $e): void
    {
        $delivery = GithubAppDelivery::with('connection')->find($this->deliveryId);
        $delivery?->update(['status' => 'failed', 'error' => Str::limit(class_basename($e).': '.$e->getMessage(), 500)]);
        $delivery?->connection?->update(['last_error' => 'Processing a '.$delivery->event.' event failed. Redeliver it from the GitHub App’s advanced settings once fixed.']);
    }
}
