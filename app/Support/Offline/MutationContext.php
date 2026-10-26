<?php

namespace App\Support\Offline;

use App\Models\OfflineMutation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * One offline mutation as the appliers see it (BRAI-82): who sent it, what it targets (a server
 * id, or `ref` = the uuid of an earlier offline create), the base values it was made against and
 * the new data.
 */
final class MutationContext
{
    /**
     * @param  array{id?: int|null, ref?: string|null}  $target
     * @param  array<string, mixed>  $baseFields
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly User $user,
        public readonly string $clientId,
        public readonly string $type,
        public readonly array $target,
        public readonly array $baseFields,
        public readonly array $data,
    ) {}

    /** The server id of the target: given, or created earlier by an offline mutation of this user. */
    public function targetId(string $entityType): int
    {
        if (! empty($this->target['id'])) {
            return (int) $this->target['id'];
        }
        if (! empty($this->target['ref'])) {
            return $this->resolveRef((string) $this->target['ref'], $entityType);
        }

        throw ValidationException::withMessages(['target' => 'This change needs a target.']);
    }

    /** Resolve `ref` (the uuid of an earlier offline create of the same user) to its server id. */
    public function resolveRef(string $ref, string $entityType): int
    {
        $created = OfflineMutation::where('user_id', $this->user->id)->where('client_id', $ref)->first();

        if (! $created) {
            throw new SyncAbort(SyncOutcome::waiting('Waiting for an earlier offline change to arrive first.'));
        }
        if ($created->status !== 200 || $created->entity_type !== $entityType || ! $created->entity_id) {
            throw new SyncAbort(SyncOutcome::deleted($entityType, 'The item this change was made on was never created.'));
        }

        return $created->entity_id;
    }

    /**
     * Resolve several refs in one query (reorder); every ref must exist, as in resolveRef().
     *
     * @param  list<string>  $refs
     * @return array<string, int>
     */
    public function resolveRefs(array $refs, string $entityType): array
    {
        if ($refs === []) {
            return [];
        }
        $rows = OfflineMutation::where('user_id', $this->user->id)->whereIn('client_id', $refs)->get()->keyBy('client_id');
        $ids = [];
        foreach ($refs as $ref) {
            $created = $rows->get($ref);
            if (! $created) {
                throw new SyncAbort(SyncOutcome::waiting('Waiting for an earlier offline change to arrive first.'));
            }
            if ($created->status !== 200 || $created->entity_type !== $entityType || ! $created->entity_id) {
                throw new SyncAbort(SyncOutcome::deleted($entityType, 'The item this change was made on was never created.'));
            }
            $ids[$ref] = $created->entity_id;
        }

        return $ids;
    }

    /**
     * Validate the data of this mutation; only the validated keys come back.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function validate(array $rules): array
    {
        return Validator::make($this->data, $rules)->validate();
    }

    /**
     * Base values for every changed field are required: without them there is no conflict check.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function baseFor(array $changes): array
    {
        if ($changes === []) {
            throw ValidationException::withMessages(['data' => 'Nothing to change.']);
        }
        $missing = array_diff(array_keys($changes), array_keys($this->baseFields));
        if ($missing !== []) {
            throw ValidationException::withMessages(['base.fields' => 'Missing the original value of: '.implode(', ', $missing).'.']);
        }

        return array_intersect_key($this->baseFields, $changes);
    }
}
