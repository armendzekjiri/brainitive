<?php

namespace App\Support\Entitlements;

use Carbon\CarbonImmutable;

/**
 * What one workspace may use (Cloud plans, v1.46.0). `null` means unlimited. Self-hosted installs
 * get `Limits::unlimited()` for every workspace; brainitive/cloud returns the workspace's plan.
 */
final readonly class Limits
{
    public function __construct(
        public string $plan,
        /** A free plan: counts towards Entitlements::freeWorkspacesPerOwner(). */
        public bool $free = false,
        public ?int $members = null,
        public ?int $projects = null,
        /** Docs, issues, comments … as measured by usage:measure (an approximation). */
        public ?int $contentBytes = null,
        /** Uploaded files and attachments. */
        public ?int $fileBytes = null,
        public VersionRetention $versions = new VersionRetention,
        /** Until then nothing is pruned or deleted after a downgrade / failed payment (90 days). */
        public ?CarbonImmutable $graceUntil = null,
    ) {}

    public static function unlimited(string $plan = 'Unlimited'): self
    {
        return new self($plan);
    }

    public function isUnlimited(): bool
    {
        return $this->members === null && $this->projects === null && $this->contentBytes === null
            && $this->fileBytes === null && $this->versions->keepsEverything();
    }

    public function inGrace(): bool
    {
        return $this->graceUntil !== null && $this->graceUntil->isFuture();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan,
            'free' => $this->free,
            'members' => $this->members,
            'projects' => $this->projects,
            'contentBytes' => $this->contentBytes,
            'fileBytes' => $this->fileBytes,
            'versions' => $this->versions->toArray(),
            'graceUntil' => $this->graceUntil?->toIso8601String(),
        ];
    }
}
