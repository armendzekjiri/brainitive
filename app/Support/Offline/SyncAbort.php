<?php

namespace App\Support\Offline;

use RuntimeException;

/** Ends a mutation early with a non-final outcome (deleted / waiting); the transaction rolls back. */
final class SyncAbort extends RuntimeException
{
    public function __construct(public readonly SyncOutcome $outcome)
    {
        parent::__construct((string) ($outcome->body['message'] ?? 'Sync aborted.'));
    }
}
