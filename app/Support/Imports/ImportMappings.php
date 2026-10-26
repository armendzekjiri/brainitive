<?php

namespace App\Support\Imports;

use App\Models\ImportMapping;
use App\Models\ImportRun;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Source object → created record, per run (v1.41.0). The only way importers create records:
 *
 *     $item = ImportMappings::once($run, 'card', $card['id'], Item::class, fn () => Item::create([...]));
 *
 * A retried or resumed run gets the existing record back instead of creating a second one. The
 * record and its mapping are written in one transaction, so a crash in between leaves neither.
 */
final class ImportMappings
{
    /**
     * @template T of Model
     *
     * @param  class-string<T>  $modelClass
     * @param  Closure(): T  $create
     * @return T
     */
    public static function once(ImportRun $run, string $sourceType, string|int $sourceId, string $modelClass, Closure $create): Model
    {
        $existing = self::find($run, $sourceType, $sourceId);
        if ($existing !== null && ($model = $modelClass::query()->find($existing)) !== null) {
            return $model;
        }

        return DB::transaction(function () use ($run, $sourceType, $sourceId, $modelClass, $create) {
            $model = $create();
            ImportMapping::updateOrCreate(
                ['import_run_id' => $run->id, 'source_type' => $sourceType, 'source_id' => (string) $sourceId],
                ['target_type' => class_basename($modelClass), 'target_id' => $model->getKey()],
            );

            return $model;
        });
    }

    /** The created record's id for a source object, or null. */
    public static function find(ImportRun $run, string $sourceType, string|int $sourceId): ?int
    {
        $id = ImportMapping::query()
            ->where('import_run_id', $run->id)
            ->where('source_type', $sourceType)
            ->where('source_id', (string) $sourceId)
            ->value('target_id');

        return $id === null ? null : (int) $id;
    }

    /** All source id → target id pairs of one type (for rewriting links after the records exist). */
    public static function map(ImportRun $run, string $sourceType): array
    {
        return ImportMapping::query()
            ->where('import_run_id', $run->id)
            ->where('source_type', $sourceType)
            ->pluck('target_id', 'source_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
