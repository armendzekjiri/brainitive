<?php

namespace App\Support\Imports\Trello;

use App\Support\Imports\ImportFailed;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A Trello board export ("Menu → Print, export and share → Export as JSON"), read and validated
 * once (BRAI-65). Everything the importer needs is normalized here; nothing is written. Malformed
 * or oversized files fail with an ImportFailed message the user can act on.
 *
 * Shape (Trello's own, only the parts used): {id, name, desc, url, lists[{id, name, closed, pos}],
 * cards[{id, name, desc, idList, pos, closed, due, start, idLabels, idMembers, attachments,
 * customFieldItems, shortUrl}], checklists[{id, idCard, name, pos, checkItems[{id, name, state, pos}]}],
 * labels[{id, name, color}], members[{id, fullName, username, email?}], actions[{type, data}],
 * customFields, pluginData, boardPlugins}.
 */
final class TrelloExport
{
    public const MAX_BYTES = 25 * 1024 * 1024; // board exports are usually a few MB; the preview decodes in the web request

    public const MAX_CARDS = 5000;

    public const MAX_LISTS = 500;

    public const MAX_LABELS = 1000;

    public const MAX_MEMBERS = 1000;

    public const MAX_CHECK_ITEMS = 20000;

    public const MAX_TITLE = 255;

    public const MAX_DESCRIPTION = 100_000;

    private const HOW_TO = 'In Trello, open the board → Menu → Print, export and share → Export as JSON, and upload that file.';

    /** Trello's label colours → hex (the "_light" / "_dark" variants map to the base colour). */
    private const COLORS = [
        'green' => '#4bce97', 'yellow' => '#e2b203', 'orange' => '#faa53d', 'red' => '#f87462',
        'purple' => '#9f8fef', 'blue' => '#579dff', 'sky' => '#6cc3e0', 'lime' => '#94c748',
        'pink' => '#e774bb', 'black' => '#8590a2',
    ];

    /**
     * @param  array{id: string, name: string, desc: string, url: ?string}  $board
     * @param  list<array{id: string, name: string, closed: bool, pos: float}>  $lists  by position
     * @param  list<array{id: string, name: string, desc: string, list: string, pos: float, closed: bool, due: ?CarbonImmutable, start: ?CarbonImmutable, labels: list<string>, members: list<string>, attachments: list<array{name: string, url: string}>, url: ?string}>  $cards  by list, then position
     * @param  array<string, list<array{id: string, name: string, items: list<array{id: string, name: string, complete: bool}>}>>  $checklists  card id → checklists by position
     * @param  array<string, array{id: string, name: string, color: string}>  $labels  by id
     * @param  array<string, array{id: string, name: string, username: string, email: ?string}>  $members  by id
     * @param  array<string, int>  $comments  card id → comment count
     * @param  array<string, int>  $customFieldValues  card id → filled custom field count
     * @param  list<string>  $warnings
     */
    private function __construct(
        public readonly array $board,
        public readonly array $lists,
        public readonly array $cards,
        public readonly array $checklists,
        public readonly array $labels,
        public readonly array $members,
        public readonly array $comments,
        public readonly array $customFieldValues,
        public readonly int $powerUps,
        public readonly array $warnings,
    ) {}

    /** @throws ImportFailed */
    public static function fromFile(string $path): self
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new ImportFailed('The uploaded file couldn’t be read. Upload it again.');
        }
        if ($size > self::MAX_BYTES) {
            throw new ImportFailed('The file is larger than 25 MB. Export a smaller board, or archive old cards in Trello first.');
        }

        // A JSON tree takes several times its file size in memory; give a big export room to decode.
        self::ensureMemory($size);
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable) {
            throw new ImportFailed('This file isn’t valid JSON. '.self::HOW_TO);
        }

        return self::fromArray($data);
    }

    /** @throws ImportFailed */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data) || array_is_list($data) || ! self::str($data, 'id') || ! is_string($data['name'] ?? null)
            || ! is_array($data['lists'] ?? null) || ! is_array($data['cards'] ?? null)) {
            throw new ImportFailed('This isn’t a Trello board export. '.self::HOW_TO);
        }

        $warnings = [];
        $board = [
            'id' => $data['id'],
            'name' => self::clip(trim($data['name']) !== '' ? trim($data['name']) : 'Trello board', self::MAX_TITLE),
            'desc' => is_string($data['desc'] ?? null) ? $data['desc'] : '',
            'url' => self::url($data['url'] ?? null),
        ];

        // Lists
        self::limit($data['lists'], self::MAX_LISTS, 'lists');
        $lists = [];
        foreach ($data['lists'] as $i => $list) {
            if (! is_array($list) || ! self::str($list, 'id') || ! is_string($list['name'] ?? null)) {
                throw new ImportFailed('List #'.($i + 1).' in the export is malformed. '.self::HOW_TO);
            }
            if (isset($lists[$list['id']])) {
                throw new ImportFailed('The export contains the list "'.self::clip($list['name'], 60).'" twice.');
            }
            $lists[$list['id']] = [
                'id' => $list['id'],
                'name' => self::clip(trim($list['name']) !== '' ? trim($list['name']) : 'Untitled list', 255),
                'closed' => (bool) ($list['closed'] ?? false),
                'pos' => self::pos($list['pos'] ?? null, $i),
            ];
        }
        $lists = array_values($lists);
        usort($lists, fn ($a, $b) => $a['pos'] <=> $b['pos']);
        $listIndex = array_flip(array_column($lists, 'id'));

        // Labels
        $rawLabels = is_array($data['labels'] ?? null) ? $data['labels'] : [];
        self::limit($rawLabels, self::MAX_LABELS, 'labels');
        $labels = [];
        foreach ($rawLabels as $label) {
            if (! is_array($label) || ! self::str($label, 'id')) {
                throw new ImportFailed('A label in the export is malformed. '.self::HOW_TO);
            }
            $color = is_string($label['color'] ?? null) ? $label['color'] : null;
            $base = $color !== null ? preg_replace('/_(light|dark)$/', '', $color) : null;
            $name = is_string($label['name'] ?? null) ? trim($label['name']) : '';
            $labels[$label['id']] = [
                'id' => $label['id'],
                'name' => self::clip($name !== '' ? $name : ($base !== null ? ucfirst($base) : 'Label'), 50),
                'color' => self::COLORS[$base] ?? '#8590a2',
            ];
        }

        // Members
        $rawMembers = is_array($data['members'] ?? null) ? $data['members'] : [];
        self::limit($rawMembers, self::MAX_MEMBERS, 'members');
        $members = [];
        foreach ($rawMembers as $member) {
            if (! is_array($member) || ! self::str($member, 'id')) {
                throw new ImportFailed('A member in the export is malformed. '.self::HOW_TO);
            }
            $username = is_string($member['username'] ?? null) ? $member['username'] : '';
            $name = is_string($member['fullName'] ?? null) && trim($member['fullName']) !== '' ? trim($member['fullName']) : ($username ?: 'Trello member');
            $email = is_string($member['email'] ?? null) && filter_var($member['email'], FILTER_VALIDATE_EMAIL) ? strtolower($member['email']) : null;
            $members[$member['id']] = ['id' => $member['id'], 'name' => self::clip($name, 120), 'username' => self::clip($username, 120), 'email' => $email];
        }

        // Cards
        self::limit($data['cards'], self::MAX_CARDS, 'cards');
        $cards = [];
        $unknownList = 0;
        $badDates = 0;
        $longTitles = 0;
        $customFieldValues = [];
        foreach ($data['cards'] as $i => $card) {
            if (! is_array($card) || ! self::str($card, 'id') || ! is_string($card['name'] ?? null) || ! is_string($card['idList'] ?? null)) {
                throw new ImportFailed('Card #'.($i + 1).' in the export is malformed. '.self::HOW_TO);
            }
            if (isset($cards[$card['id']])) {
                throw new ImportFailed('The export contains the card "'.self::clip($card['name'], 60).'" twice.');
            }
            $list = $card['idList'];
            if (! isset($listIndex[$list])) {
                $unknownList++;
                $list = '';
            }
            $title = trim(preg_replace('/\s+/u', ' ', $card['name']) ?? '');
            if (mb_strlen($title) > self::MAX_TITLE) {
                $longTitles++;
            }
            [$due, $dueOk] = self::date($card['due'] ?? null);
            [$start, $startOk] = self::date($card['start'] ?? null);
            $badDates += (int) ! $dueOk + (int) ! $startOk;

            $attachments = [];
            foreach (is_array($card['attachments'] ?? null) ? $card['attachments'] : [] as $attachment) {
                if (is_array($attachment)) {
                    $url = self::url($attachment['url'] ?? null);
                    $attachments[] = [
                        'name' => self::clip(is_string($attachment['name'] ?? null) && trim($attachment['name']) !== '' ? trim($attachment['name']) : ($url ?? 'Attachment'), 255),
                        'url' => $url ?? '',
                    ];
                }
            }
            $filled = array_filter(is_array($card['customFieldItems'] ?? null) ? $card['customFieldItems'] : [], 'is_array');
            if ($filled !== []) {
                $customFieldValues[$card['id']] = count($filled);
            }

            $cards[$card['id']] = [
                'id' => $card['id'],
                'name' => self::clip($title !== '' ? $title : 'Untitled card', self::MAX_TITLE),
                'desc' => is_string($card['desc'] ?? null) ? self::clip($card['desc'], self::MAX_DESCRIPTION) : '',
                'list' => $list,
                'pos' => self::pos($card['pos'] ?? null, $i),
                'closed' => (bool) ($card['closed'] ?? false),
                'due' => $due,
                'start' => $start,
                'labels' => array_values(array_filter(array_unique(self::ids($card['idLabels'] ?? [])), fn ($id) => isset($labels[$id]))),
                'members' => array_values(array_filter(array_unique(self::ids($card['idMembers'] ?? [])), fn ($id) => isset($members[$id]))),
                'attachments' => $attachments,
                'url' => self::url($card['shortUrl'] ?? ($card['url'] ?? null)),
            ];
        }
        $cards = array_values($cards);
        usort($cards, fn ($a, $b) => [$listIndex[$a['list']] ?? -1, $a['pos']] <=> [$listIndex[$b['list']] ?? -1, $b['pos']]);
        $cardIds = array_flip(array_column($cards, 'id'));

        if ($unknownList > 0) {
            $warnings[] = "{$unknownList} card(s) belong to a list that isn’t in the export; they go into the first column.";
        }
        if ($badDates > 0) {
            $warnings[] = "{$badDates} date(s) couldn’t be read and were left empty.";
        }
        if ($longTitles > 0) {
            $warnings[] = "{$longTitles} card title(s) are longer than ".self::MAX_TITLE.' characters and were shortened.';
        }

        // Checklists
        $checklists = [];
        $checkItems = 0;
        foreach (is_array($data['checklists'] ?? null) ? $data['checklists'] : [] as $i => $checklist) {
            if (! is_array($checklist) || ! self::str($checklist, 'id') || ! is_string($checklist['idCard'] ?? null)) {
                throw new ImportFailed('Checklist #'.($i + 1).' in the export is malformed. '.self::HOW_TO);
            }
            if (! isset($cardIds[$checklist['idCard']])) {
                continue;
            }
            $items = [];
            foreach (is_array($checklist['checkItems'] ?? null) ? $checklist['checkItems'] : [] as $j => $item) {
                if (! is_array($item) || ! self::str($item, 'id') || ! is_string($item['name'] ?? null)) {
                    throw new ImportFailed('An item of the checklist "'.self::clip((string) ($checklist['name'] ?? ''), 60).'" is malformed. '.self::HOW_TO);
                }
                $items[] = [
                    'id' => $item['id'],
                    'name' => self::clip(trim($item['name']) !== '' ? trim($item['name']) : 'Untitled item', self::MAX_TITLE),
                    'complete' => ($item['state'] ?? '') === 'complete',
                    'pos' => self::pos($item['pos'] ?? null, $j),
                ];
            }
            $checkItems += count($items);
            if ($checkItems > self::MAX_CHECK_ITEMS) {
                throw new ImportFailed('The export has more than '.number_format(self::MAX_CHECK_ITEMS).' checklist items, the limit for one import.');
            }
            usort($items, fn ($a, $b) => $a['pos'] <=> $b['pos']);
            $checklists[$checklist['idCard']][] = [
                'id' => $checklist['id'],
                'name' => self::clip(is_string($checklist['name'] ?? null) && trim($checklist['name']) !== '' ? trim($checklist['name']) : 'Checklist', 255),
                'pos' => self::pos($checklist['pos'] ?? null, $i),
                'items' => array_map(fn ($item) => array_diff_key($item, ['pos' => true]), $items),
            ];
        }
        foreach ($checklists as &$ofCard) {
            usort($ofCard, fn ($a, $b) => $a['pos'] <=> $b['pos']);
            $ofCard = array_map(fn ($c) => array_diff_key($c, ['pos' => true]), $ofCard);
        }
        unset($ofCard);

        // Comments (Trello's JSON export holds at most the newest 1 000 actions)
        $comments = [];
        foreach (is_array($data['actions'] ?? null) ? $data['actions'] : [] as $action) {
            if (is_array($action) && ($action['type'] ?? null) === 'commentCard') {
                $cardId = $action['data']['card']['id'] ?? null;
                if (is_string($cardId) && isset($cardIds[$cardId])) {
                    $comments[$cardId] = ($comments[$cardId] ?? 0) + 1;
                }
            }
        }

        $powerUps = [];
        foreach (['boardPlugins', 'pluginData', 'powerUps'] as $key) {
            foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $plugin) {
                $id = is_array($plugin) ? ($plugin['idPlugin'] ?? $plugin['id'] ?? null) : null;
                if (is_string($id)) {
                    $powerUps[$id] = true;
                }
            }
        }

        return new self(
            board: $board,
            lists: array_map(fn ($l) => array_diff_key($l, ['pos' => true]), $lists),
            cards: $cards,
            checklists: $checklists,
            labels: $labels,
            members: $members,
            comments: $comments,
            customFieldValues: $customFieldValues,
            powerUps: count($powerUps),
            warnings: $warnings,
        );
    }

    /** Hex colour for a Trello colour name (tests, preview). */
    public static function color(?string $trelloColor): string
    {
        return self::COLORS[preg_replace('/_(light|dark)$/', '', (string) $trelloColor)] ?? '#8590a2';
    }

    /** @return list<array{id: string, name: string, closed: bool}> */
    public function openLists(): array
    {
        return array_values(array_filter($this->lists, fn ($l) => ! $l['closed']));
    }

    /** Archived in Trello: the card itself, or the list it is on. */
    public function isArchived(array $card): bool
    {
        if ($card['closed']) {
            return true;
        }
        foreach ($this->lists as $list) {
            if ($list['id'] === $card['list']) {
                return $list['closed'];
            }
        }

        return false;
    }

    /** The default done list: the last open list whose name says "done", else the last open list. */
    public function defaultDoneList(): ?string
    {
        $open = $this->openLists();
        foreach (array_reverse($open) as $list) {
            if (preg_match('/\b(done|complete[d]?|finished|erledigt|fertig)\b/iu', $list['name'])) {
                return $list['id'];
            }
        }

        return $open === [] ? null : $open[array_key_last($open)]['id'];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private static function str(array $data, string $key): bool
    {
        return is_string($data[$key] ?? null) && $data[$key] !== '' && strlen($data[$key]) <= 64;
    }

    private static function limit(array $rows, int $max, string $what): void
    {
        if (count($rows) > $max) {
            throw new ImportFailed('The export has more than '.number_format($max)." {$what}, the limit for one import.");
        }
    }

    private static function clip(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    private static function pos(mixed $pos, int $fallback): float
    {
        return is_int($pos) || is_float($pos) ? (float) $pos : (float) $fallback;
    }

    /** @return list<string> */
    private static function ids(mixed $ids): array
    {
        return is_array($ids) ? array_values(array_filter($ids, fn ($id) => is_string($id) && $id !== '')) : [];
    }

    /** @return array{0: ?CarbonImmutable, 1: bool} the date, and whether the value was usable (null is fine) */
    private static function date(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, true];
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}(T[\d:.]+(Z|[+-]\d{2}:?\d{2})?)?$/', $value)) {
            return [null, false];
        }
        try {
            $date = CarbonImmutable::parse($value, 'UTC');
        } catch (Throwable) {
            return [null, false];
        }

        return $date->year < 1900 || $date->year > 2200 ? [null, false] : [$date, true];
    }

    /** Only http(s) links survive; anything else (javascript:, data:, …) is dropped. */
    private static function url(mixed $url): ?string
    {
        return is_string($url) && strlen($url) <= 2048 && preg_match('#^https?://[^\s<>"]+$#i', $url) ? $url : null;
    }

    private static function ensureMemory(int $bytes): void
    {
        $needed = max(256 * 1024 * 1024, $bytes * 16);
        $limit = (string) ini_get('memory_limit');
        if ($limit === '-1') {
            return;
        }
        $current = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
        if ($current < $needed) {
            @ini_set('memory_limit', (string) min($needed, 512 * 1024 * 1024)); // never more than 512 MB in a web request
        }
    }
}
