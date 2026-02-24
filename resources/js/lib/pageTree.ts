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
