<?php

namespace App\Support\Imports\Jira;

use App\Models\Item;
use App\Support\Imports\ImportFailed;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A Jira "Export issues → CSV (all fields)" file, read into plain issue arrays (v1.41.0, BRAI-139).
 * Used by both preview() and run(), so the dry run validates exactly what the run will use.
 *
 * Columns are looked up by name, case-insensitively; repeated columns (Labels, Comment, Sprint,
 * Watchers, Attachment, …) are read positionally. Known columns become issue fields; every other
 * "Custom field (…)" column and the content Brainitive doesn't import are counted in unsupported().
 */
final class JiraExport
{
    public const MAX_ISSUES = 10_000;

    public const MAX_BYTES = 25 * 1024 * 1024; // the preview runs in the web request

    private const MAX_TEXT = 100_000;

    /** Custom fields that map to issue fields (or are noise), so they don't count as unsupported. */
    private const KNOWN_CUSTOM_FIELDS = [
        'epic link', 'epic name', 'epic status', 'epic colour', 'epic color', 'parent link', 'story points',
        'story point estimate', 'start date', 'rank', 'sprint', 'development', 'issue color', 'vulnerability',
    ];

    /** Issue link column name (Outward issue link (X)) → Brainitive link type. Others relate. */
    private const LINK_TYPES = [
        'blocks' => 'blocks',
        'blocker' => 'blocks',
        'duplicate' => 'duplicates',
        'duplicates' => 'duplicates',
        'cloners' => 'clones',
        'clones' => 'clones',
        'relates' => 'relates_to',
        'relate' => 'relates_to',
    ];

    private const DATE_FORMATS = [
        'd/M/y g:i A', 'd/M/y h:i A', 'd/M/Y g:i A', 'd/M/y H:i', 'd/M/Y H:i', 'd/M/y',
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.vO', 'Y-m-d',
        'd.m.Y H:i', 'd.m.y H:i', 'd.m.Y', 'm/d/Y g:i A', 'm/d/Y H:i', 'm/d/Y', 'd/m/Y H:i', 'd/m/Y',
    ];

    /** @var list<array<string, mixed>> */
    public array $issues = [];

    /** @var array<string, string> Jira account id / user name → display name (mentions, comment authors) */
    public array $people = [];

    /** @var array<string, int> */
    public array $unsupported = [];

    /** @var list<string> */
    public array $warnings = [];

    public string $delimiter = ',';

    /** @var array<string, list<int>> */
    private array $columns = [];

    private int $unreadableDates = 0;

    /** @throws ImportFailed */
    public static function read(string $path): self
    {
        $export = new self;
        $export->parse(new CsvReader($path, self::MAX_BYTES));

        return $export;
    }

    private function parse(CsvReader $csv): void
    {
        $this->delimiter = $csv->delimiter();
        foreach ($csv->headers() as $position => $header) {
            $this->columns[mb_strtolower($header)][] = $position;
        }
        if (! isset($this->columns['summary']) || (! isset($this->columns['issue key']) && ! isset($this->columns['issue id']))) {
            throw new ImportFailed('This doesn’t look like a Jira CSV export: it needs the columns “Summary” and “Issue key”. In Jira, use Export → Export CSV (all fields), with Jira set to English.');
        }
        $customColumns = $this->customFieldColumns();

        $rows = [];
        foreach ($csv->rows(self::MAX_ISSUES) as $number => $row) {
            $rows[$number] = $row;
            foreach (['assignee', 'reporter', 'creator'] as $role) {
                $name = $this->first($row, $role);
                $id = $this->first($row, $role.' id');
                if ($name !== '' && $id !== '') {
                    $this->people[$id] = $name;
                }
            }
        }
        if ($rows === []) {
            throw new ImportFailed('The file has a header row but no issues.');
        }

        $seen = [];
        foreach ($rows as $number => $row) {
            $issue = $this->issue($row, $number);
            if (isset($seen[$issue['key']])) {
                $this->warn("{$issue['key']} appears twice in the file; only the first row is imported.");

                continue;
            }
            $seen[$issue['key']] = true;
            $this->issues[] = $issue;

            foreach ($customColumns as $position) {
                if (trim($row[$position]) !== '') {
                    $this->count('Custom fields');
                }
            }
            foreach (['Attachments' => 'attachment', 'Watchers' => 'watchers', 'Work log entries' => 'log work', 'Components' => 'component/s'] as $label => $column) {
                $this->count($label, count($this->all($row, $column)));
            }
            $this->count('Versions (fix / affects)', count($this->all($row, 'fix version/s')) + count($this->all($row, 'affects version/s')));
        }

        if ($csv->recoded()) {
            $this->warn('The file isn’t UTF-8; it was read as Windows-1252. Check special characters after the import.');
        }
        if ($this->unreadableDates > 0) {
            $this->warn("{$this->unreadableDates} dates couldn’t be read and were left empty.");
        }
    }

    /** @param list<string> $row */
    private function issue(array $row, int $number): array
    {
        $key = $this->first($row, 'issue key') ?: ($this->first($row, 'issue id') !== '' ? '#'.$this->first($row, 'issue id') : '');
        if ($key === '') {
            throw new ImportFailed("Row {$number} has no issue key.");
        }
        $summary = trim(preg_replace('/\s+/u', ' ', $this->first($row, 'summary')) ?? '');
        if ($summary === '') {
            $this->warn("{$key} has no summary; it is imported as “(no summary)”.");
            $summary = '(no summary)';
        }

        $created = $this->date($this->first($row, 'created'));
        $resolved = $this->date($this->first($row, 'resolved'));

        $links = [];
        foreach ($this->columns as $name => $positions) {
            if (preg_match('/^outward issue link \((.+)\)$/', $name, $m)) {
                $type = self::LINK_TYPES[mb_strtolower(trim($m[1]))] ?? 'relates_to';
                foreach ($positions as $position) {
                    foreach (preg_split('/[\s,]+/', trim($row[$position])) ?: [] as $target) {
                        if ($target !== '') {
                            $links[] = ['type' => $type, 'to' => $target];
                        }
                    }
                }
            }
        }

        $comments = [];
        foreach ($this->all($row, 'comment') as $raw) {
            $comments[] = $this->comment($raw);
        }

        return [
            'key' => $key,
            'id' => $this->first($row, 'issue id'),
            'row' => $number,
            'summary' => mb_substr($summary, 0, 255),
            'type' => $this->first($row, 'issue type') ?: 'Task',
            'status' => $this->first($row, 'status') ?: 'To Do',
            'category' => $this->category($this->first($row, 'status category'), $this->first($row, 'status'), $this->first($row, 'resolution')),
            'priority' => $this->first($row, 'priority'),
            'project' => ['key' => $this->first($row, 'project key'), 'name' => $this->first($row, 'project name')],
            'assignee' => $this->first($row, 'assignee') ?: null,
            'reporter' => $this->first($row, 'reporter') ?: ($this->first($row, 'creator') ?: null),
            'created' => $created?->toDateString(),
            'resolved' => $resolved?->toDateTimeString(),
            'due' => $this->date($this->first($row, 'due date'))?->toDateString(),
            'start' => $this->date($this->first($row, 'custom field (start date)'))?->toDateString(),
            'labels' => array_values(array_unique(array_merge(...array_map(fn ($cell) => preg_split('/\s+/', $cell) ?: [], $this->all($row, 'labels'))))),
            'description' => $this->text($this->first($row, 'description'), $key),
            'environment' => $this->text($this->first($row, 'environment'), $key),
            'story_points' => $this->points($this->first($row, 'custom field (story points)') ?: $this->first($row, 'custom field (story point estimate)')),
            'estimate' => $this->estimate($this->first($row, 'original estimate')),
            'sprints' => $this->all($row, 'sprint'),
            'comments' => $comments,
            // sub-tasks: "Parent id" (Server, older Cloud) / "Parent" (Cloud); epics: "Epic Link" (a key)
            'parent' => $this->first($row, 'parent id') ?: ($this->first($row, 'parent') ?: ($this->first($row, 'parent key') ?: ($this->first($row, 'custom field (parent link)') ?: null))),
            'epic' => $this->first($row, 'custom field (epic link)') ?: ($this->first($row, 'epic link') ?: null),
            'links' => $links,
        ];
    }

    /** "17/Jan/24 10:15 AM;557058:abc…;Text" → date, author, body (Cloud and Server write it like this). */
    private function comment(string $raw): array
    {
        $parts = explode(';', $raw, 3);
        if (count($parts) === 3 && ($date = $this->date(trim($parts[0]), count: false)) !== null) {
            $author = trim($parts[1]);

            return [
                'date' => $date->toDateString(),
                'author' => $this->people[$author] ?? ($this->looksLikeAccountId($author) ? null : ($author ?: null)),
                'body' => $this->text($parts[2], 'a comment'),
            ];
        }

        return ['date' => null, 'author' => null, 'body' => $this->text($raw, 'a comment')];
    }

    private function looksLikeAccountId(string $value): bool
    {
        return (bool) preg_match('/^(?:\d+:)?[0-9a-f]{8}-[0-9a-f-]{20,}$|^[0-9a-f]{24}$|^\d{6}:/i', $value);
    }

    /** todo | in_progress | done: the Status Category column, else the resolution, else the name. */
    private function category(string $category, string $status, string $resolution): string
    {
        $category = mb_strtolower(trim($category));
        if ($category !== '') {
            return match (true) {
                (bool) preg_match('/done|complete|erledigt|fertig/', $category) => 'done',
                (bool) preg_match('/to ?do|new|open|offen|backlog/', $category) => 'todo',
                default => 'in_progress',
            };
        }
        if ($resolution !== '' && ! in_array(mb_strtolower($resolution), ['unresolved', 'none', 'nicht erledigt'], true)) {
            return 'done';
        }

        return match (true) {
            (bool) preg_match('/\b(done|closed|resolved|complete[d]?|released|fertig|erledigt|geschlossen)\b/i', $status) => 'done',
            (bool) preg_match('/\b(to ?do|open|new|backlog|selected|offen|neu)\b/i', $status) => 'todo',
            default => 'in_progress',
        };
    }

    private function text(string $text, string $where): string
    {
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_TEXT) {
            $this->warn("The text of {$where} is longer than 100 000 characters and was shortened.");
            $text = mb_substr($text, 0, self::MAX_TEXT);
        }

        return $text;
    }

    private function points(string $value): ?int
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) && (float) $value >= 0 ? (int) min(1_000_000, round((float) $value)) : null;
    }

    /** Jira writes estimates in seconds; Brainitive stores minutes. "2h" style values are parsed too. */
    private function estimate(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            $minutes = intdiv((int) $value, 60);

            return $minutes > 0 ? min($minutes, 1_000_000) : null;
        }

        return Item::parseDuration($value);
    }

    public function date(string $value, bool $count = true): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (self::DATE_FORMATS as $format) {
            $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            $errors = CarbonImmutable::getLastErrors();
            if ($date !== false && $date !== null && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->year >= 1970 && $date->year < 2200) {
                return $date;
            }
        }
        try {
            $date = CarbonImmutable::parse($value);
            if ($date->year >= 1970 && $date->year < 2200) {
                return $date;
            }
        } catch (Throwable) {
            // unreadable
        }
        if ($count) {
            $this->unreadableDates++;
        }

        return null;
    }

    /** @return list<int> positions of "Custom field (…)" columns that aren't mapped to issue fields */
    private function customFieldColumns(): array
    {
        $positions = [];
        foreach ($this->columns as $name => $columnPositions) {
            if (preg_match('/^custom field \((.+)\)$/', $name, $m) && ! in_array(trim($m[1]), self::KNOWN_CUSTOM_FIELDS, true)) {
                array_push($positions, ...$columnPositions);
            }
        }

        return $positions;
    }

    /** @param list<string> $row */
    private function first(array $row, string $column): string
    {
        foreach ($this->columns[$column] ?? [] as $position) {
            $value = trim($row[$position] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Every non-empty value of a (repeated) column, in column order.
     *
     * @param  list<string>  $row
     * @return list<string>
     */
    private function all(array $row, string $column): array
    {
        $values = [];
        foreach ($this->columns[$column] ?? [] as $position) {
            $value = trim($row[$position] ?? '');
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function count(string $what, int $by = 1): void
    {
        if ($by > 0) {
            $this->unsupported[$what] = ($this->unsupported[$what] ?? 0) + $by;
        }
    }

    private function warn(string $message): void
    {
        if (count($this->warnings) < 100 && ! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    // ── what the importer needs ─────────────────────────────────────────────

    /**
     * Statuses in status-category order (to do, in progress, done), then first appearance.
     *
     * @return list<array{name: string, category: string, count: int}>
     */
    public function statuses(): array
    {
        $statuses = [];
        foreach ($this->issues as $issue) {
            $statuses[$issue['status']] ??= ['name' => $issue['status'], 'category' => $issue['category'], 'count' => 0];
            $statuses[$issue['status']]['count']++;
        }
        $order = ['todo' => 0, 'in_progress' => 1, 'done' => 2];
        $list = array_values($statuses);
        $index = array_flip(array_keys($statuses));
        usort($list, fn ($a, $b) => [$order[$a['category']], $index[$a['name']]] <=> [$order[$b['category']], $index[$b['name']]]);

        return $list;
    }

    /** @return list<array{name: string, count: int}> values of one issue field, by frequency */
    public function tally(string $field): array
    {
        $counts = [];
        foreach ($this->issues as $issue) {
            foreach ((array) $issue[$field] as $value) {
                if ($value !== null && $value !== '') {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return array_map(fn ($name, $count) => ['name' => (string) $name, 'count' => $count], array_keys($counts), $counts);
    }

    /** @return list<array{name: string, count: int}> assignees and reporters */
    public function persons(): array
    {
        $counts = [];
        foreach ($this->issues as $issue) {
            foreach (array_unique(array_filter([$issue['assignee'], $issue['reporter']])) as $person) {
                $counts[$person] = ($counts[$person] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_map(fn ($name, $count) => ['name' => (string) $name, 'count' => $count], array_keys($counts), $counts);
    }

    /** @return list<array{key: string, name: string}> */
    public function projects(): array
    {
        $projects = [];
        foreach ($this->issues as $issue) {
            if ($issue['project']['key'] !== '' || $issue['project']['name'] !== '') {
                $projects[$issue['project']['key'].'|'.$issue['project']['name']] = $issue['project'];
            }
        }

        return array_values($projects);
    }

    /** The sprint an issue is in now: Jira lists every sprint it was in; the last one is current. */
    public static function currentSprint(array $issue): ?string
    {
        return $issue['sprints'] === [] ? null : $issue['sprints'][count($issue['sprints']) - 1];
    }

    /**
     * Each issue's parent key within this file (sub-task → parent, story → epic), at most
     * Item::MAX_DEPTH levels deep, plus the issues in creation order (parents first).
     *
     * @return array{parents: array<string, string|null>, order: list<int>}
     */
    public function hierarchy(): array
    {
        $byKey = [];
        $byId = [];
        foreach ($this->issues as $index => $issue) {
            $byKey[$issue['key']] = $index;
            if ($issue['id'] !== '') {
                $byId[$issue['id']] = $issue['key'];
            }
        }

        $parents = [];
        foreach ($this->issues as $issue) {
            $parent = null;
            foreach ([$issue['parent'], $issue['epic']] as $ref) {
                if ($ref === null) {
                    continue;
                }
                $candidate = isset($byKey[$ref]) ? $ref : ($byId[$ref] ?? null);
                if ($candidate !== null && $candidate !== $issue['key']) {
                    $parent = $candidate;
                    break;
                }
                $this->warn("The parent of {$issue['key']} ({$ref}) isn’t in this file, so it is imported as a top-level issue.");
            }
            $parents[$issue['key']] = $parent;
        }

        // break cycles (A → B → A): cut the link of an issue that leads back to itself
        foreach (array_keys($parents) as $key) {
            $seen = [$key => true];
            $current = $parents[$key];
            while ($current !== null && ! isset($seen[$current])) {
                $seen[$current] = true;
                $current = $parents[$current];
            }
            if ($current === $key) {
                $this->warn("{$key} and its parent point at each other; {$key} is imported as a top-level issue.");
                $parents[$key] = null;
            }
        }

        // depth; anything deeper than issue → subtask → sub-subtask moves one level up
        $depth = [];
        $resolve = function (string $key) use (&$resolve, &$depth, &$parents): int {
            if (isset($depth[$key])) {
                return $depth[$key];
            }
            $parent = $parents[$key];
            if ($parent === null) {
                return $depth[$key] = 0;
            }
            if ($resolve($parent) >= Item::MAX_DEPTH) {
                $this->warn("{$key} is nested deeper than Brainitive allows (issue → subtask → sub-subtask); it is placed one level higher.");
                $parent = $parents[$key] = $parents[$parent];
            }

            return $depth[$key] = $parent === null ? 0 : $resolve($parent) + 1;
        };
        foreach (array_keys($parents) as $key) {
            $resolve($key);
        }

        $order = array_keys($this->issues);
        usort($order, fn ($a, $b) => [$depth[$this->issues[$a]['key']], $a] <=> [$depth[$this->issues[$b]['key']], $b]);

        return ['parents' => $parents, 'order' => $order];
    }
}
