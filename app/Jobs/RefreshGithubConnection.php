<?php

namespace App\Jobs;

use App\Models\GithubConnection;
use App\Support\Github\ConnectionRefresh;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** "Refresh" on the project's GitHub card (BRAI-61); one at a time per connection. */
class RefreshGithubConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(): void
    {
        $connection = GithubConnection::with(['project', 'installation'])->find($this->connectionId);
        if ($connection) {
            ConnectionRefresh::run($connection);
        }
    }
}
