import React, { createContext, useContext, useMemo, useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import { ExternalLink, Files, FolderInput, Link2, Loader2, MoreHorizontal, PanelTopOpen, Pencil, Trash2 } from 'lucide-react';
import type { Page as PageType, Workspace } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuSeparator, ContextMenuTrigger } from '@/components/ui/context-menu';
import { moveItemLabel } from '@/components/page/MovePageDialog';
import { usePageTreeDnd } from '@/components/sidebar/PageTreeDnd';

/*
 * The menu of a doc / whiteboard / folder row in the sidebar (GitHub #155): on the row's ⋯ and on
 * right-click. Same actions, confirmations, endpoints and permissions as the page's own ⋯ menu
 * (Pages/Pages/Show.tsx): Rename / Duplicate / Move… for people who may edit pages
 * (`can_edit_pages`), Delete for `can_delete` (PagePolicy::delete, marked per page by
 * HandleInertiaRequests). Everything else is for anyone who sees the row.
 */

interface MenuProject {
    id: number;
    name: string;
    slug: string;
    can_edit_pages?: boolean;
}

interface PageItemMenuValue {
    canEdit: boolean;
    pageUrl: (page: PageType) => string;
    openRename: (page: PageType) => void;
    duplicate: (page: PageType) => void;
    openDelete: (page: PageType) => void;
}

const PageItemMenuContext = createContext<PageItemMenuValue | null>(null);

function containsUuid(page: PageType, uuid: string): boolean {
    return page.uuid === uuid || (page.children ?? []).some((child) => containsUuid(child, uuid));
}

function firstError(error: unknown, fallback: string): string {
    const response = axios.isAxiosError(error) ? error.response : undefined;
    if (response?.status === 403) return 'You can\'t change this item.';
    const errors = response?.data?.errors as Record<string, string[]> | undefined;
    return (errors ? Object.values(errors)[0]?.[0] : undefined) ?? response?.data?.message ?? fallback;
}

/**
 * State of one project's sidebar row menus: the context value and the dialogs (rename, duplicate
 * with subpages, delete confirmation) to mount once.
 */
export function usePageItemMenuState(workspace: Workspace, project: MenuProject, currentPageUuid?: string | null) {
    const [renaming, setRenaming] = useState<PageType | null>(null);
    const [name, setName] = useState('');
    const [renameError, setRenameError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [duplicating, setDuplicating] = useState<PageType | null>(null);
    const [deleting, setDeleting] = useState<PageType | null>(null);
    const canEdit = !!project.can_edit_pages;
    const params = (page: PageType) => [workspace.slug, project.slug, page.uuid];

    const runDuplicate = (page: PageType, withSubpages: boolean) => {
        // like the page menu: the copy opens once it is made
        router.post(route('pages.duplicate', params(page)), { with_subpages: withSubpages }, {
            onSuccess: () => setDuplicating(null),
        });
    };

    const value = useMemo<PageItemMenuValue>(() => ({
        canEdit,
        pageUrl: (page) => route('pages.show', [workspace.slug, project.slug, page.uuid]),
        openRename: (page) => {
            setRenaming(page);
            setName(page.name);
            setRenameError(null);
            setSaving(false);
        },
        duplicate: (page) => {
            if ((page.children?.length ?? 0) > 0) setDuplicating(page);
            else runDuplicate(page, false);
        },
        openDelete: (page) => setDeleting(page),
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }), [canEdit, workspace.slug, project.slug]);

    const submitRename = async (event: FormEvent) => {
        event.preventDefault();
        if (!renaming) return;
        const next = name.trim();
        if (next === '') {
            setRenameError('Enter a name.');
            return;
        }
        if (next === renaming.name) {
            setRenaming(null);
            return;
        }
        setSaving(true);
        try {
            const { data } = await axios.put<PageType>(route('pages.update', params(renaming)), { name: next });
            setRenaming(null);
            // "review required" pages keep a new title in their draft until it is published
            if (data.name !== next) toast.success('The new name is saved in the draft. It shows once the page is published.');
            else toast.success(`Renamed to "${next}"`);
            if (currentPageUuid === renaming.uuid) {
                // the open page shows the new title: load it again (its editor saves what's pending on the way)
                router.visit(window.location.href, { preserveScroll: true });
            } else {
                router.reload({ only: ['currentWorkspace', 'breadcrumbs'] });
            }
        } catch (error) {
            setRenameError(firstError(error, 'The item could not be renamed. Please try again.'));
        } finally {
            setSaving(false);
        }
    };

    const confirmDelete = async () => {
        const page = deleting;
        setDeleting(null);
        if (!page) return;
        const label = moveItemLabel(page);
        if (currentPageUuid && containsUuid(page, currentPageUuid)) {
            // the open page goes away: the server sends us to the parent or the project, like the page menu
            router.delete(route('pages.destroy', params(page)), {
                onSuccess: () => toast.success(`Deleted "${page.name}"`),
            });
            return;
        }
        try {
            await axios.delete(route('pages.destroy', params(page)));
            toast.success(`Deleted "${page.name}"`);
            router.reload();
        } catch (error) {
            toast.error(firstError(error, `The ${label} could not be deleted. Please try again.`));
        }
    };

    const childCount = deleting?.children?.length ?? 0;
    const dialogs = (
        <>
            <Dialog open={!!renaming} onOpenChange={(open) => { if (!open) setRenaming(null); }}>
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submitRename}>
                        <DialogHeader>
                            <DialogTitle>Rename {renaming ? moveItemLabel(renaming) : 'page'}</DialogTitle>
                            <DialogDescription>Give “{renaming?.name}” a new name.</DialogDescription>
                        </DialogHeader>
                        <div className="space-y-2 py-4">
                            <Label htmlFor="sidebar-rename-input">Name</Label>
                            <Input
                                id="sidebar-rename-input"
                                value={name}
                                maxLength={255}
                                autoFocus
                                onFocus={(event) => event.currentTarget.select()}
                                onChange={(event) => { setName(event.target.value); setRenameError(null); }}
                                aria-invalid={!!renameError}
                            />
                            {renameError && <p className="text-sm text-destructive">{renameError}</p>}
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setRenaming(null)}>Cancel</Button>
                            <Button type="submit" disabled={saving}>
                                {saving && <Loader2 className="mr-2 size-4 animate-spin" />}
                                Rename
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={!!duplicating} onOpenChange={(open) => { if (!open) setDuplicating(null); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Duplicate {duplicating ? moveItemLabel(duplicating) : 'page'}</DialogTitle>
                        <DialogDescription>
                            You are about to duplicate <strong>{duplicating?.name}</strong>. It has {duplicating?.children?.length} subpage(s).
                        </DialogDescription>
                    </DialogHeader>
                    <div className="mt-4 flex flex-col gap-3">
                        <Button variant="outline" onClick={() => duplicating && runDuplicate(duplicating, false)}>
                            Duplicate {duplicating ? moveItemLabel(duplicating) : 'page'} only
                        </Button>
                        <Button onClick={() => duplicating && runDuplicate(duplicating, true)}>
                            Duplicate with subpages
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(open) => { if (!open) setDeleting(null); }}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete {deleting ? moveItemLabel(deleting) : 'page'}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete “{deleting?.name}”?
                            {childCount > 0 && (
                                <span className="mt-2 block font-medium text-destructive">
                                    This will also delete all {childCount} subpage(s) inside.
                                </span>
                            )}
                            {' '}This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => void confirmDelete()}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );

    return { value, dialogs, Provider: PageItemMenuContext.Provider };
}

interface Action {
    key: string;
    label: string;
    icon: React.ComponentType<{ className?: string }>;
    onSelect: () => void;
    destructive?: boolean;
    /** a separator goes above this entry */
    group?: boolean;
}

/** The entries the user may use on this row, in menu order. */
function useActions(page: PageType): Action[] {
    const menu = useContext(PageItemMenuContext);
    const dnd = usePageTreeDnd();
    if (!menu) return [];
    const url = menu.pageUrl(page);
    const actions: Action[] = [
        { key: 'open', label: 'Open', icon: PanelTopOpen, onSelect: () => router.visit(url) },
        { key: 'open-tab', label: 'Open in new tab', icon: ExternalLink, onSelect: () => { window.open(url, '_blank', 'noopener'); } },
        {
            key: 'copy-link',
            label: 'Copy link',
            icon: Link2,
            onSelect: () => {
                navigator.clipboard.writeText(url).then(() => toast.success('Link copied')).catch(() => toast.error('Could not copy the link'));
            },
        },
    ];
    if (menu.canEdit) {
        actions.push(
            { key: 'rename', label: 'Rename…', icon: Pencil, onSelect: () => menu.openRename(page), group: true },
            { key: 'duplicate', label: 'Duplicate', icon: Files, onSelect: () => menu.duplicate(page) },
        );
        if (dnd?.enabled) actions.push({ key: 'move', label: 'Move…', icon: FolderInput, onSelect: () => dnd.openMove(page) });
    }
    if (page.can_delete) {
        actions.push({ key: 'delete', label: 'Delete', icon: Trash2, onSelect: () => menu.openDelete(page), destructive: true, group: true });
    }
    return actions;
}

/** "⋯" on a sidebar doc / whiteboard / folder row. */
export function PageRowMenu({ page }: { page: PageType }) {
    const actions = useActions(page);
    if (actions.length === 0) return null;
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-6 shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100 [@media(hover:none)]:opacity-60"
                    aria-label={`More actions for ${page.name}`}
                    onClick={(e) => e.stopPropagation()}
                >
                    <MoreHorizontal className="size-3.5" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-48" onClick={(e) => e.stopPropagation()}>
                {actions.map(({ key, label, icon: Icon, onSelect, destructive, group }, index) => (
                    <React.Fragment key={key}>
                        {group && index > 0 && <DropdownMenuSeparator />}
                        <DropdownMenuItem onSelect={onSelect} className={destructive ? 'text-destructive focus:text-destructive' : undefined}>
                            <Icon className="mr-2 size-4" />
                            {label}
                        </DropdownMenuItem>
                    </React.Fragment>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Right-click on a sidebar doc / whiteboard / folder row opens the same menu as its ⋯. Touch
 * screens keep the ⋯ only: a long press there scrolls the sidebar or opens the browser's link menu.
 */
export function PageRowContextMenu({ page, children }: { page: PageType; children: React.ReactElement }) {
    const actions = useActions(page);
    const coarsePointer = useMemo(() => typeof window !== 'undefined' && !!window.matchMedia?.('(pointer: coarse)').matches, []);
    if (actions.length === 0) return children;
    return (
        <ContextMenu>
            <ContextMenuTrigger asChild disabled={coarsePointer}>{children}</ContextMenuTrigger>
            <ContextMenuContent className="w-48" aria-label={`Actions for ${page.name}`}>
                {actions.map(({ key, label, icon: Icon, onSelect, destructive, group }, index) => (
                    <React.Fragment key={key}>
                        {group && index > 0 && <ContextMenuSeparator />}
                        <ContextMenuItem onSelect={onSelect} className={destructive ? 'text-destructive focus:text-destructive' : undefined}>
                            <Icon className="mr-2 size-4" />
                            {label}
                        </ContextMenuItem>
                    </React.Fragment>
                ))}
            </ContextMenuContent>
        </ContextMenu>
    );
}
