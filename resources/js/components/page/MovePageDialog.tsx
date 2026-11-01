import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import { Folder, FolderInput, Home, Loader2, Search } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PageIcon from '@/components/page/PageIcon';
import { cn } from '@/lib/utils';
import { findWithAncestors, folderDestinations, isSelfOrDescendant, type MovableNode, type MovePlacement } from '@/lib/pageTree';

/** What a move needs to know about the item. */
export interface MoveItem {
    id: number;
    uuid: string;
    name: string;
    type: string;
    is_whiteboard?: boolean;
    parent_id?: number | null;
}

export interface MoveProject {
    name: string;
    slug: string;
    pages?: MovableNode[];
}

export function moveItemLabel(item: Pick<MoveItem, 'type' | 'is_whiteboard'>): string {
    if (item.type === 'folder') return 'folder';
    return item.is_whiteboard ? 'whiteboard' : 'page';
}

/**
 * Save a move through `pages.move` (BRAI-431) and refresh the sidebar (+ breadcrumbs on a page).
 * Resolves with the destination path; rejects with a readable message.
 */
export async function requestPageMove(workspaceSlug: string, projectSlug: string, item: Pick<MoveItem, 'uuid'>, placement: MovePlacement): Promise<string> {
    try {
        const { data } = await axios.post<{ path: string }>(route('pages.move', [workspaceSlug, projectSlug, item.uuid]), {
            parent_id: placement.parentId,
            before_id: placement.beforeId ?? null,
            after_id: placement.afterId ?? null,
        });
        router.reload({ only: ['currentWorkspace', 'breadcrumbs'] });
        return data.path;
    } catch (error) {
        const response = axios.isAxiosError(error) ? error.response : undefined;
        const errors = response?.data?.errors as Record<string, string[]> | undefined;
        const first = errors ? Object.values(errors)[0]?.[0] : undefined;
        if (response?.status === 403) throw new Error('You can\'t move this item.');
        throw new Error(first ?? response?.data?.message ?? 'The item could not be moved. Please try again.');
    }
}

interface Option {
    key: string;
    parentId: number | null;
    label: string;
    icon?: string | null;
    depth: number;
    path: string;
    disabledReason: string | null;
}

/**
 * "Move…" (BRAI-431): pick a folder of the project, or its top level, for a page / whiteboard /
 * folder. A combobox over a flat folder tree: type to filter, ↑ ↓ to choose, Enter to move.
 * The item itself, everything inside it and its current place can't be chosen.
 */
export function MovePageDialog({
    open,
    onOpenChange,
    workspaceSlug,
    project,
    item,
    onMoved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspaceSlug: string;
    project: MoveProject;
    item: MoveItem | null;
    onMoved?: (path: string) => void;
}) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const listRef = useRef<HTMLDivElement>(null);
    const tree = useMemo(() => project.pages ?? [], [project.pages]);

    useEffect(() => {
        if (open) {
            setQuery('');
            setSelected(null);
            setSaving(false);
        }
    }, [open, item?.id]);

    const options = useMemo<Option[]>(() => {
        if (!item) return [];
        const located = findWithAncestors(tree, item.id);
        const itemNode: MovableNode = located?.node ?? { ...item, children: [] };
        const currentParentId = located ? (located.ancestors.at(-1)?.id ?? null) : (item.parent_id ?? null);

        const reasonFor = (parentId: number | null): string | null => {
            if (parentId === item.id) return 'This item';
            if (parentId !== null && isSelfOrDescendant(itemNode, parentId)) return `Inside this ${moveItemLabel(item)}`;
            if (parentId === currentParentId) return 'Current location';
            return null;
        };

        const all: Option[] = [
            { key: 'root', parentId: null, label: 'Top level', depth: 0, path: project.name, disabledReason: reasonFor(null) },
            ...folderDestinations(tree).map((folder) => ({
                key: `folder-${folder.id}`,
                parentId: folder.id,
                label: folder.name,
                icon: folder.icon,
                depth: folder.depth + 1,
                path: [project.name, ...folder.trail].join(' / '),
                disabledReason: reasonFor(folder.id),
            })),
        ];

        const q = query.trim().toLowerCase();
        if (!q) return all;
        return all
            .filter((option) => option.path.toLowerCase().includes(q) || option.label.toLowerCase().includes(q))
            .map((option) => ({ ...option, depth: 0 }));
    }, [item, tree, project.name, query]);

    const enabled = options.filter((option) => !option.disabledReason);
    const selectedOption = options.find((option) => option.key === selected && !option.disabledReason) ?? null;

    useEffect(() => {
        if (!selected) return;
        listRef.current?.querySelector(`[data-option-key="${selected}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [selected]);

    const step = (delta: number) => {
        if (enabled.length === 0) return;
        const index = enabled.findIndex((option) => option.key === selected);
        const next = index === -1 ? (delta > 0 ? 0 : enabled.length - 1) : Math.min(enabled.length - 1, Math.max(0, index + delta));
        setSelected(enabled[next].key);
    };

    const submit = async (option: Option | null = selectedOption) => {
        if (!item || !option || option.disabledReason || saving) return;
        setSaving(true);
        try {
            const path = await requestPageMove(workspaceSlug, project.slug, item, { parentId: option.parentId });
            toast.success(`Moved "${item.name}" to ${path}`);
            onOpenChange(false);
            onMoved?.(path);
        } catch (error) {
            toast.error((error as Error).message);
            setSaving(false);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') { event.preventDefault(); step(1); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); step(-1); }
        else if (event.key === 'Home' && enabled.length) { event.preventDefault(); setSelected(enabled[0].key); }
        else if (event.key === 'End' && enabled.length) { event.preventDefault(); setSelected(enabled[enabled.length - 1].key); }
        else if (event.key === 'Enter') { event.preventDefault(); void submit(); }
    };

    const listId = 'move-page-destinations';
    const kind = item ? moveItemLabel(item) : 'page';

    return (
        <Dialog open={open} onOpenChange={(next) => { if (!saving) onOpenChange(next); }}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <FolderInput className="size-5 text-muted-foreground" />
                        <span className="min-w-0 truncate">Move “{item?.name}”</span>
                    </DialogTitle>
                    <DialogDescription>Choose where this {kind} should go in {project.name}.</DialogDescription>
                </DialogHeader>

                <div className="relative">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        autoFocus
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={onKeyDown}
                        placeholder="Search folders"
                        className="pl-8"
                        role="combobox"
                        aria-label="Search folders"
                        aria-expanded="true"
                        aria-controls={listId}
                        aria-activedescendant={selectedOption ? `${listId}-${selectedOption.key}` : undefined}
                        aria-autocomplete="list"
                    />
                </div>

                <div
                    ref={listRef}
                    id={listId}
                    role="listbox"
                    aria-label="Destinations"
                    className="max-h-72 min-h-24 overflow-y-auto rounded-md border p-1"
                >
                    {options.length === 0 && <p className="px-2 py-6 text-center text-sm text-muted-foreground">No folder matches “{query}”.</p>}
                    {options.map((option) => {
                        const isSelected = option.key === selectedOption?.key;
                        const disabled = !!option.disabledReason;
                        return (
                            <div
                                key={option.key}
                                id={`${listId}-${option.key}`}
                                data-option-key={option.key}
                                role="option"
                                aria-selected={isSelected}
                                aria-disabled={disabled}
                                title={option.path}
                                onClick={() => { if (!disabled) setSelected(option.key); }}
                                onDoubleClick={() => { if (!disabled) void submit(option); }}
                                className={cn(
                                    'flex min-w-0 cursor-pointer select-none items-center gap-2 rounded px-2 py-1.5 text-sm',
                                    isSelected ? 'bg-primary text-primary-foreground' : 'hover:bg-accent',
                                    disabled && 'cursor-not-allowed opacity-55 hover:bg-transparent',
                                )}
                                style={{ paddingLeft: `${option.depth * 14 + 8}px` }}
                            >
                                {option.parentId === null
                                    ? <Home className="size-4 shrink-0" />
                                    : option.icon
                                        ? <PageIcon icon={option.icon} className="text-sm" imageClassName="size-4" />
                                        : <Folder className="size-4 shrink-0" />}
                                <span className="min-w-0 flex-1 truncate">
                                    {option.label}
                                    {query.trim() && option.parentId !== null && (
                                        <span className={cn('ml-1.5 text-xs', isSelected ? 'text-primary-foreground/80' : 'text-muted-foreground')}>
                                            {option.path.split(' / ').slice(0, -1).join(' / ')}
                                        </span>
                                    )}
                                </span>
                                {option.disabledReason && (
                                    <span className="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">{option.disabledReason}</span>
                                )}
                            </div>
                        );
                    })}
                </div>

                <div className="rounded-md bg-muted/60 px-3 py-2 text-sm" aria-live="polite">
                    <span className="text-muted-foreground">Destination: </span>
                    <span className="font-medium" data-testid="move-destination-path">{selectedOption ? selectedOption.path : 'Choose a folder'}</span>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                    <Button onClick={() => void submit()} disabled={!selectedOption || saving}>
                        {saving && <Loader2 className="mr-2 size-4 animate-spin" />}
                        Move
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

