<?php

namespace App\Support\Offline;

use App\Models\OfflineMutation;
use App\Models\User;
use App\Support\Offline\Appliers\CaptureMutations;
use App\Support\Offline\Appliers\IssueMutations;
use App\Support\Offline\Appliers\ListTaskMutations;
use App\Support\Offline\Appliers\MyDayMutations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies one mutation from a device's offline outbox (BRAI-82), exactly once.
 *
 * - Idempotent per (user, client uuid): the outcome row is inserted first, inside the
 *   transaction, so a concurrent retry waits on the unique index and then gets the stored answer;
 *   the same uuid with different content is refused.
 * - Only final outcomes are stored (applied, conflict). Deleted / waiting / forbidden / invalid
 *   wrote nothing (the transaction rolls back), so they can simply be checked again.
 * - The device sends its outbox in order, one mutation per request.
 */
final class OfflineSync
{
    /** type => [applier, method] */
    public const TYPES = [
        'list_task.create' => [ListTaskMutations::class, 'create'],
        'list_task.update' => [ListTaskMutations::class, 'update'],
        'list_task.reorder' => [ListTaskMutations::class, 'reorder'],
        'issue.update' => [IssueMutations::class, 'update'],
        'issue.comment' => [IssueMutations::class, 'comment'],
        'issue.create' => [IssueMutations::class, 'create'],
        'capture.create' => [CaptureMutations::class, 'create'],
        'my_day.add' => [MyDayMutations::class, 'add'],
        'my_day.remove' => [MyDayMutations::class, 'remove'],
    ];

    /**
     * @param  array{id: string, type: string, target?: array<string, mixed>|null, base?: array<string, mixed>|null, data: array<string, mixed>}  $mutation
     */
    public static function handle(User $user, array $mutation): SyncOutcome
    {
        $hash = self::hash($mutation);
        $key = ['user_id' => $user->id, 'client_id' => $mutation['id']];

        if ($existing = OfflineMutation::where($key)->first()) {
            return self::replay($existing, $hash);
        }

        $ctx = new MutationContext(
            user: $user,
            clientId: $mutation['id'],
            type: $mutation['type'],
            target: (array) ($mutation['target'] ?? []),
            baseFields: (array) ($mutation['base']['fields'] ?? []),
            data: (array) $mutation['data'],
        );

        try {
            return DB::transaction(function () use ($ctx, $key, $hash) {
                $record = OfflineMutation::create($key + ['type' => $ctx->type, 'payload_hash' => $hash, 'status' => 0, 'response' => []]);

                [$class, $method] = self::TYPES[$ctx->type];
                $outcome = $class::$method($ctx);
                if (! $outcome->isFinal()) {
                    throw new SyncAbort($outcome);
                }

                $record->update([
                    'status' => $outcome->status,
                    'entity_type' => $outcome->entityType,
                    'entity_id' => $outcome->entityId,
                    'response' => $outcome->body,
                ]);

                return $outcome;
            });
        } catch (SyncAbort $abort) {
            return $abort->outcome;
        } catch (UniqueConstraintViolationException) {
            // A concurrent retry of the same mutation won the race; answer with its outcome.
            return self::replay(OfflineMutation::where($key)->firstOrFail(), $hash);
        }
    }

    private static function replay(OfflineMutation $existing, string $hash): SyncOutcome
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            throw ValidationException::withMessages(['id' => 'This change id was already used for a different change.']);
        }

        return SyncOutcome::replayed($existing->status, $existing->response + ['replayed' => true]);
    }

    /** @param array<string, mixed> $mutation */
    private static function hash(array $mutation): string
    {
        $payload = [
            'type' => $mutation['type'],
            'target' => $mutation['target'] ?? null,
            'base' => $mutation['base'] ?? null,
            'data' => $mutation['data'],
        ];

        return hash('sha256', (string) json_encode(self::sorted($payload)));
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map([self::class, 'sorted'], $value);
    }
}
