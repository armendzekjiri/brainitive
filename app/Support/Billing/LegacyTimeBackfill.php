<?php

namespace App\Support\Billing;

use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Work logged before the time ledger existed (v1.38.0, BRAI-99): one `legacy` TimeEntry per
 * `work_logged` history entry on a live issue — minutes and note from the log's metadata, the day
 * from its timestamp in the author's zone, project / workspace from the issue, no activity,
 * billable. Rows are inserted with the query builder (no model events): the minutes are already in
 * `items.time_spent` and stay there unchanged.
 *
 * Safe to re-run: every row remembers its log (`legacy_log_id`, unique; insertOrIgnore), and logs
 * written together with a ledger entry (`metadata.time_entry_id`, since v1.38.0) are skipped.
 */
class LegacyTimeBackfill
{
    private const ITEM_CLASS = 'App\\Models\\Item';

    /** @return int rows created */
    public static function run(): int
    {
        $created = 0;

        DB::table('activity_logs as l')
            ->join('items as i', 'i.id', '=', 'l.loggable_id')
            ->join('boards as b', 'b.id', '=', 'i.board_id')
            ->join('projects as p', 'p.id', '=', 'b.project_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->leftJoin('time_entries as t', 't.legacy_log_id', '=', 'l.id')
            ->where('l.loggable_type', self::ITEM_CLASS)
            ->where('l.action', 'work_logged')
            ->whereNull('i.deleted_at')
            ->whereNull('t.id')
            ->select([
                'l.id as log_id', 'l.user_id', 'l.metadata', 'l.created_at',
                'i.id as item_id', 'i.key as item_key', 'i.title as item_title',
                'p.id as project_id', 'p.workspace_id', 'u.timezone',
            ])
            ->chunkById(500, function ($logs) use (&$created) {
                $rows = [];
                foreach ($logs as $log) {
                    $row = self::row($log);
                    if ($row) {
                        $rows[] = $row;
                    }
                }
                if ($rows) {
                    $created += DB::table('time_entries')->insertOrIgnore($rows);
                }
            }, 'l.id', 'log_id');

        return $created;
    }

    /** @return array<string, mixed>|null */
    private static function row(object $log): ?array
    {
        $metadata = is_string($log->metadata) ? json_decode($log->metadata, true) : (array) $log->metadata;
        if (! is_array($metadata) || isset($metadata['time_entry_id'])) {
            return null; // not a log, or logged with a ledger entry already
        }
        $minutes = filter_var($metadata['minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 1) {
            return null;
        }

        $zone = $log->timezone && in_array($log->timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
            ? $log->timezone
            : config('app.timezone');
        $note = isset($metadata['note']) && is_scalar($metadata['note']) && $metadata['note'] !== '' ? (string) $metadata['note'] : null;
        $now = now();

        return [
            'workspace_id' => $log->workspace_id,
            'project_id' => $log->project_id,
            'item_id' => $log->item_id,
            'user_id' => $log->user_id,
            'billing_activity_id' => null,
            'spent_on' => CarbonImmutable::parse($log->created_at, 'UTC')->setTimezone($zone)->toDateString(),
            'minutes' => $minutes,
            'note' => $note,
            'billable' => true,
            'source' => TimeEntry::SOURCE_LEGACY,
            'item_key' => $log->item_key ? Str::limit((string) $log->item_key, 40, '') : null,
            'item_title' => $log->item_title !== null ? Str::limit((string) $log->item_title, 250, '') : null,
            'legacy_log_id' => $log->log_id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
