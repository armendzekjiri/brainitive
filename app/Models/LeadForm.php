<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A lead generation form (BRAI-93): a multi-step form (`definition`), its look (`theme`), what
 * happens after submit (`settings`) and its own leads board (columns + leads).
 */
class LeadForm extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'uuid', 'project_id', 'name', 'slug', 'public_slug', 'status', 'definition', 'theme',
        'settings', 'webhook_secret', 'inbound_token', 'next_lead_number', 'position', 'created_by',
    ];

    protected $hidden = ['webhook_secret', 'inbound_token'];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'theme' => 'array',
            'settings' => 'array',
            'webhook_secret' => 'encrypted',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (LeadForm $form) {
            $form->uuid ??= (string) Str::uuid();
            $form->slug ??= Str::slug($form->name) ?: 'form';
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(LeadFormColumn::class)->orderBy('position')->orderBy('id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeadFormEvent::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->public_slug !== null;
    }

    /**
     * The next lead number for this form (atomic: the counter is the last number used).
     */
    public function takeLeadNumber(): int
    {
        return DB::transaction(function () {
            $form = static::whereKey($this->id)->lockForUpdate()->first();
            $form->increment('next_lead_number');

            return (int) $form->next_lead_number;
        });
    }
}
