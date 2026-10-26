<?php

namespace App\Support\Calendar\Ics;

/**
 * One content line of an iCalendar object (RFC 5545 §3.1): NAME;PARAM=value:VALUE.
 * `value` is the raw (still escaped) value; text() unescapes a TEXT value. Parameter names are
 * upper-case, parameter values unquoted (a multi-value parameter stays comma-joined).
 */
final class IcsProperty
{
    /**
     * @param  array<string, string>  $params
     * @param  string|null  $raw  the original unfolded line, re-emitted unchanged when the property wasn't built here
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params = [],
        public readonly string $value = '',
        private readonly ?string $raw = null,
    ) {}

    /** A TEXT property from plain text (escaped here). */
    public static function text(string $name, string $text, array $params = []): self
    {
        return new self(strtoupper($name), $params, self::escape($text));
    }

    public function param(string $key): ?string
    {
        return $this->params[strtoupper($key)] ?? null;
    }

    /** The value as plain text (\n, \, \; \, \\ unescaped). */
    public function textValue(): string
    {
        return self::unescape($this->value);
    }

    public static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $text);
    }

    public static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\([\\\\;,nN:])/', fn (array $m) => match ($m[1]) {
            'n', 'N' => "\n",
            default => $m[1],
        }, $value);
    }

    /** The content line, folded at 75 octets (never inside a UTF-8 character), CRLF-terminated. */
    public function toLine(): string
    {
        $line = $this->raw ?? $this->build();

        return self::fold($line);
    }

    private function build(): string
    {
        $line = $this->name;
        foreach ($this->params as $key => $value) {
            $line .= ';'.$key.'='.(preg_match('/[:;,"]/', $value) ? '"'.str_replace('"', '', $value).'"' : $value);
        }

        return $line.':'.$this->value;
    }

    public static function fold(string $line): string
    {
        $out = '';
        $max = 75;
        while (strlen($line) > $max) {
            $chunk = mb_strcut($line, 0, $max, 'UTF-8');
            if ($chunk === '') {
                break;
            }
            $out .= $chunk."\r\n ";
            $line = substr($line, strlen($chunk));
            $max = 74; // the continuation line's leading space counts
        }

        return $out.$line."\r\n";
    }
}
