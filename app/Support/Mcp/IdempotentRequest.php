<?php

namespace App\Support\Mcp;

use App\Models\McpRequest;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Run an MCP write once per (user, tool, request_id). A retry with the same parameters
 * returns the stored result (`replayed`); the same request_id with different parameters is
 * rejected, never applied.
 */
class IdempotentRequest
{
    /**
     * @param  array<string, mixed>  $payload  the parameters that define the write
     * @param  Closure(): array<string, mixed>  $write  runs inside a transaction; returns the tool result
     * @return array{result: array<string, mixed>, replayed: bool}
     *
     * @throws RequestIdConflict
     */
    public static function run(User $user, string $tool, string $requestId, array $payload, Closure $write): array
    {
        $hash = hash('sha256', json_encode(self::sorted($payload)));
        $key = ['user_id' => $user->id, 'tool' => $tool, 'request_id' => $requestId];

        if ($existing = McpRequest::where($key)->first()) {
            return self::replay($existing, $hash);
        }

        try {
            $result = DB::transaction(function () use ($key, $hash, $write) {
                $result = $write();
                McpRequest::create($key + ['payload_hash' => $hash, 'result' => $result]);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent retry won the race; its write committed, ours rolled back.
            return self::replay(McpRequest::where($key)->firstOrFail(), $hash);
        }

        return ['result' => $result, 'replayed' => false];
    }

    private static function replay(McpRequest $existing, string $hash): array
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            throw new RequestIdConflict('This request_id was already used for a different request. Use a new request_id for a new write.');
        }

        return ['result' => $existing->result, 'replayed' => true];
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
