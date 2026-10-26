<?php

namespace App\Support\Imports;

/**
 * Every import source (v1.41.0). Each ticket adds one line.
 */
final class ImportRegistry
{
    /** @var array<string, class-string<Importer>> */
    public const SOURCES = [
        // 'trello' => Trello\TrelloImporter::class,          // BRAI-65
        // 'jira' => Jira\JiraImporter::class,                // BRAI-139
        // 'notion' => Notion\NotionImporter::class,          // BRAI-66
        // 'brainitive' => Archive\ProjectArchiveImporter::class, // BRAI-81 (restore)
    ];

    /** @var array<string, class-string<Importer>> test-only extra sources (ImportRegistry::fake) */
    private static array $fakes = [];

    /** @return array<string, class-string<Importer>> */
    public static function sources(): array
    {
        return self::SOURCES + self::$fakes;
    }

    /** @param class-string<Importer>|null $class null removes it */
    public static function fake(string $source, ?string $class): void
    {
        if ($class === null) {
            unset(self::$fakes[$source]);
        } else {
            self::$fakes[$source] = $class;
        }
    }

    /** @return class-string<Importer> */
    public static function for(string $source): string
    {
        return self::sources()[$source] ?? throw new ImportFailed('Unknown import source.');
    }

    public static function make(string $source): Importer
    {
        return app(self::for($source));
    }

    /** @return list<array{key: string, label: string, description: string, accept: string, needs_project: bool}> */
    public static function catalog(): array
    {
        return array_values(array_map(fn (string $class, string $key) => [
            'key' => $key,
            'label' => $class::label(),
            'description' => $class::description(),
            'accept' => $class::accept(),
            'needs_project' => $class::needsProject(),
        ], self::sources(), array_keys(self::sources())));
    }
}
