<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Support\Imports\ImportFailed;
use App\Support\Imports\ImportProgress;
use App\Support\Imports\ImportRegistry;
use App\Support\Imports\ImportScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs a confirmed import (v1.41.0). Acts as the user who started it (history, permissions),
 * inside ImportScope (no per-record notifications / webhooks / automations). Resumable: the
 * importer creates everything through ImportMappings, so a retry continues where it stopped.
 */
class RunImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public array $backoff = [30, 120];

    public function __construct(public ImportRun $run) {}

    public function handle(): void
    {
        $lock = Cache::lock('import-run:'.$this->run->id, $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        $previous = Auth::user();
        try {
            $run = $this->run->fresh();
            if ($run === null || $run->isFinished() || ! in_array($run->status, [ImportRun::QUEUED, ImportRun::RUNNING, ImportRun::FAILED], true)) {
                return;
            }
            $user = $run->user;
            if ($user === null) {
                throw new ImportFailed('The person who started this import no longer exists.');
            }
            Auth::setUser($user);

            $run->forceFill(['status' => ImportRun::RUNNING, 'error' => null, 'started_at' => $run->started_at ?? now()])->save();
            $progress = new ImportProgress($run);
            $path = Storage::disk('local')->path((string) $run->file_path);

            try {
                $result = ImportScope::run(fn () => ImportRegistry::make($run->source)->run($run, $path, $progress));
            } catch (ImportFailed $e) {
                $progress->save();
                $this->fail($e); // the user has to change something: retrying the same input won't help

                return;
            }

            $progress->save();
            $run->forceFill(['status' => ImportRun::COMPLETED, 'result' => $result, 'finished_at' => now(), 'progress_done' => max($run->progress_done, $run->progress_total)])->save();
            self::deleteFiles($run);
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
            $lock->release();
        }
    }

    public function failed(?Throwable $e): void
    {
        $run = $this->run->fresh();
        if ($run === null) {
            return;
        }
        if ($e !== null && ! $e instanceof ImportFailed) {
            report($e);
        }
        $run->forceFill([
            'status' => ImportRun::FAILED,
            'error' => $e instanceof ImportFailed ? $e->getMessage() : 'The import stopped because of an unexpected error. Nothing was lost: retry it to continue where it stopped.',
        ])->save();
    }

    /** The uploaded file and everything extracted from it. */
    public static function deleteFiles(ImportRun $run): void
    {
        Storage::disk('local')->deleteDirectory('imports/'.$run->uuid);
    }
}
