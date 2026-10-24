<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer the workspace bills (v1.38.0). Projects belong to at most one client; billing reports
 * are made per client. `tax_rate_bp` null = the workspace's default VAT (basis points, 19 % = 1900).
 * Workspace admins only (BillingPolicy).
 */
class Client extends Model
{
    protected $fillable = ['workspace_id', 'name', 'email', 'address', 'vat_id', 'tax_rate_bp', 'notes', 'archived_at'];

    protected function casts(): array
    {
        return ['tax_rate_bp' => 'integer', 'archived_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
