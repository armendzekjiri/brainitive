<?php

namespace App\Console\Commands;

use App\Models\EditorUpload;
use App\Support\Files\WorkspaceFiles;
use Illuminate\Console\Command;

/**
 * Codex web review C (v1.49.0): icons uploaded before their bookmark / snippet collection exists (`kind` temp) count
 * against the workspace's file storage until they are used (moved into place) or pruned here, a day later: file
 * deleted, row dropped, bytes given back. Scheduled daily (routes/console.php).
 */
class PruneTemporaryFiles extends Command
{
    protected $signature = 'files:prune-temp {--hours='.WorkspaceFiles::TEMP_HOURS.' : delete temporary uploads older than this}';

    protected $description = 'Delete abandoned temporary uploads (icons picked for something never created) and release their storage';

    public function handle(): int
    {
        $deleted = 0;
        EditorUpload::query()->where('kind', 'temp')
            ->where('created_at', '<', now()->subHours(max(1, (int) $this->option('hours'))))
            ->with('workspace')
            ->chunkById(500, function ($rows) use (&$deleted) {
                foreach ($rows as $row) {
                    if ($row->workspace) {
                        WorkspaceFiles::forget($row->workspace, $row->path);
                        $deleted++;
                    }
                }
            });

        $this->info("Removed {$deleted} temporary upload(s).");

        return self::SUCCESS;
    }
}
