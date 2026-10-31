<?php

namespace App\Support\Boards;

use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Linked issues" (blocks / is blocked by, duplicates, relates to, clones…) with the same rules
 * and history entries everywhere — the issue view (BoardController) and MCP (BRAI-432).
 *
 * A link is stored once, from the issue it was added on. "A blocks B" and "B is blocked by A"
 * mean the same thing, so find() treats the inverse row from the other side as the same link.
 */
class ItemLinks
{
    /**
     * Link types in the order the issue view offers them.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(ItemLink::LINK_TYPES);
    }

    /**
     * The link between two issues with that meaning, stored from either side, or null.
     */
    public static function find(Item $item, Item $target, string $type): ?ItemLink
    {
        $inverse = ItemLink::LINK_TYPES[$type] ?? $type;

        return ItemLink::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('source_item_id', $item->id)->where('target_item_id', $target->id)->where('link_type', $type))
                ->orWhere(fn (Builder $q) => $q->where('source_item_id', $target->id)->where('target_item_id', $item->id)->where('link_type', $inverse)))
            ->orderBy('id')
            ->first();
    }

    /**
     * Every link between two issues, either direction.
     *
     * @return Collection<int, ItemLink>
     */
    public static function between(Item $item, Item $target)
    {
        return ItemLink::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('source_item_id', $item->id)->where('target_item_id', $target->id))
                ->orWhere(fn (Builder $q) => $q->where('source_item_id', $target->id)->where('target_item_id', $item->id)))
            ->orderBy('id')
            ->get();
    }

    /**
     * Link $item to $target. An existing link with that meaning (either side) is returned
     * unchanged (`wasRecentlyCreated` false) — never a second row, never a second history entry.
     * Callers check that the user may edit $item and see $target.
     *
     * @throws \DomainException for an unknown type or a self-link
     */
    public static function add(Item $item, Item $target, string $type, User $user): ItemLink
    {
        if (! isset(ItemLink::LINK_TYPES[$type])) {
            throw new \DomainException("Unknown link type \"{$type}\". Link types: ".implode(', ', self::types()).'.');
        }
        if ($item->id === $target->id) {
            throw new \DomainException('Cannot link an item to itself.');
        }

        if ($existing = self::find($item, $target, $type)) {
            return $existing;
        }

        $link = ItemLink::create([
            'source_item_id' => $item->id,
            'target_item_id' => $target->id,
            'link_type' => $type,
            'created_by' => $user->id,
        ]);

        ActivityLog::log($item, 'linked', null, [
            'link_type' => $link->link_type,
            'item' => $target->key,
        ]);

        return $link;
    }

    /**
     * Remove a link of $item (stored on either side), with the history entry of the issue view.
     */
    public static function remove(Item $item, ItemLink $link): void
    {
        $outgoing = $link->source_item_id === $item->id;
        $other = $outgoing ? $link->targetItem : $link->sourceItem;

        ActivityLog::log($item, 'unlinked', null, [
            'link_type' => self::typeFor($link, $item),
            'item' => $other?->key,
        ]);

        $link->delete();
    }

    /**
     * The link type read from $item's side: "is_blocked_by" for an incoming "blocks".
     */
    public static function typeFor(ItemLink $link, Item $item): string
    {
        return $link->source_item_id === $item->id
            ? $link->link_type
            : (ItemLink::LINK_TYPES[$link->link_type] ?? $link->link_type);
    }
}
