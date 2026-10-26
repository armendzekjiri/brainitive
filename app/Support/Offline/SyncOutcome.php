<?php

namespace App\Support\Offline;

/**
 * The answer to one offline mutation (BRAI-82). Applied (200) and conflict (409) are final and
 * stored for replays; deleted (410) and waiting (424) wrote nothing.
 */
final class SyncOutcome
{
    /** @param array<string, mixed> $body */
    private function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly ?string $entityType = null,
        public readonly ?int $entityId = null,
    ) {}

    /** @param array<string, mixed> $entity */
    public static function applied(string $entityType, array $entity, ?int $entityId = null): self
    {
        return new self(200, ['status' => 'applied', 'entity_type' => $entityType, 'entity' => $entity], $entityType, $entityId);
    }

    /**
     * Someone changed a field meanwhile: nothing of it was written. Fields that didn't conflict
     * were applied (`applied`), so "keep theirs" never needs to undo anything.
     *
     * @param  array<string, mixed>  $entity  the current server state
     * @param  array<string, array{base: mixed, mine: mixed, theirs: mixed}>  $conflicts
     * @param  list<string>  $applied
     */
    public static function conflict(string $entityType, array $entity, int $entityId, array $conflicts, array $applied): self
    {
        return new self(409, [
            'status' => 'conflict',
            'entity_type' => $entityType,
            'entity' => $entity,
            'conflicts' => $conflicts,
            'applied' => $applied,
            'message' => 'Someone changed this meanwhile.',
        ], $entityType, $entityId);
    }

    /** The thing was deleted (or is no longer visible to this user — the same answer, so nothing leaks). */
    public static function deleted(string $entityType, string $message): self
    {
        return new self(410, ['status' => 'deleted', 'entity_type' => $entityType, 'message' => $message]);
    }

    /** Refers to something created offline that hasn't reached the server yet: retry later. */
    public static function waiting(string $message): self
    {
        return new self(424, ['status' => 'waiting', 'message' => $message]);
    }

    /** A stored outcome, answered again for a retried mutation. @param array<string, mixed> $body */
    public static function replayed(int $status, array $body): self
    {
        return new self($status, $body);
    }

    public function isFinal(): bool
    {
        return $this->status === 200 || $this->status === 409;
    }
}
