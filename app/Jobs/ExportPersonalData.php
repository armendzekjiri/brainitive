<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Models\User;
use App\Notifications\DataExportReadyNotification;
use App\Support\Privacy\PersonalDataArchive;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Writes a "Download my data" archive (GDPR Art. 15 + 20, BRAI-156 follow-up) and tells its owner
 * (`data_export_ready`, in-app + email). On the sync queue (dev, e2e) it runs after the response.
 */
class ExportPersonalData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    /** The account (and with it the row) was deleted before the job ran: nothing to do. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public DataExport $export) {}

    /** A slow run (longer than the queue's retry_after) must not be started a second time in parallel. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('data-export:'.$this->export->uuid))->dontRelease()->expireAfter($this->timeout)];
    }

    public function handle(): void
    {
        $export = $this->export->fresh();
        if ($export === null || ! in_array($export->status, [DataExport::QUEUED, DataExport::RUNNING], true)) {
            return;
        }
        $user = $export->user;
        if ($user === null) {
            return;
        }

        $export->forceFill(['status' => DataExport::RUNNING, 'error' => null])->save();
        $disk = Storage::disk('local');
        $relative = $export->directory().'/brainitive-my-data-'.now()->format('Y-m-d').'.zip';
        $disk->makeDirectory($export->directory());

        $counts = PersonalDataArchive::write($user, $disk->path($relative));

        // the account (and with it the row) was deleted while the ZIP was written: keep nothing
        if (! DataExport::whereKey($export->id)->exists() || ! User::whereKey($user->id)->exists()) {
            $disk->deleteDirectory($export->directory());

            return;
        }

        $export->forceFill([
            'status' => DataExport::READY,
            'file_path' => $relative,
            'file_size' => (int) $disk->size($relative),
            'counts' => $counts,
            'finished_at' => now(),
            'expires_at' => now()->addHours(DataExport::TTL_HOURS),
        ])->save();

        $user->notify(new DataExportReadyNotification($export));
    }

    public function failed(?Throwable $e): void
    {
        // the serialized uuid: the row may be gone (account deleted), the folder must go anyway
        Storage::disk('local')->deleteDirectory($this->export->directory());
        $export = $this->export->fresh();
        if ($export === null) {
            return;
        }
        $export->forceFill(['status' => DataExport::FAILED, 'file_path' => null, 'error' => 'The export stopped because of an unexpected error. Try again.', 'finished_at' => now()])->save();
        // a failed export doesn't count against the hourly limit
        if ($export->user !== null) {
            RateLimiter::clear(DataExport::limiterKey($export->user));
        }
    }
}
