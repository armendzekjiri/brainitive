<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemLink extends Model
{
    protected $fillable = [
        'source_item_id',
        'target_item_id',
        'link_type',
        'created_by',
    ];

    /**
     * Link types with their inverse types.
     */
    public const LINK_TYPES = [
        'blocks' => 'is_blocked_by',
        'is_blocked_by' => 'blocks',
        'duplicates' => 'is_duplicated_by',
        'is_duplicated_by' => 'duplicates',
        'relates_to' => 'relates_to',
        'clones' => 'is_cloned_by',
        'is_cloned_by' => 'clones',
    ];

    /**
     * Get the display name for a link type.
     */
    public static function getLinkTypeLabel(string $type): string
    {
        $labels = [
            'blocks' => 'blocks',
            'is_blocked_by' => 'is blocked by',
            'duplicates' => 'duplicates',
            'is_duplicated_by' => 'is duplicated by',
            'relates_to' => 'relates to',
            'clones' => 'clones',
            'is_cloned_by' => 'is cloned by',
        ];

        return $labels[$type] ?? $type;
    }

    /**
     * Get the source item.
     */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'source_item_id');
    }

    /**
     * Get the target item.
     */
    public function targetItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'target_item_id');
    }

    /**
     * Get the user who created this link.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
