<?php

namespace App\Support\Templates\Builtin;

/**
 * Small builders for built-in template documents (TemplateDocument format: refs, day offsets).
 */
trait Blocks
{
    private static function doc(string $html): array
    {
        return ['type' => 'lexical', 'html' => $html];
    }

    private static function issue(string $ref, string $board, string $column, string $title, array $extra = []): array
    {
        return ['ref' => $ref, 'board' => $board, 'column' => $column, 'title' => $title] + $extra;
    }

    private static function column(string $ref, string $name, string $color, array $extra = []): array
    {
        return ['ref' => $ref, 'name' => $name, 'color' => $color] + $extra;
    }

    private static function bar(string $id, int $start, int $end, string $color, array $extra = []): array
    {
        return ['id' => $id, 'start' => $start, 'end' => $end, 'type' => 'bar', 'color' => $color] + $extra;
    }

    private static function milestone(string $id, int $day): array
    {
        return ['id' => $id, 'start' => $day, 'end' => $day, 'type' => 'milestone', 'color' => '#F59E0B'];
    }

    /**
     * Checklist tasks; with $dueStep each one is due $dueStep days after the previous.
     */
    private static function tasks(array $titles, ?int $dueStep = null, array $extra = []): array
    {
        return array_map(fn ($title, $i) => ['title' => $title, 'due' => $dueStep === null ? null : ($i + 1) * $dueStep] + $extra, $titles, array_keys($titles));
    }

    /**
     * Select options from labels: ['o-high' => ['High', '#ef4444'], …].
     */
    private static function options(array $options): array
    {
        return array_map(fn ($ref, $option) => ['ref' => $ref, 'label' => $option[0], 'color' => $option[1] ?? null], array_keys($options), $options);
    }
}
