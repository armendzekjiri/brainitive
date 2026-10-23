<?php

namespace App\Console\Commands;

use Database\Seeders\E2eSeeder;
use Illuminate\Console\Command;

/**
 * Wipe and reseed the database for the Playwright suite (BRAI-96). Destructive, so it refuses
 * unless APP_ENV=e2e AND the database is clearly an e2e one (an SQLite file under storage/e2e,
 * or a database whose name contains "e2e" / is "testing") — never a local devdb or production.
 */
class E2eReset extends Command
{
    protected $signature = 'e2e:reset';

    protected $description = 'Reset the e2e database: migrate:fresh + E2eSeeder (APP_ENV=e2e only)';

    public function handle(): int
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! self::isE2eDatabase(app()->environment(), $connection, $database)) {
            $this->error("Refusing to reset {$connection}:{$database} (APP_ENV=".app()->environment().'). Needs APP_ENV=e2e and an e2e database.');

            return self::FAILURE;
        }

        if ($connection === 'sqlite' && ! file_exists($database)) {
            @mkdir(dirname($database), 0755, true);
            touch($database);
        }

        // quiet: only the "ready" line (or the error) shows before the tests start
        $exit = $this->callSilently('migrate:fresh', ['--force' => true, '--seed' => true, '--seeder' => E2eSeeder::class]);
        if ($exit !== self::SUCCESS) {
            $this->error('migrate:fresh failed — run it with -v to see why.');

            return self::FAILURE;
        }
        $this->info("e2e database ready: {$connection}:{$database}");

        return self::SUCCESS;
    }

    public static function isE2eDatabase(string $environment, string $connection, string $database): bool
    {
        if ($environment !== 'e2e') {
            return false;
        }
        if ($connection === 'sqlite') {
            return str_contains(str_replace('\\', '/', $database), '/storage/e2e/');
        }

        return str_contains(strtolower($database), 'e2e') || $database === 'testing';
    }
}
