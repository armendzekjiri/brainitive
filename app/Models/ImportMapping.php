<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Source object → the record an import created for it (v1.41.0). Unique per (run, source_type,
 * source_id): a resumed or retried run looks here first and never creates a second record.
 * Use App\Support\Imports\ImportMappings, not this model directly.
 */
class ImportMapping extends Model
{
    protected $fillable = ['import_run_id', 'source_type', 'source_id', 'target_type', 'target_id'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }
}
