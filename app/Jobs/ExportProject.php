<?php

namespace App\Jobs;

use App\Models\ProjectExport;
use App\Notifications\ProjectExportReadyNotification;
use App\Support\Imports\Archive\ArchiveTooLarge;
use App\Support\Imports\Archive\ProjectArchiveWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes a project export (v1.41.0, BRAI-81) and tells the exporter (`export_ready`). The exporter
 * must still be allowed to update the project when the job runs; the archive contains only what
 * they may see. On the sync queue (dev) it runs after the response.
 */
class ExportProject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(public ProjectExport $export) {}

    public function handle(): void
    {
        $export = $this->export->fresh();
        if ($export === null || ! in_array($export->status, [ProjectExport::QUEUED, ProjectExport::RUNNING], true)) {
            return;
        }
        $project = $export->project;
        $user = $export->user;
        if ($project === null || $user === null || ! $user->can('update', $project)) {
            $export->forceFill(['status' => ProjectExport::FAILED, 'error' => 'You can no longer export this project.', 'finished_at' => now()])->save();

            return;
        }

        $export->forceFill(['status' => ProjectExport::RUNNING, 'error' => null])->save();
        $disk = Storage::disk('local');
        $relative = $export->directory().'/'.(Str::slug($project->name) ?: 'project').'-'.now()->format('Y-m-d').'.zip';
        $disk->makeDirectory($export->directory());

        try {
            $counts = ProjectArchiveWriter::write($project, $user, $disk->path($relative));
        } catch (ArchiveTooLarge $e) {
            $export->forceFill(['status' => ProjectExport::FAILED, 'error' => $e->getMessage(), 'finished_at' => now()])->save();
            $disk->deleteDirectory($export->directory());

            return;
        }

        $export->forceFill([
            'status' => ProjectExport::READY,
            'file_path' => $relative,
            'file_size' => (int) $disk->size($relative),
            'counts' => $counts,
            'finished_at' => now(),
            'expires_at' => now()->addHours(ProjectExport::TTL_HOURS),
        ])->save();

        $user->notify(new ProjectExportReadyNotification($export));
    }

    public function failed(?Throwable $e): void
    {
        $export = $this->export->fresh();
        if ($export === null) {
            return;
        }
        Storage::disk('local')->deleteDirectory($export->directory());
        $export->forceFill(['status' => ProjectExport::FAILED, 'file_path' => null, 'error' => 'The export stopped because of an unexpected error. Try again.', 'finished_at' => now()])->save();
    }
}
