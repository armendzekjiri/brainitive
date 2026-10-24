<?php

namespace App\Support\Insights;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of an Insights tab (BRAI-105): the numbers of the page, as tables separated by an
 * empty line. Text cells that a spreadsheet would run as a formula get a leading apostrophe.
 */
final class InsightsCsv
{
    /**
     * @param  array<string, mixed>  $data  the tab's compute() result
     */
    public static function download(string $tab, array $data, InsightsFilters $filters): StreamedResponse
    {
        $tables = self::tables($tab, $data, $filters);
        $name = sprintf('insights-%s-%s-%s.csv', $tab, $filters->from->toDateString(), $filters->to->toDateString());

        return response()->streamDownload(function () use ($tables) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM: Excel reads umlauts correctly
            foreach ($tables as $i => $rows) {
                if ($i > 0) {
                    fputcsv($out, [], ',', '"', '');
                }
                foreach ($rows as $row) {
                    fputcsv($out, array_map(self::cell(...), $row), ',', '"', '');
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<list<list<mixed>>> tables of rows; each table starts with its header row
     */
    public static function tables(string $tab, array $data, InsightsFilters $filters): array
    {
        $scope = [
            ['Workspace', $filters->workspace->name],
            ['From', $filters->from->toDateString()],
            ['To', $filters->to->toDateString()],
            ['Project', $filters->projectId !== null ? $filters->projects[$filters->projectId]['name'] : 'All projects'],
            ['Time zone', $filters->timezone],
        ];

        return match ($tab) {
            'overview' => [
                $scope,
                [
                    ['Metric', 'Value'],
                    ['Issues created', $data['kpis']['created']],
                    ['Issues completed', $data['kpis']['completed']],
                    ['Completion rate (%)', $data['kpis']['completion_rate']],
                    ['Median cycle time (days)', $data['kpis']['median_cycle_days']],
                    ['Open now', $data['kpis']['open']],
                    ['Overdue now', $data['kpis']['overdue']],
                    ['Hours logged', $data['kpis']['hours']],
                ],
                [['Week starting', 'Created', 'Completed'], ...array_map(fn ($w) => [$w['week'], $w['created'], $w['completed']], $data['weeks'])],
                [['Project', 'Open', 'Overdue'], ...array_map(fn ($p) => [$p['name'], $p['open'], $p['overdue']], $data['projects'])],
                [['Issue type', 'Created', 'Done'], ...array_map(fn ($t) => [$t['name'] ?? 'No type', $t['created'], $t['completed']], $data['types'])],
            ],
            'team' => [
                $scope,
                [
                    ['Member', 'Role', 'Completed', 'Open assigned', 'Overdue', 'Hours logged', 'Comments', 'Last sign-in', 'Last activity'],
                    ...array_map(fn ($m) => [$m['name'], $m['role'], $m['completed'], $m['open'], $m['overdue'], $m['hours'], $m['comments'], $m['last_login_at'], $m['last_activity_at']], $data['members']),
                ],
            ],
            'projects' => [
                $scope,
                [
                    ['Project', 'Key', 'Health', 'Issues', 'Open', 'Overdue', 'Done in range', 'Hours logged', 'Last activity'],
                    ...array_map(fn ($p) => [$p['name'], $p['key'], $p['health'], $p['issues'], $p['open'], $p['overdue'], $p['done_in_range'], $p['hours'], $p['last_activity_at']], $data['projects']),
                ],
            ],
            'helpdesk' => [
                $scope,
                [
                    ['Metric', 'Value'],
                    ['Tickets created', $data['tickets']['created']],
                    ['Tickets resolved', $data['tickets']['resolved']],
                    ['Open tickets now', $data['tickets']['open']],
                    ['Median resolution time (hours)', $data['tickets']['median_resolution_hours']],
                    ['SLA breaches', $data['sla']['breaches']],
                    ['SLA met (%)', $data['sla']['met_percent']],
                    ['Leads received', $data['leads']['total']],
                ],
                [['Helpdesk', 'Project', 'Created', 'Resolved', 'Open now', 'SLA breaches', 'SLA met (%)'], ...array_map(fn ($h) => [$h['name'], $h['project'], $h['created'], $h['resolved'], $h['open'], $h['sla_breaches'], $h['sla_met_percent']], $data['helpdesks'])],
                [['Lead form', 'Project', 'Status', 'Leads', 'Closed', 'Views', 'Starts', 'Submits', 'Conversion (%)'], ...array_map(fn ($f) => [$f['name'], $f['project'], $f['status'], $f['leads'], $f['closed'], $f['views'], $f['starts'], $f['submits'], $f['conversion']], $data['forms'])],
            ],
            'usage' => [
                [['Workspace', $filters->workspace->name], ['Project', $scope[3][1]]],
                [
                    ['Metric', 'Value'],
                    ['Members', $data['members']],
                    ...array_map(fn ($role, $total) => ["Role: {$role}", $total], array_keys($data['roles']), $data['roles']),
                    ['Pending invitations', $data['pending_invites']],
                    ['Projects', $data['projects']],
                    ['Storage (bytes)', $data['storage_bytes']],
                    ['Webhooks', $data['webhooks']],
                ],
                [['Project', 'Files', 'Issue attachments (bytes)', 'Ticket attachments (bytes)', 'Total (bytes)'], ...array_map(fn ($s) => [$s['name'], $s['files'], $s['issue_bytes'], $s['ticket_bytes'], $s['bytes']], $data['storage'])],
            ],
        };
    }

    /** Neutralise spreadsheet formulas in text cells (CSV injection). */
    private static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
