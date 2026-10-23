<?php

namespace App\Console\Commands;

use App\Support\Uploads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Lists files on the public disk a web server could execute or a browser could render as a
 * page (stored before upload names were made safe). Review them, then remove the ones that
 * aren't legitimate with --delete.
 */
class AuditUploads extends Command
{
    protected $signature = 'uploads:audit {--delete : Delete the listed files}';

    protected $description = 'Find uploaded files with executable or active-content extensions (php, html, svg, …)';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $all = collect($disk->allFiles());
        $found = $all->filter(fn (string $path) => Uploads::isActive($path))->values();
        $unknown = $all->filter(fn (string $path) => Uploads::isUnknownType($path))->values();

        if ($unknown->isNotEmpty()) {
            $this->line('Not on the allowlist (stored before it existed) — review, not deleted by --delete:');
            $this->table(['Path', 'Size'], $unknown->map(fn (string $path) => [$path, number_format($disk->size($path)).' B']));
        }

        if ($found->isEmpty()) {
            $this->info('No executable or markup files on the public disk.');

            return self::SUCCESS;
        }

        $this->table(['Path', 'Size', 'Modified'], $found->map(fn (string $path) => [
            $path,
            number_format($disk->size($path)).' B',
            date('Y-m-d H:i', $disk->lastModified($path)),
        ]));

        if ($this->option('delete')) {
            $disk->delete($found->all());
            $this->warn("Deleted {$found->count()} file(s).");
        } else {
            $this->warn("{$found->count()} file(s). Check them (a .php upload means someone tried to run code), then re-run with --delete.");
        }

        return self::SUCCESS;
    }
}
