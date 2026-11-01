<?php

namespace App\Console\Commands;

use App\Support\AppVersion;
use App\Support\Updates\UpdateCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * BRAI-433: is a newer Brainitive release out? Runs daily from the scheduler; run it by hand to check
 * now. Only tells — upgrading stays a manual step (docs: self-hosting/upgrading).
 */
class CheckForUpdate extends Command
{
    protected $signature = 'brainitive:check-update
        {--clear : Forget the stored result (the notice disappears until the next check)}
        {--fake-latest= : e2e only: record this version as the latest release without calling GitHub}';

    protected $description = 'Check GitHub for a newer Brainitive release and tell instance admins about it';

    public function handle(): int
    {
        if ($this->option('clear')) {
            UpdateCheck::clear();
            $this->info('Forgot the stored update check result.');

            return self::SUCCESS;
        }

        if (($fake = $this->option('fake-latest')) !== null) {
            if (! app()->environment('e2e')) {
                $this->error('--fake-latest only works on the e2e server (APP_ENV=e2e).');

                return self::FAILURE;
            }
            if (AppVersion::normalize($fake) === null) {
                $this->error("Not a version: {$fake}");

                return self::FAILURE;
            }
            UpdateCheck::record($fake, null, now()->toIso8601String());
        } else {
            $result = UpdateCheck::run();

            if ($result['status'] === 'disabled') {
                $this->warn('The update check is off (BRAINITIVE_UPDATE_CHECK=false, or this is Brainitive Cloud). Nothing was sent.');

                return self::SUCCESS;
            }

            if ($result['status'] === 'failed') {
                $this->warn('Could not check for updates: '.($result['reason'] ?? 'unknown reason').'. The last result is kept.');

                return self::FAILURE;
            }
        }

        $status = UpdateCheck::status();
        $current = $status['current'] ? 'v'.$status['current'] : 'an unknown version';
        $this->line("Running Brainitive {$current}.");

        if (! $status['latest']) {
            $this->line('No release information yet.');

            return self::SUCCESS;
        }

        $published = $status['published_at'] ? ' (published '.Carbon::parse($status['published_at'])->toDateString().')' : '';
        $this->line("Latest release: v{$status['latest']}{$published}.");

        if ($status['update_available']) {
            $this->info("Brainitive v{$status['latest']} is available.");
            $this->line('Release notes: '.$status['release_url']);
            $this->line('How to upgrade: '.UpdateCheck::UPGRADE_DOCS_URL);
        } else {
            $this->info('You are up to date.');
        }

        return self::SUCCESS;
    }
}
