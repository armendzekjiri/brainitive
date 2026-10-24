<?php

namespace App\Support\ProjectHealth;

use RuntimeException;

/** The AI narrative couldn't be drafted; the metrics stay usable. */
class HealthAiException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $notConfigured = false)
    {
        parent::__construct($message);
    }
}
