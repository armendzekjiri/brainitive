<?php

namespace App\Support\MeetingNotes;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns the model's raw candidates into proposals a person can trust (BRAI-62). Deterministic:
 * - evidence: each proposal must quote the notes; an excerpt that isn't in the notes is dropped
 *   (the model invented it) and counted, never shown as a task;
 * - assignees only ever resolve to people who can open the project; several matches are
 *   `ambiguous` and need a choice, no match stays unassigned;
 * - due dates only when the deadline phrase is in the notes and the date is safe to trust
 *   (a meeting date is known, or the phrase is a full calendar date);
 * - duplicates (within the batch, or an open issue on the destination board) are flagged and
 *   unselected, never removed.
 */
class ProposalReview
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  Collection<int, User>  $members  people who can open the project
     * @return array{proposals: list<array<string, mixed>>, dropped: int, truncated: bool}
     */
    public static function review(array $candidates, string $sourceText, Collection $members, Workspace $workspace, Project $project, Board $board, ?CarbonImmutable $meetingDate): array
    {
        $source = self::normalize($sourceText);
        $lines = self::lines($sourceText);
        $existing = self::openIssuesByTitle($board);

        $proposals = [];
        $dropped = 0;
        $truncated = false;
        $seenTitles = [];

        foreach ($candidates as $candidate) {
            $title = self::text($candidate['title'] ?? null, 200);
            $excerpt = self::text($candidate['excerpt'] ?? null, 500);
            $normalizedExcerpt = self::normalize(preg_replace('/^[\s>*•-]+/u', '', $excerpt) ?? $excerpt);

            if ($title === '' || ! self::quotes($normalizedExcerpt, $source, $lines)) {
                $dropped++;

                continue;
            }
            if (count($proposals) >= ActionItemExtractor::MAX_PROPOSALS) {
                $truncated = true;
                break;
            }

            $tempId = 'p'.(count($proposals) + 1);
            $titleKey = self::titleKey($title);
            // one sentence can hold two actions: only the same title makes a duplicate
            $duplicateOf = $seenTitles[$titleKey] ?? null;
            $match = $existing->get($titleKey);

            $proposals[] = [
                'temp_id' => $tempId,
                'title' => $title,
                'description' => self::text($candidate['description'] ?? null, 2000),
                'excerpt' => $excerpt,
                'assignee' => self::assignee($candidate['assignee'] ?? null, $members, $source),
                'due' => self::due($candidate['due_text'] ?? null, $candidate['due_date'] ?? null, $source, $meetingDate),
                'duplicate_of' => $duplicateOf,
                'existing' => $match ? [
                    'id' => $match->id,
                    'key' => $match->key,
                    'title' => $match->title,
                    'url' => route('boards.items.show', [$workspace->slug, $project->slug, $board->slug, $match->id]),
                ] : null,
                'selected' => $duplicateOf === null && $match === null,
            ];

            $seenTitles[$titleKey] ??= $tempId;
        }

        return ['proposals' => $proposals, 'dropped' => $dropped, 'truncated' => $truncated];
    }

    /**
     * @param  Collection<int, User>  $members
     * @return array{mention: string, status: 'matched'|'ambiguous'|'unknown', user_id: int|null, candidates: list<array{id: int, name: string}>}|null
     */
    public static function assignee(mixed $mention, Collection $members, string $normalizedSource): ?array
    {
        $mention = self::text($mention, 120);
        $words = self::nameWords($mention);
        // an owner the notes never name was invented (or injected): no suggestion at all
        if ($words === [] || ! str_contains($normalizedSource, self::normalize(ltrim($mention, '@')))) {
            return null;
        }

        $exact = $members->filter(fn (User $m) => self::nameWords($m->name) === $words);
        $matches = $exact->isNotEmpty()
            ? $exact
            : $members->filter(fn (User $m) => array_diff($words, self::nameWords($m->name)) === []);

        $candidates = $matches->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name])->values()->all();
        $status = match (count($candidates)) {
            0 => 'unknown',
            1 => 'matched',
            default => 'ambiguous',
        };

        return [
            'mention' => $mention,
            'status' => $status,
            'user_id' => $status === 'matched' ? $candidates[0]['id'] : null,
            'candidates' => $status === 'ambiguous' ? $candidates : [],
        ];
    }

    /**
     * @return array{text: string, date: string|null, status: 'set'|'needs_meeting_date'|'unclear'}|null
     */
    public static function due(mixed $dueText, mixed $dueDate, string $normalizedSource, ?CarbonImmutable $meetingDate): ?array
    {
        $text = self::text($dueText, 120);
        // the deadline must be in the notes, or there is no deadline
        if ($text === '' || ! str_contains($normalizedSource, self::normalize($text))) {
            return null;
        }

        $date = is_string($dueDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) ? CarbonImmutable::createFromFormat('!Y-m-d', $dueDate) : null;
        if (! $date || $date->format('Y-m-d') !== $dueDate) {
            return ['text' => $text, 'date' => null, 'status' => $meetingDate ? 'unclear' : 'needs_meeting_date'];
        }

        if ($meetingDate) {
            $plausible = $date->betweenIncluded($meetingDate->subDays(31), $meetingDate->addYears(2));

            return ['text' => $text, 'date' => $plausible ? $dueDate : null, 'status' => $plausible ? 'set' : 'unclear'];
        }

        return self::fullDate($text, $date)
            ? ['text' => $text, 'date' => $dueDate, 'status' => 'set']
            : ['text' => $text, 'date' => null, 'status' => 'needs_meeting_date'];
    }

    /**
     * Without a meeting date only a deadline written as a full, unambiguous calendar date is
     * trusted: year, day and month must all be in the phrase ("14 November 2026", "2026-11-14").
     * A numeric "3/4/2026" could be March or April, so it isn't.
     */
    private static function fullDate(string $text, CarbonImmutable $date): bool
    {
        $lower = mb_strtolower($text);
        if (str_contains($lower, $date->format('Y-m-d'))) {
            return true;
        }
        if (! str_contains($lower, $date->format('Y'))) {
            return false;
        }
        $rest = str_replace($date->format('Y'), ' ', $lower);
        $number = fn (int $n) => (bool) preg_match('/(?<!\d)0?'.$n.'(?!\d)/', $rest);
        if (! $number($date->day)) {
            return false;
        }
        if (str_contains($rest, mb_strtolower($date->format('M')))) {
            return true; // "Nov", "November"
        }

        // numeric month: only when day and month can't be swapped
        return $number($date->month) && ($date->day > 12 || $date->day === $date->month);
    }

    /**
     * Real evidence: a fragment of at least three words / 12 characters of the notes, or a whole
     * (short) line of them ("Fix CI"). A common word like "the" is found anywhere and proves nothing.
     *
     * @param  array<string, true>  $lines
     */
    private static function quotes(string $excerpt, string $source, array $lines): bool
    {
        if ($excerpt === '' || ! str_contains($source, $excerpt)) {
            return false;
        }
        $words = count(preg_split('/\s+/u', $excerpt, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return ($words >= 3 && mb_strlen($excerpt) >= 12) || isset($lines[rtrim($excerpt, '.!;:')]);
    }

    /** @return array<string, true> normalized source lines without list markers / trailing punctuation */
    private static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            $line = rtrim(self::normalize(preg_replace('/^[\s>*•#-]+|^\s*\d+[.)]\s+/u', '', $line) ?? $line), '.!;:');
            if (mb_strlen($line) >= 3) {
                $lines[$line] = true;
            }
        }

        return $lines;
    }

    /** Lower-case, typographic quotes straightened, Markdown emphasis removed, whitespace collapsed. */
    public static function normalize(string $text): string
    {
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text; // [text](url) → text
        $text = strtr($text, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '*' => '', '_' => '', '`' => '', '~' => '']);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(mb_strtolower($text));
    }

    private static function titleKey(string $title): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)) ?? '');
    }

    /** @return list<string> */
    private static function nameWords(string $name): array
    {
        $name = mb_strtolower(ltrim(trim($name), '@'));
        $words = preg_split('/[^\p{L}\p{N}\'-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($words);

        return $words;
    }

    private static function text(mixed $value, int $max): string
    {
        if (! is_string($value) && ! is_int($value)) {
            return '';
        }
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '');

        return Str::limit($value, $max, '…');
    }

    /** @return Collection<string, Item> open issues of the board by normalized title */
    private static function openIssuesByTitle(Board $board): Collection
    {
        return Item::query()
            ->where('board_id', $board->id)
            ->whereDoesntHave('column', fn ($q) => $q->where('is_done_column', true))
            ->latest('id')
            ->limit(2000)
            ->get(['id', 'key', 'title'])
            ->keyBy(fn (Item $item) => self::titleKey($item->title))
            ->forget('');
    }
}
