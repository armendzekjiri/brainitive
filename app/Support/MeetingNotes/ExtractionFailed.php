<?php

namespace App\Support\MeetingNotes;

use RuntimeException;

/**
 * Extraction stopped before any proposal was returned. `code` is stable for the UI
 * (not_configured, endpoint_blocked, timeout, provider_error, invalid_output, too_long, empty).
 */
class ExtractionFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
