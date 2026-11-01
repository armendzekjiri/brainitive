import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type DragEvent } from 'react';
import { toast } from 'sonner';
import { GripVertical } from 'lucide-react';
import type { Page as PageType, Workspace } from '@/types';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { MovePageDialog, moveItemLabel, requestPageMove } from '@/components/page/MovePageDialog';
import { destinationPath, findWithAncestors, isSelfOrDescendant, moveTreeNode, pageKind, type MovePlacement } from '@/lib/pageTree';
import { cn } from '@/lib/utils';

/*
 * Sidebar page tree drag & drop + "Move…" (BRAI-431).
 *
 * Drop zones are measured on the row under the pointer: on a folder the top quarter puts the item
 * before it, the bottom quarter after it and the middle inside it; on other rows the top / bottom
 * half. The sidebar lists docs, whiteboards and folders apart, so "before / after" only works next
 * to a row of the same kind — "inside" works on any folder. The item itself, anything inside it and
 * its current parent are never targets. A move to another parent asks first; a reorder doesn't.
 * The tree is updated optimistically and reset from the server's props after the reload.
 */

export type DropZone = 'before' | 'after' | 'inside';

interface DropTarget {
    /** the row under the pointer; null = a top-level group header */
    nodeId: number | null;
    zone: DropZone;
    placement: MovePlacement;
    parentChanges: boolean;
    path: string;
}

interface PendingMove {
    node: PageType;
    placement: MovePlacement;
    path: string;
}

interface PageTreeDndValue {
    enabled: boolean;
    dragging: PageType | null;
    target: DropTarget | null;
    /** the row the pointer is over while dragging (for auto-expand), allowed or not */
    hoverId: number | null;
    startDrag: (event: DragEvent, node: PageType) => void;
    endDrag: () => void;
    overRow: (event: DragEvent<HTMLElement>, node: PageType) => void;
    overRoot: (event: DragEvent<HTMLElement>) => void;
    leave: (event: DragEvent<HTMLElement>, nodeId: number | null) => void;
    drop: (event: DragEvent) => void;
    openMove: (node: PageType) => void;
}

const PageTreeDndContext = createContext<PageTreeDndValue | null>(null);

export function usePageTreeDnd(): PageTreeDndValue | null {
    return useContext(PageTreeDndContext);
}

const DRAG_TYPE = 'application/x-brainitive-page';

interface DndProject {
    id: number;
    name: string;
    slug: string;
    pages?: PageType[];
    can_edit_pages?: boolean;
}

/**
 * State for one project's page tree. Returns the (optimistic) tree to render, the context value
 * and the dialogs to mount once.
 */
export function usePageTreeDndState(workspace: Workspace, project: DndProject) {
    const serverTree = useMemo(() => project.pages ?? [], [project.pages]);
    const [override, setOverride] = useState<PageType[] | null>(null);
    const tree = override ?? serverTree;
    const [dragging, setDragging] = useState<PageType | null>(null);
    const [target, setTarget] = useState<DropTarget | null>(null);
    const [hoverId, setHoverId] = useState<number | null>(null);
    const [pending, setPending] = useState<PendingMove | null>(null);
    const [moveItem, setMoveItem] = useState<PageType | null>(null);
    const targetRef = useRef<DropTarget | null>(null);
    const enabled = !!project.can_edit_pages;

    // fresh props from the server replace the optimistic tree
    useEffect(() => setOverride(null), [serverTree]);

    const updateTarget = useCallback((next: DropTarget | null) => {
        const prev = targetRef.current;
        const same = prev === next || (!!prev && !!next && prev.nodeId === next.nodeId && prev.zone === next.zone);
        targetRef.current = next;
        if (!same) setTarget(next);
    }, []);

    const resolve = useCallback((dragged: PageType, nodeId: number | null, zone: DropZone): DropTarget | null => {
        const draggedAt = findWithAncestors(tree, dragged.id);
        if (!draggedAt) return null;
        const currentParentId = draggedAt.ancestors.at(-1)?.id ?? null;
        const siblingsOf = (parentId: number | null): PageType[] =>
            parentId === null ? tree : (findWithAncestors(tree, parentId)?.node.children ?? []);

        let placement: MovePlacement;
        if (nodeId === null) {
            placement = { parentId: null };
        } else {
            const rowAt = findWithAncestors(tree, nodeId);
            if (!rowAt) return null;
            const rowParentId = rowAt.ancestors.at(-1)?.id ?? null;
            placement = zone === 'inside'
                ? { parentId: nodeId }
                : zone === 'before'
                    ? { parentId: rowParentId, beforeId: nodeId }
                    : { parentId: rowParentId, afterId: nodeId };
        }

        if (placement.parentId !== null && isSelfOrDescendant(draggedAt.node, placement.parentId)) return null;
        const parentChanges = placement.parentId !== currentParentId;
        if (!parentChanges) {
            // "inside" its own parent / the top level it is already in: nothing to do
            if (placement.beforeId == null && placement.afterId == null) return null;
            const siblings = siblingsOf(currentParentId);
            const index = siblings.findIndex((n) => n.id === dragged.id);
            if (placement.beforeId != null && siblings[index + 1]?.id === placement.beforeId) return null;
            if (placement.afterId != null && siblings[index - 1]?.id === placement.afterId) return null;
        }

        return { nodeId, zone, placement, parentChanges, path: destinationPath(project.name, tree, placement.parentId) };
    }, [tree, project.name]);

    const save = useCallback(async (node: PageType, placement: MovePlacement, announce: boolean) => {
        setOverride(moveTreeNode(tree, node.id, placement));
        try {
            const path = await requestPageMove(workspace.slug, project.slug, node, placement);
            if (announce) toast.success(`Moved "${node.name}" to ${path}`);
        } catch (error) {
            setOverride(null);
            toast.error((error as Error).message);
        }
    }, [tree, workspace.slug, project.slug]);

    const value = useMemo<PageTreeDndValue>(() => ({
        enabled,
        dragging,
        target,
        hoverId,
        startDrag: (event, node) => {
            event.stopPropagation();
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData(DRAG_TYPE, String(node.id));
            setDragging(node);
        },
        endDrag: () => {
            setDragging(null);
            setHoverId(null);
            updateTarget(null);
        },
        overRow: (event, node) => {
            if (!dragging) return;
            event.stopPropagation();
            setHoverId(node.id);
            const rect = event.currentTarget.getBoundingClientRect();
            const fraction = rect.height > 0 ? (event.clientY - rect.top) / rect.height : 0.5;
            const draggedKind = pageKind(dragging);
            let zone: DropZone | null;
            if (node.type === 'folder') {
                zone = draggedKind === 'folder' ? (fraction < 0.25 ? 'before' : fraction > 0.75 ? 'after' : 'inside') : 'inside';
            } else {
                zone = pageKind(node) === draggedKind ? (fraction < 0.5 ? 'before' : 'after') : null;
            }
            const next = zone && node.id !== dragging.id ? resolve(dragging, node.id, zone) : null;
            updateTarget(next);
            if (next) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
            } else {
                event.dataTransfer.dropEffect = 'none';
            }
        },
        overRoot: (event) => {
            if (!dragging) return;
            event.stopPropagation();
            setHoverId(null);
            const next = resolve(dragging, null, 'inside');
            updateTarget(next);
            if (next) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
            }
        },
        leave: (event, nodeId) => {
            const related = event.relatedTarget as Node | null;
            if (related && event.currentTarget.contains(related)) return;
            if (targetRef.current?.nodeId === nodeId) updateTarget(null);
        },
        drop: (event) => {
            event.preventDefault();
            event.stopPropagation();
            const drop = targetRef.current;
            const node = dragging;
            setDragging(null);
            setHoverId(null);
            updateTarget(null);
            if (!drop || !node) return;
            if (drop.parentChanges) setPending({ node, placement: drop.placement, path: drop.path });
            else void save(node, drop.placement, false);
        },
        openMove: (node) => setMoveItem(node),
    }), [enabled, dragging, target, hoverId, resolve, save, updateTarget]);

    const pendingLabel = pending ? moveItemLabel(pending.node) : 'page';
    const dialogs = (
        <>
            <AlertDialog open={!!pending} onOpenChange={(open) => { if (!open) setPending(null); }}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Move this {pendingLabel}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {pending && (pending.placement.parentId === null
                                ? <>Move “{pending.node.name}” to the top level of {pending.path}?</>
                                : <>Move “{pending.node.name}” into {pending.path}?</>)}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                if (pending) void save(pending.node, pending.placement, true);
                                setPending(null);
                            }}
                        >
                            Move
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            {enabled && (
                <MovePageDialog
                    open={!!moveItem}
                    onOpenChange={(open) => { if (!open) setMoveItem(null); }}
                    workspaceSlug={workspace.slug}
                    project={{ name: project.name, slug: project.slug, pages: tree }}
                    item={moveItem}
                />
            )}
        </>
    );

    return { tree, value, dialogs, Provider: PageTreeDndContext.Provider };
}

/**
 * The header line of a page / whiteboard / folder in the sidebar: grip (drag source), drop zones
 * and the drop indicator.
 */
export function PageTreeRow({ page, level, children }: { page: PageType; level: number; children: React.ReactNode }) {
    const dnd = usePageTreeDnd();
    const rowRef = useRef<HTMLDivElement>(null);
    if (!dnd) return <>{children}</>;

    const enableDrag = () => { if (rowRef.current) rowRef.current.draggable = true; };
    const disableDrag = () => { if (rowRef.current) rowRef.current.draggable = false; };
    const target = dnd.target?.nodeId === page.id ? dnd.target : null;
    const isDragged = dnd.dragging?.id === page.id;
    const indent = level * 12 + 8;

    return (
        <div
            ref={rowRef}
            data-page-row={page.id}
            data-drop-zone={target?.zone}
            className={cn(
                'relative group/drag rounded-md',
                isDragged && 'opacity-50',
                target?.zone === 'inside' && 'bg-primary/10 ring-2 ring-inset ring-primary',
            )}
            onDragStart={(event) => dnd.startDrag(event, page)}
            onDragEnd={() => { disableDrag(); dnd.endDrag(); }}
            onDragOver={(event) => dnd.overRow(event, page)}
            onDragLeave={(event) => dnd.leave(event, page.id)}
            onDrop={(event) => dnd.drop(event)}
        >
            {dnd.enabled && (
                <div
                    className="absolute left-0.5 top-1/2 z-10 -translate-y-1/2 cursor-grab select-none opacity-0 group-hover/drag:opacity-100"
                    title="Drag to move"
                    aria-hidden="true"
                    data-drag-handle
                    onMouseDown={enableDrag}
                    onMouseUp={disableDrag}
                >
                    <GripVertical className="size-3 text-muted-foreground/40" />
                </div>
            )}
            {children}
            {(target?.zone === 'before' || target?.zone === 'after') && (
                <div
                    role="presentation"
                    data-testid="page-drop-line"
                    className={cn('pointer-events-none absolute right-1 z-20 h-0.5 rounded-full bg-primary', target.zone === 'before' ? '-top-px' : '-bottom-px')}
                    style={{ left: `${indent}px` }}
                >
                    <span className="absolute -left-1 -top-[3px] size-2 rounded-full border-2 border-primary bg-background" />
                </div>
            )}
            {target && (target.zone === 'inside' || target.parentChanges) && <DropLabel target={target} />}
        </div>
    );
}

function DropLabel({ target }: { target: DropTarget }) {
    return (
        <div
            role="status"
            data-testid="page-drop-label"
            className="pointer-events-none absolute left-6 right-1 top-full z-30 mt-0.5 truncate rounded bg-primary px-1.5 py-0.5 text-[11px] font-medium text-primary-foreground shadow"
        >
            {target.zone === 'inside' ? 'Move into ' : 'Move to '}{target.nodeId === null ? `${target.path} (top level)` : target.path}
        </div>
    );
}

/** A top-level group header ("Pages", "Folders", …) is a drop zone for "move to the top level". */
export function RootDropZone({ children }: { children: React.ReactNode }) {
    const dnd = usePageTreeDnd();
    if (!dnd) return <>{children}</>;
    const target = dnd.target?.nodeId === null ? dnd.target : null;

    return (
        <div
            className={cn('relative rounded-md', target && 'bg-primary/10 ring-2 ring-inset ring-primary')}
            data-root-drop
            onDragOver={(event) => dnd.overRoot(event)}
            onDragLeave={(event) => dnd.leave(event, null)}
            onDrop={(event) => dnd.drop(event)}
        >
            {children}
            {target && <DropLabel target={target} />}
        </div>
    );
}
