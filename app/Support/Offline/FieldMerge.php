<?php

namespace App\Support\Offline;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Field-level three-way check for an offline edit (BRAI-82). For every field the device changed:
 * - the server still has the value the device saw (base) → write mine;
 * - the server already has my value → nothing to do;
 * - anything else → a conflict: someone changed it meanwhile. Never written.
 * Fields the device didn't touch are never written, so other people's changes to them survive.
 */
final class FieldMerge
{
    public const INT = 'int';

    public const STRING = 'string';

    public const DATE = 'date';

    /**
     * @param  array<string, mixed>  $current  server values
     * @param  array<string, mixed>  $base  what the device saw, per changed field
     * @param  array<string, mixed>  $mine  the device's new values
     * @param  array<string, string>  $kinds  field => INT | STRING | DATE
     * @return array{apply: array<string, mixed>, conflicts: array<string, array{base: mixed, mine: mixed, theirs: mixed}>}
     */
    public static function merge(array $current, array $base, array $mine, array $kinds): array
    {
        $apply = [];
        $conflicts = [];

        foreach ($mine as $field => $value) {
            $kind = $kinds[$field];
            $theirs = self::normalize($current[$field] ?? null, $kind);
            $wanted = self::normalize($value, $kind);
            $seen = self::normalize($base[$field] ?? null, $kind);

            if ($theirs === $wanted) {
                continue;
            }
            if ($theirs === $seen) {
                $apply[$field] = $wanted;

                continue;
            }
            $conflicts[$field] = ['base' => $seen, 'mine' => $wanted, 'theirs' => $theirs];
        }

        return ['apply' => $apply, 'conflicts' => $conflicts];
    }

    public static function normalize(mixed $value, string $kind): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($kind) {
            self::INT => is_numeric($value) ? (int) $value : null,
            self::DATE => self::date($value),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    private static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
