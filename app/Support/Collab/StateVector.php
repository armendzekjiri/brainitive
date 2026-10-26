<?php

namespace App\Support\Collab;

/**
 * Yjs state vectors as `{client id: clock}` (BRAI-54). A browser sends the vector its draft HTML was
 * generated from; a save whose vector is covered by the stored one has nothing new and is skipped,
 * so an older snapshot never overwrites a newer one.
 */
final class StateVector
{
    /** More clients than a document plausibly ever has; keeps the stored JSON small. */
    public const MAX_CLIENTS = 5000;

    /**
     * Validate a decoded vector: integer client ids (Yjs uses uint32) → non-negative integer clocks.
     *
     * @return array<int, int>|null
     */
    public static function normalize(mixed $vector): ?array
    {
        if (! is_array($vector) || count($vector) > self::MAX_CLIENTS) {
            return null;
        }
        $normalized = [];
        foreach ($vector as $client => $clock) {
            if (! ctype_digit((string) $client) || ! is_int($clock) || $clock < 0) {
                return null;
            }
            $client = (int) $client;
            if ($client > 4294967295) {
                return null;
            }
            $normalized[$client] = $clock;
        }

        return $normalized;
    }

    /**
     * Whether `$stored` already contains everything in `$incoming` (every client's clock ≥).
     *
     * @param  array<int|string, int>  $stored
     * @param  array<int|string, int>  $incoming
     */
    public static function covers(array $stored, array $incoming): bool
    {
        foreach ($incoming as $client => $clock) {
            if ($clock > ($stored[$client] ?? 0)) {
                return false;
            }
        }

        return true;
    }
}
