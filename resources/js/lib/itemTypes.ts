import type { ItemType } from '@/types';

/**
 * The type a new issue starts with, from the types enabled for the project (the server's
 * ItemType::defaultIdFor): the default type, else the first that isn't Subtask, else none.
 * A project can switch the instance default (Task) off.
 */
export function defaultItemTypeId(types: ItemType[]): string {
    const type = types.find((t) => t.is_default) ?? types.find((t) => t.slug === 'task') ?? types.find((t) => t.slug !== 'subtask');
    return type ? String(type.id) : '';
}

/**
 * Options of an issue's type picker: the enabled types plus the issue's current type when the
 * project switched it off — the card keeps it, it just can't be picked again.
 */
export function typeOptionsFor(types: ItemType[], current: ItemType | null | undefined): { types: ItemType[]; offId: number | null } {
    if (!current || types.some((t) => t.id === current.id)) return { types, offId: null };
    return { types: [...types, current], offId: current.id };
}
