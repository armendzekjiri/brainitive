<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A project template saved by a workspace admin (BRAI-72). The content lives in immutable
 * versions; the newest one is used for new projects. Built-in templates are code
 * (App\Support\Templates\BuiltinTemplates) and have no row.
 */
class ProjectTemplate extends Model
{
    protected $fillable = [
        'uuid',
        'workspace_id',
        'name',
        'description',
        'created_by',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ProjectTemplate $template) {
            $template->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProjectTemplateVersion::class)->orderByDesc('version');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(ProjectTemplateVersion::class)->latestOfMany('version');
    }
}
