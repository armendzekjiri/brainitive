<?php

namespace App\Support\Imports;

use App\Models\ImportRun;

/**
 * Progress + report of a running import (v1.41.0). Counts are saved to the run at most every
 * second (and at the end), so the import page can poll them.
 *
 * Report shape (ImportRun::report): {created: {issues: 12, columns: 4, …}, warnings: [string],
 * unsupported: {"Custom fields": 3, …}}. Warnings are capped at 200 lines.
 */
final class ImportProgress
{
    private array $created = [];

    private array $warnings = [];

    private array $unsupported = [];

    private float $savedAt = 0;

    public function __construct(private readonly ImportRun $run)
    {
        $report = $run->report ?? [];
        $this->created = $report['created'] ?? [];
        $this->warnings = $report['warnings'] ?? [];
        $this->unsupported = $report['unsupported'] ?? [];
    }

    public function total(int $total): void
    {
        $this->run->forceFill(['progress_total' => $total])->save();
    }

    public function advance(int $by = 1): void
    {
        $this->run->progress_done += $by;
        $this->saveSoon();
    }

    public function created(string $what, int $count = 1): void
    {
        $this->created[$what] = ($this->created[$what] ?? 0) + $count;
    }

    public function warn(string $message): void
    {
        if (count($this->warnings) < 200 && ! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    public function unsupported(string $what, int $count = 1): void
    {
        $this->unsupported[$what] = ($this->unsupported[$what] ?? 0) + $count;
    }

    public function save(): void
    {
        $this->run->forceFill([
            'report' => ['created' => $this->created, 'warnings' => $this->warnings, 'unsupported' => $this->unsupported],
        ])->save();
        $this->savedAt = microtime(true);
    }

    private function saveSoon(): void
    {
        if (microtime(true) - $this->savedAt >= 1.0) {
            $this->save();
        }
    }
}
