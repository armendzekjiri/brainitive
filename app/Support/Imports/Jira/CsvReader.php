<?php

namespace App\Support\Imports\Jira;

use App\Support\Imports\ImportFailed;
use Generator;

/**
 * Streams a CSV export without trusting it (v1.41.0, Jira). Handles what real exports contain:
 *  - a UTF-8 byte order mark (Excel, Jira Server), and Windows-1252 cells (a file re-saved in Excel);
 *  - `,`, `;` or tab as the delimiter (detected on the header row, outside quotes);
 *  - quoted cells with newlines, delimiters and doubled quotes ("") inside;
 *  - repeated column names (Jira writes one `Labels` / `Comment` / `Sprint` column per value), so
 *    rows are plain lists and callers look columns up by position (columns()).
 * Backslashes are ordinary characters (escape ''): Jira wiki markup uses `\\` for line breaks.
 * Damaged files (unbalanced quotes, rows longer than the header) fail with a message, before anything
 * is written. Not specific to Jira: any importer reading CSV can use it.
 */
final class CsvReader
{
    private string $delimiter = ',';

    /** @var list<string> */
    private array $headers = [];

    private int $offset = 0;

    private bool $recoded = false;

    public function __construct(private readonly string $path, private readonly int $maxBytes = 25 * 1024 * 1024)
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new ImportFailed('The uploaded file couldn’t be read.');
        }
        if ($size > $maxBytes) {
            throw new ImportFailed('The file is larger than '.intdiv($maxBytes, 1024 * 1024).' MB. Export fewer issues at a time.');
        }
        if ($size === 0) {
            throw new ImportFailed('The file is empty.');
        }

        $handle = $this->open();
        $start = (string) fread($handle, 4);
        fclose($handle);
        if (str_starts_with($start, "\xFF\xFE") || str_starts_with($start, "\xFE\xFF")) {
            throw new ImportFailed('The file is saved as UTF-16. Save it as “CSV UTF-8” (or export it from Jira again) and upload it again.');
        }
        $this->offset = str_starts_with($start, "\xEF\xBB\xBF") ? 3 : 0;

        $this->checkQuotes();
        $this->delimiter = $this->detectDelimiter();

        $handle = $this->open();
        $row = fgetcsv($handle, null, $this->delimiter, '"', '');
        fclose($handle);
        if (! is_array($row) || $row === [null]) {
            throw new ImportFailed('The file has no header row.');
        }
        $this->headers = array_map(fn ($cell) => trim($this->utf8((string) $cell)), $row);
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /** @return list<string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Positions of every column with this name (case-insensitive): repeated names give several.
     *
     * @return list<int>
     */
    public function columns(string $name): array
    {
        $name = mb_strtolower($name);

        return array_keys(array_filter($this->headers, fn ($header) => mb_strtolower($header) === $name));
    }

    /** true when a cell had to be converted from Windows-1252 (the file isn't UTF-8). */
    public function recoded(): bool
    {
        return $this->recoded;
    }

    /**
     * Data rows as lists as long as the header (blank lines skipped), keyed by their 1-based row
     * number (the header is row 1).
     *
     * @return Generator<int, list<string>>
     */
    public function rows(int $maxRows): Generator
    {
        $handle = $this->open();
        $width = count($this->headers);
        $number = 0;
        $count = 0;
        try {
            fgetcsv($handle, null, $this->delimiter, '"', ''); // header
            $number = 1;
            while (($row = fgetcsv($handle, null, $this->delimiter, '"', '')) !== false) {
                $number++;
                if ($row === [null] || $row === ['']) {
                    continue; // blank line
                }
                if (count($row) > $width) {
                    $extra = array_slice($row, $width);
                    if (array_filter($extra, fn ($cell) => trim((string) $cell) !== '') !== []) {
                        throw new ImportFailed("Row {$number} has more cells than the header row. The file looks damaged: export it from Jira again.");
                    }
                    $row = array_slice($row, 0, $width);
                }
                if (++$count > $maxRows) {
                    throw new ImportFailed('The file has more than '.number_format($maxRows).' rows. Export fewer issues at a time (for example one Jira project or a date range per file).');
                }
                $cells = [];
                foreach ($row as $cell) {
                    $cells[] = $this->utf8((string) $cell);
                }

                yield $number => array_pad($cells, $width, '');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function open()
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new ImportFailed('The uploaded file couldn’t be read.');
        }
        if ($this->offset > 0) {
            fseek($handle, $this->offset);
        }

        return $handle;
    }

    /** Every quote opens and closes (doubled quotes count twice): an odd total means a cut-off cell. */
    private function checkQuotes(): void
    {
        $handle = $this->open();
        $quotes = 0;
        $binary = false;
        while (! feof($handle)) {
            $chunk = (string) fread($handle, 1024 * 1024);
            $quotes += substr_count($chunk, '"');
            $binary = $binary || str_contains($chunk, "\0");
        }
        fclose($handle);
        if ($binary) {
            throw new ImportFailed('This isn’t a CSV file (it contains binary data).');
        }
        if ($quotes % 2 !== 0) {
            throw new ImportFailed('The file has an unclosed quote, so it was cut off or damaged. Export it from Jira again.');
        }
    }

    /** The most frequent of `,` `;` and tab on the header row, outside quotes. */
    private function detectDelimiter(): string
    {
        $handle = $this->open();
        $line = '';
        $inQuotes = false;
        // the header row ends at the first newline outside quotes (read at most 1 MB)
        while (! feof($handle) && strlen($line) < 1024 * 1024) {
            $char = fgetc($handle);
            if ($char === false) {
                break;
            }
            if ($char === '"') {
                $inQuotes = ! $inQuotes;
            } elseif (($char === "\n" || $char === "\r") && ! $inQuotes) {
                break;
            }
            $line .= $inQuotes ? '' : $char;
        }
        fclose($handle);

        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    private function utf8(string $cell): string
    {
        if ($cell === '' || mb_check_encoding($cell, 'UTF-8')) {
            return $cell;
        }
        $this->recoded = true;

        return (string) mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
    }
}
