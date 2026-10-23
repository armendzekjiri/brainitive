<?php

namespace App\Support\Releases;

use App\Models\Item;
use App\Models\Release;

/**
 * Deterministic first draft of release notes: the release's finished issues grouped into
 * Features / Improvements / Fixes, titles only. No keys, links, descriptions or comments —
 * the author curates what becomes public.
 */
class ReleaseNotes
{
    private const FEATURE_TYPES = ['story', 'epic', 'feature'];

    private const FIX_TYPES = ['bug'];

    public static function draft(Release $release): string
    {
        $groups = ['Features' => [], 'Improvements' => [], 'Fixes' => []];

        $release->items()->whereNotNull('completed_at')->whereNull('parent_id')
            ->with('itemType:id,slug')->orderBy('completed_at')->get(['id', 'title', 'item_type_id'])
            ->each(function (Item $item) use (&$groups) {
                $slug = $item->itemType?->slug;
                $group = in_array($slug, self::FEATURE_TYPES, true) ? 'Features' : (in_array($slug, self::FIX_TYPES, true) ? 'Fixes' : 'Improvements');
                $groups[$group][] = trim($item->title);
            });

        $html = $release->description ? '<p>'.e($release->description).'</p>' : '';
        foreach (array_filter($groups) as $heading => $titles) {
            $html .= '<h2>'.$heading.'</h2><ul>'.implode('', array_map(fn ($t) => '<li>'.e($t).'</li>', array_unique($titles))).'</ul>';
        }

        return $html !== '' ? $html : '<p>No finished issues in this release yet.</p>';
    }
}
