<?php

namespace App\Support\Entitlements;

/**
 * How long a workspace keeps old doc / Gantt plan versions (Cloud plans, v1.46.0): the newest
 * `keepLatest` versions stay forever, older ones are pruned after `keepDays` days.
 * `keepDays === null` keeps every version forever (self-hosted, Team, Business).
 */
final readonly class VersionRetention
{
    public function __construct(
        public int $keepLatest = 0,
        public ?int $keepDays = null,
    ) {}

    public static function forever(): self
    {
        return new self;
    }

    public function keepsEverything(): bool
    {
        return $this->keepDays === null;
    }

    /** @return array{keepLatest: int, keepDays: int|null} */
    public function toArray(): array
    {
        return ['keepLatest' => $this->keepLatest, 'keepDays' => $this->keepDays];
    }
}
