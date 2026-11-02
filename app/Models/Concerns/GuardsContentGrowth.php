<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Support\Entitlements\LimitGate;
use App\Support\Imports\ImportScope;

/**
 * Edits that make content bigger while the workspace is at its content limit are refused (Codex web review B):
 * one check on the model, so the web, the App API, MCP, the CLI, sync and the extension all get it. An edit that
 * keeps or shrinks the size always goes through. The model lists its text columns in `growthColumns()` and names
 * its workspace in `meteredWorkspace()`.
 */
trait GuardsContentGrowth
{
    public static function bootGuardsContentGrowth(): void
    {
        static::updating(function (self $model) {
            $columns = array_values(array_intersect($model->growthColumns(), array_keys($model->getDirty())));
            if ($columns === [] || ImportScope::active()) {
                return;
            }
            $workspace = $model->meteredWorkspace();
            if (! $workspace instanceof Workspace) {
                return;
            }
            $attributes = $model->getAttributes();
            app(LimitGate::class)->ensureContentGrowth(
                $workspace,
                array_map(fn (string $column) => $model->getRawOriginal($column), $columns),
                array_map(fn (string $column) => $attributes[$column] ?? null, $columns),
            );
        });
    }

    /** @return list<string> the text / document columns whose size counts */
    abstract public function growthColumns(): array;

    abstract public function meteredWorkspace(): ?Workspace;
}
