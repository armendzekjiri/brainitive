/** Nesting helpers for board items (issue → subtask → sub-subtask). Mirrors Item::MAX_DEPTH. */
export const MAX_ITEM_DEPTH = 2;

interface TreeItem {
    id: number;
    parent_id?: number | null;
    column_id?: number | null;
}

export function itemTree<T extends TreeItem>(items: T[]) {
    const byId = new Map(items.map((i) => [i.id, i]));
    const children = new Map<number, T[]>();
    items.forEach((i) => {
        if (i.parent_id) children.set(i.parent_id, [...(children.get(i.parent_id) ?? []), i]);
    });

    const ancestors = (id: number): T[] => {
        const chain: T[] = [];
        let current = byId.get(id);
        const seen = new Set<number>([id]);
        while (current?.parent_id && !seen.has(current.parent_id)) {
            const parent = byId.get(current.parent_id);
            if (!parent) break;
            chain.unshift(parent);
            seen.add(parent.id);
            current = parent;
        }
        return chain;
    };

    const descendants = (id: number): T[] => {
        const out: T[] = [];
        const walk = (pid: number) => (children.get(pid) ?? []).forEach((c) => {
            if (out.includes(c)) return;
            out.push(c);
            walk(c.id);
        });
        walk(id);
        return out;
    };

    const depth = (id: number) => ancestors(id).length;
    const height = (id: number): number => Math.max(0, ...(children.get(id) ?? []).map((c) => 1 + height(c.id)));

    return { byId, ancestors, descendants, depth, height };
}
