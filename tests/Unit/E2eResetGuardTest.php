<?php

namespace Tests\Unit;

use App\Console\Commands\E2eReset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * e2e:reset wipes the database (BRAI-96): it must only ever accept an e2e database.
 */
class E2eResetGuardTest extends TestCase
{
    /** @return array<string, array{string, string, string, bool}> */
    public static function cases(): array
    {
        return [
            'e2e sqlite file' => ['e2e', 'sqlite', '/repo/storage/e2e/e2e.sqlite', true],
            'ci postgres' => ['e2e', 'pgsql', 'testing', true],
            'named e2e postgres' => ['e2e', 'pgsql', 'brainitive_e2e', true],
            'local devdb' => ['e2e', 'pgsql', 'devdb', false],
            'production db' => ['e2e', 'pgsql', 'brainitive', false],
            'dev server db' => ['e2e', 'pgsql', 'brainitive_dev', false],
            'other sqlite file' => ['e2e', 'sqlite', '/repo/database/database.sqlite', false],
            'wrong env' => ['local', 'sqlite', '/repo/storage/e2e/e2e.sqlite', false],
            'production env' => ['production', 'pgsql', 'testing', false],
        ];
    }

    #[DataProvider('cases')]
    public function test_only_e2e_databases_can_be_reset(string $env, string $connection, string $database, bool $allowed): void
    {
        $this->assertSame($allowed, E2eReset::isE2eDatabase($env, $connection, $database));
    }
}
