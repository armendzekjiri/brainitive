<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Page;
use App\Models\PollVote;
use App\Support\Polls\PollDocuments;
use Illuminate\Console\Command;

/**
 * Codex web review E (v1.49.0): before the fix a viewer could vote in made-up polls (any poll id, any option).
 * Votes are now only taken for polls and options of the saved doc / issue (PollDocuments); this removes the rows
 * that don't match one: made-up polls, removed options, polls whose doc / issue is gone. A page's draft counts
 * too, so polls an editor is still working on keep their votes.
 *
 * Dry run by default (reports); `--force` deletes them. Safe to run again (e.g. monthly).
 */
class PruneOrphanPollVotes extends Command
{
    protected $signature = 'polls:prune-orphans {--force : delete them (without it: only report)}';

    protected $description = 'Report (or with --force remove) poll votes whose poll or option is not in the saved doc / issue';

    public function handle(): int
    {
        $orphans = [];
        $scopes = PollVote::query()->select('scope_type', 'scope_id')->distinct()->get();
        foreach ($scopes as $scope) {
            $model = match ($scope->scope_type) {
                'page' => Page::query()->where('uuid', $scope->scope_id)->first(),
                'item' => ctype_digit((string) $scope->scope_id) ? Item::withTrashed()->find((int) $scope->scope_id) : null,
                default => null,
            };
            $polls = $model ? $this->polls($model) : [];

            PollVote::where(['scope_type' => $scope->scope_type, 'scope_id' => $scope->scope_id])
                ->get(['id', 'poll_id', 'option_uid'])
                ->each(function (PollVote $vote) use ($polls, &$orphans) {
                    if (! in_array($vote->option_uid, $polls[$vote->poll_id] ?? [], true)) {
                        $orphans[] = $vote->id;
                    }
                });
        }

        if ($orphans === []) {
            $this->info('No orphaned poll votes.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn(count($orphans).' orphaned poll vote(s). Dry run: nothing changed. Run again with --force to remove them.');

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach (array_chunk($orphans, 1000) as $chunk) {
            $deleted += PollVote::whereKey($chunk)->delete();
        }
        $this->info("Removed {$deleted} orphaned poll vote(s).");

        return self::SUCCESS;
    }

    /** @return array<string, list<string>> every poll of the saved document, a page's draft included */
    private function polls(Page|Item $model): array
    {
        if ($model instanceof Item) {
            return PollDocuments::parse($model->description);
        }

        return array_merge_recursive(PollDocuments::parse($model->content), PollDocuments::parse($model->draft_content));
    }
}
