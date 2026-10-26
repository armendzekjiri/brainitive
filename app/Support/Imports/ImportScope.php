<?php

namespace App\Support\Imports;

use Closure;

/**
 * "An import is writing right now" (v1.41.0). While active, per-record side effects that would
 * flood people or systems are off: assignment notifications (Assignments), webhooks and automation
 * rules (DomainEvents). History entries are still written. An import may send one summary later.
 */
final class ImportScope
{
    private static int $depth = 0;

    public static function run(Closure $work): mixed
    {
        self::$depth++;
        try {
            return $work();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
