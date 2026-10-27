<?php

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\BookingType;

/**
 * Words about a booking for the calendar event, the card and the emails (BRAI-158). Guest input is
 * plain text everywhere: escaped in HTML, never turned into links.
 */
final class BookingText
{
    public const LOCATION_LABELS = [
        'none' => null,
        'google_meet' => 'Google Meet',
        'teams' => 'Microsoft Teams',
        'phone' => 'Phone call',
        'in_person' => 'In person',
        'link' => 'Online',
    ];

    /** Where the meeting happens, for a calendar's location field (the call link comes from the provider). */
    public static function location(BookingType $type): ?string
    {
        $kind = $type->location['kind'] ?? 'none';
        $value = trim((string) ($type->location['value'] ?? ''));

        return match ($kind) {
            'phone', 'in_person', 'link' => $value !== '' ? $value : self::LOCATION_LABELS[$kind],
            // Meet / Teams: the provider's call link is the location; without one there's nothing to say
            default => null,
        };
    }

    /** @return list<array{0: string, 1: string}> question label → answer, in the type's question order */
    public static function answers(Booking $booking): array
    {
        $answers = (array) $booking->answers;
        $out = [];
        foreach ((array) ($booking->type?->questions ?? []) as $question) {
            $value = $answers[$question['id'] ?? ''] ?? null;
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            if ($value !== null && trim((string) $value) !== '') {
                $out[] = [(string) ($question['label'] ?? ''), trim((string) $value)];
            }
        }

        return $out;
    }

    public static function eventDescription(Booking $booking): string
    {
        $lines = ["Booked by {$booking->guest_name} <{$booking->guest_email}> via Brainitive."];
        foreach (self::answers($booking) as [$label, $value]) {
            $lines[] = "{$label}: {$value}";
        }

        return implode("\n", $lines);
    }

    public static function cardHtml(Booking $booking): string
    {
        $html = '<p><strong>'.e($booking->guest_name).'</strong> · '.e($booking->guest_email).'</p>';
        foreach (self::answers($booking) as [$label, $value]) {
            $html .= '<p><strong>'.e($label).':</strong> '.nl2br(e($value)).'</p>';
        }

        return $html;
    }

    /** "Tue 5 Jan 2027, 14:00–14:30 (Europe/Berlin)" */
    public static function when(Booking $booking, string $timezone): string
    {
        $start = $booking->starts_at->setTimezone($timezone);
        $end = $booking->ends_at->setTimezone($timezone);

        return $start->format('D j M Y, H:i').'–'.$end->format('H:i').' ('.str_replace('_', ' ', $timezone).')';
    }
}
