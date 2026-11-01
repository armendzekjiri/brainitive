/**
 * Flatten a tree of pages into a list with depth for use in parent selectors.
 * Optionally filter to folders only (e.g. when creating a new folder).
 */
export interface PageTreeNode {
    id: number;
    name: string;
    type: string;
    is_whiteboard?: boolean;
    children?: PageTreeNode[];
}

export interface FlattenedPageOption {
    id: number;
    name: string;
    type: string;
    is_whiteboard?: boolean;
    depth: number;
}

export function flattenPagesForParentSelect(
    nodes: PageTreeNode[],
    options: { foldersOnly?: boolean } = {}
): FlattenedPageOption[] {
    const { foldersOnly = false } = options;

    function flatten(nodes: PageTreeNode[], depth: number): FlattenedPageOption[] {
        return nodes.flatMap((n) => {
            const item: FlattenedPageOption = {
                id: n.id,
                name: n.name,
                type: n.type,
                is_whiteboard: n.is_whiteboard,
                depth,
            };
            if (foldersOnly && n.type !== 'folder') {
                return [...flatten(n.children ?? [], depth + 1)];
            }
            return [item, ...flatten(n.children ?? [], depth + 1)];
        });
    }

    const flattened = flatten(nodes, 0);
    return foldersOnly ? flattened.filter((p) => p.type === 'folder') : flattened;
}

// ---------------------------------------------------------------------------------------------
// Moving pages, whiteboards and folders (BRAI-431). Pure helpers over the sidebar tree, shared by
// the sidebar drag & drop and the "Move…" dialog. The server decides (PageMover); these only
// decide what the UI offers and how the tree looks until the server answers.
// ---------------------------------------------------------------------------------------------

export interface MovableNode {
    id: number;
    name: string;
    type: string;
    is_whiteboard?: boolean;
    parent_id?: number | null;
    icon?: string | null;
    children?: MovableNode[];
}

/** The sidebar lists docs, whiteboards and folders apart, so "before / after" only works within a kind. */
export type PageKind = 'doc' | 'whiteboard' | 'folder';

export function pageKind(node: Pick<MovableNode, 'type' | 'is_whiteboard'>): PageKind {
    if (node.type === 'folder') return 'folder';
    return node.is_whiteboard ? 'whiteboard' : 'doc';
}

/** The node with that id plus its ancestors (outermost first), or null. */
export function findWithAncestors<T extends MovableNode>(nodes: T[], id: number, trail: T[] = []): { node: T; ancestors: T[] } | null {
    for (const node of nodes) {
        if (node.id === id) return { node, ancestors: trail };
        const found = findWithAncestors((node.children ?? []) as T[], id, [...trail, node]);
        if (found) return found;
    }
    return null;
}

/** "Website / Meetings / New Folder" (null = the project's top level). */
export function destinationPath(projectName: string, nodes: MovableNode[], parentId: number | null): string {
    if (parentId === null) return projectName;
    const found = findWithAncestors(nodes, parentId);
    if (!found) return projectName;
    return [projectName, ...found.ancestors.map((a) => a.name), found.node.name].join(' / ');
}

/** Whether `candidateId` is `node` itself or somewhere below it. */
export function isSelfOrDescendant(node: MovableNode, candidateId: number): boolean {
    if (node.id === candidateId) return true;
    return (node.children ?? []).some((child) => isSelfOrDescendant(child, candidateId));
}

export interface FolderDestination {
    id: number;
    name: string;
    icon?: string | null;
    depth: number;
    /** names from the top level down to this folder (the project not included) */
    trail: string[];
}

/** Every folder of the tree, depth-first in sidebar order, with its trail of names. */
export function folderDestinations(nodes: MovableNode[], depth = 0, trail: string[] = []): FolderDestination[] {
    return nodes.flatMap((node) => {
        const isFolder = node.type === 'folder';
        const below = folderDestinations(node.children ?? [], isFolder ? depth + 1 : depth, [...trail, node.name]);
        return isFolder ? [{ id: node.id, name: node.name, icon: node.icon, depth, trail: [...trail, node.name] }, ...below] : below;
    });
}

export interface MovePlacement {
    parentId: number | null;
    beforeId?: number | null;
    afterId?: number | null;
}

/**
 * The tree after a move, for the optimistic sidebar: the node leaves its parent and lands in the
 * new one before / after the anchor, or at the end. Returns the input unchanged when the node
 * isn't found or the move would make a cycle.
 */
export function moveTreeNode<T extends MovableNode>(nodes: T[], id: number, placement: MovePlacement): T[] {
    const found = findWithAncestors(nodes, id);
    if (!found) return nodes;
    if (placement.parentId !== null && isSelfOrDescendant(found.node, placement.parentId)) return nodes;

    const removeFrom = (list: T[]): T[] =>
        list
            .filter((n) => n.id !== id)
            .map((n) => (n.children?.length ? ({ ...n, children: removeFrom(n.children as T[]) } as T) : n));

    const moved = { ...found.node, parent_id: placement.parentId } as T;
    const insertInto = (list: T[]): T[] => {
        const next = [...list];
        const anchorId = placement.beforeId ?? placement.afterId ?? null;
        const anchorIndex = anchorId === null ? -1 : next.findIndex((n) => n.id === anchorId);
        if (anchorIndex === -1) next.push(moved);
        else next.splice(placement.beforeId != null ? anchorIndex : anchorIndex + 1, 0, moved);
        return next;
    };

    const without = removeFrom(nodes);
    if (placement.parentId === null) return insertInto(without);

    const addTo = (list: T[]): T[] =>
        list.map((n) => {
            if (n.id === placement.parentId) return { ...n, children: insertInto((n.children ?? []) as T[]) } as T;
            return n.children?.length ? ({ ...n, children: addTo(n.children as T[]) } as T) : n;
        });
    return addTo(without);
}
