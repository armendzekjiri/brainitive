<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per action taken in the instance admin console. Stores labels (name / email)
 * so the log stays readable after the actor or target is deleted.
 */
class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_name',
        'action',
        'target_type',
        'target_id',
        'target_label',
        'meta',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(string $action, User|Workspace|null $target = null, array $meta = []): self
    {
        $actor = request()->user();

        return static::create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor ? "{$actor->name} <{$actor->email}>" : 'system',
            'action' => $action,
            'target_type' => match (true) {
                $target instanceof User => 'user',
                $target instanceof Workspace => 'workspace',
                default => null,
            },
            'target_id' => $target?->id,
            'target_label' => match (true) {
                $target instanceof User => "{$target->name} <{$target->email}>",
                $target instanceof Workspace => $target->name,
                default => null,
            },
            'meta' => $meta ?: null,
            'ip_address' => request()->ip(),
        ]);
    }
}
