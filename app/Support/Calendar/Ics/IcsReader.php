<?php

namespace App\Support\Calendar\Ics;

use App\Support\Calendar\CalendarSyncFailed;

/**
 * A small, forgiving RFC 5545 parser (BRAI-141): unfolds lines, parses parameters (quoted
 * values, multi-values) and builds the component tree. Feeds in the wild are messy (bare LF,
 * lower-case names, unclosed components), so unknown or broken lines are skipped rather than
 * failing the whole calendar. Values stay raw; IcsProperty::textValue() / IcsTime interpret them.
 */
final class IcsReader
{
    private const MAX_DEPTH = 8;

    /** Content lines / components kept: bounds the memory of a hostile 5 MB file (~200 bytes per line). */
    public const MAX_PROPERTIES = 200000;

    public const MAX_COMPONENTS = 30000;

    /**
     * The first VCALENDAR in $text, or null when there is none.
     *
     * @throws CalendarSyncFailed when the calendar has more lines / components than we read
     */
    public static function parse(string $text): ?IcsComponent
    {
        $properties = 0;
        $components = 0;
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        // Unfold: a line break followed by one space or tab continues the previous line.
        $text = (string) preg_replace('/\r\n[ \t]|\n[ \t]|\r[ \t]/', '', $text);

        $root = null;
        /** @var list<IcsComponent> $stack */
        $stack = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $property = self::parseLine($line);
            if ($property === null) {
                continue;
            }

            if ($property->name === 'BEGIN') {
                $name = strtoupper(trim($property->value));
                if ($stack === [] && $name !== 'VCALENDAR') {
                    continue; // anything before the calendar
                }
                if (count($stack) >= self::MAX_DEPTH || $name === '') {
                    return $root;
                }
                if (++$components > self::MAX_COMPONENTS) {
                    throw new CalendarSyncFailed('This calendar is too big to load.');
                }
                $component = new IcsComponent($name);
                if ($stack !== []) {
                    $stack[count($stack) - 1]->children[] = $component;
                } elseif ($root === null) {
                    $root = $component;
                } else {
                    break; // a second VCALENDAR: one is enough
                }
                $stack[] = $component;

                continue;
            }

            if ($property->name === 'END') {
                $name = strtoupper(trim($property->value));
                // pop up to the matching BEGIN (tolerates a missing END of a child)
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]->name === $name) {
                        $stack = array_slice($stack, 0, $i);
                        break;
                    }
                }
                if ($stack === [] && $root !== null) {
                    break;
                }

                continue;
            }

            if ($stack !== []) {
                if (++$properties > self::MAX_PROPERTIES) {
                    throw new CalendarSyncFailed('This calendar is too big to load.');
                }
                $stack[count($stack) - 1]->properties[] = $property;
            }
        }

        return $root;
    }

    /** NAME *(;PARAM=value*(,value)) : VALUE — or null for a line that isn't a content line. */
    public static function parseLine(string $line): ?IcsProperty
    {
        $len = strlen($line);
        $i = strcspn($line, ';:');
        $name = strtoupper(trim(substr($line, 0, $i)));
        if ($name === '' || $i >= $len || ! preg_match('/^[A-Z0-9.\-_]+$/', $name)) {
            return null;
        }

        $params = [];
        while ($i < $len && $line[$i] === ';') {
            $i++;
            $start = $i;
            $i += strcspn($line, '=;:', $i);
            $key = strtoupper(trim(substr($line, $start, $i - $start)));
            $values = [];
            if ($i < $len && $line[$i] === '=') {
                $i++;
                while (true) {
                    if ($i < $len && $line[$i] === '"') {
                        $end = strpos($line, '"', $i + 1);
                        $end = $end === false ? $len : $end;
                        $values[] = substr($line, $i + 1, $end - $i - 1);
                        $i = min($len, $end + 1);
                    } else {
                        $start = $i;
                        $i += strcspn($line, ',;:', $i);
                        $values[] = substr($line, $start, $i - $start);
                    }
                    if ($i < $len && $line[$i] === ',') {
                        $i++;

                        continue;
                    }
                    break;
                }
            }
            if ($key !== '') {
                $params[$key] = implode(',', $values);
            }
        }

        if ($i >= $len || $line[$i] !== ':') {
            return null;
        }

        return new IcsProperty($name, $params, substr($line, $i + 1), $line);
    }
}
