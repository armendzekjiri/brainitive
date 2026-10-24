<?php

namespace App\Support\Billing;

/**
 * Invoice numbers (v1.38.0, BRAI-101): prefix + the consecutive number, zero-padded to 3 digits
 * ("2026-" + 14 → "2026-014"). Taken from billing_settings.next_number under a row lock when a
 * report is finalized in invoice mode; a number is never reused, also after a void. Plain time
 * reports have no number. Use this helper wherever a number is shown before it exists (settings).
 */
final class InvoiceNumber
{
    public static function format(?string $prefix, int $number): string
    {
        return ($prefix ?? '').str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
