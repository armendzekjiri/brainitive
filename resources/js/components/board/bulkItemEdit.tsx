import { useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import type { Board, Item, ItemPriority, ItemType, Label as LabelType, PageProps, Project, User, Workspace } from '@/types';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';

/**
 * Editing several issues at once (BRAI-108): shared by the list view's bulk bar (`BulkEditBar`) and
 * the right-click menu on rows and cards (`ItemContextMenu`, GitHub #143). Both send the same
 * requests (`PATCH|DELETE boards/{board}/items/bulk`), so the server checks every issue's policy.
 */

/** What one bulk request may change (BoardBulkItemController). */
export interface BulkChanges {
    column_id?: number;
    assigned_to?: number | null;
    priority_id?: number;
    item_type_id?: number;
    sprint_id?: number | null;
    due_date?: string | null;
    add_label_ids?: number[];
    remove_label_ids?: number[];
}

/** What the bulk bar and the right-click menu need besides the board (null for viewers). */
export interface BulkEditOptions {
    users: User[];
    priorities: ItemPriority[];
    itemTypes: ItemType[];
    labels: LabelType[];
    /** Scrum boards: the running + planned sprints; null on Kanban boards. */
    sprints: { id: number; name: string }[] | null;
    /** May delete issues created by others (ItemPolicy::delete). */
    canDeleteAnyItem: boolean;
    currentUserId: number;
}

export const issueNoun = (count: number) => (count === 1 ? 'issue' : 'issues');

/** ItemPolicy::delete on the client: project admins, or the issue's creator. */
export const canDeleteItem = (item: Item, options: Pick<BulkEditOptions, 'canDeleteAnyItem' | 'currentUserId'>) =>
    options.canDeleteAnyItem || item.created_by === options.currentUserId;

/** The bulk requests with their toasts; `busy` while one is on the way. */
export function useBulkItemEdit(workspace: Workspace, project: Project, board: Board) {
    const [busy, setBusy] = useState(false);
    const params = [workspace.slug, project.slug, board.slug];

    const options = (fallback: string, after?: () => void) => ({
        preserveScroll: true,
        preserveState: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onSuccess: (page: { props: unknown }) => {
            toast.success((page.props as PageProps).flash?.success ?? fallback);
            after?.();
        },
        onError: (errors: Record<string, string>) => toast.error(Object.values(errors)[0] ?? 'Could not update the selected issues.'),
    });

    /** `what` names the change for the fallback toast ("Moved to Done" → "Moved to Done for 3 issues."). */
    const update = (items: Item[], changes: BulkChanges, what: string) =>
        router.patch(
            route('boards.items.bulk.update', params),
            { item_ids: items.map((item) => item.id), changes: { ...changes } },
            options(`${what} for ${items.length} ${issueNoun(items.length)}.`),
        );

    const destroy = (items: Item[], after?: () => void) =>
        router.delete(route('boards.items.bulk.destroy', params), {
            data: { item_ids: items.map((item) => item.id) },
            ...options(`Deleted ${items.length} ${issueNoun(items.length)}.`, after),
        });

    return { busy, update, destroy };
}

/** "Delete 3 issues?" — the confirmation of a bulk delete. */
export function BulkDeleteDialog({ open, onOpenChange, items, onConfirm }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    items: Item[];
    onConfirm: () => void;
}) {
    const count = items.length;
    const noun = issueNoun(count);
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete {count} {noun}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        {items.slice(0, 5).map((item) => item.key).join(', ')}{count > 5 ? ` and ${count - 5} more` : ''} will be deleted
                        together with their subtasks.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90" onClick={onConfirm}>
                        Delete {count} {noun}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
