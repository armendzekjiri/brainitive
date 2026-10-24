<?php

namespace App\Support\Workload;

/**
 * Hours for messages ("25h", "7h 30m", "0h"); mirrors formatHours() in resources/js/lib/workload.ts.
 * Workload amounts are hours and minutes only: Jira's 1d = 8h would be misleading for capacity.
 */
class WorkloadFormat
{
    public static function hours(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        if ($m === 0) {
            return "{$sign}{$h}h";
        }

        return $h === 0 ? "{$sign}{$m}m" : "{$sign}{$h}h {$m}m";
    }
}
