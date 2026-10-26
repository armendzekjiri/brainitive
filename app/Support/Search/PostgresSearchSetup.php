<?php

namespace App\Support\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL pieces of the search index (BRAI-55): the optional extensions, the immutable
 * `search_unaccent()` wrapper and the trigram index. Used by the migration and by
 * `search:reindex --setup` (after an admin installed an extension later).
 */
final class PostgresSearchSetup
{
    public const EXTENSIONS = ['pg_trgm', 'unaccent'];

    private const CAPABILITIES_CACHE = 'search.pg-capabilities';

    /** Create the extensions this database user may create; never fails. */
    public static function tryExtensions(): void
    {
        foreach (self::EXTENSIONS as $extension) {
            try {
                // a savepoint: a privilege error must not abort the surrounding (migration) transaction
                DB::transaction(fn () => DB::statement("create extension if not exists {$extension}"));
            } catch (\Throwable $e) {
                logger()->warning("search: extension {$extension} is not available — search runs without it", ['error' => $e->getMessage()]);
            }
        }
        Cache::forget(self::CAPABILITIES_CACHE);
    }

    /**
     * `search_unaccent(text)`: unaccent() when the extension exists (schema-qualified, as an
     * IMMUTABLE function must not depend on the caller's search_path), else the input unchanged.
     */
    public static function createUnaccentFunction(): void
    {
        $schema = self::extensionSchema('unaccent');
        $body = $schema === null
            ? 'select $1'
            : sprintf("select %1\$s.unaccent('%2\$s.unaccent'::regdictionary, \$1)", self::ident($schema), str_replace("'", "''", self::ident($schema)));

        DB::statement("create or replace function search_unaccent(text) returns text language sql immutable parallel safe strict as \$fn\$ {$body} \$fn\$");
    }

    public static function createTrigramIndex(): void
    {
        if (self::extensionSchema('pg_trgm') !== null) {
            DB::statement('create index if not exists search_entries_title_trgm_index on search_entries using gin (search_unaccent(lower(title)) gin_trgm_ops)');
        }
    }

    /** @return array{trigram: bool, unaccent: bool} */
    public static function capabilities(): array
    {
        return Cache::remember(self::CAPABILITIES_CACHE, 600, fn () => [
            'trigram' => self::extensionSchema('pg_trgm') !== null,
            'unaccent' => self::extensionSchema('unaccent') !== null,
        ]);
    }

    public static function forgetCapabilities(): void
    {
        Cache::forget(self::CAPABILITIES_CACHE);
    }

    private static function extensionSchema(string $extension): ?string
    {
        $schema = DB::table('pg_extension')
            ->join('pg_namespace', 'pg_namespace.oid', '=', 'pg_extension.extnamespace')
            ->where('pg_extension.extname', $extension)
            ->value('pg_namespace.nspname');

        return is_string($schema) ? $schema : null;
    }

    private static function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
