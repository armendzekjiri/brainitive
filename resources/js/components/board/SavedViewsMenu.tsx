import { FormEvent, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { Bookmark, Check, ChevronDown, Copy, MoreHorizontal, Pencil, Star, Trash2, Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { SavedBoardView } from '@/types';

/** The keys that filter issues (the rest — layout, sort, hidden columns — only change how they show). */
const FILTER_KEY = /^(q|assignee|status|labels|priority|type|quick|f\d+)$/;

/** Same params? (id lists compared as sets, empty values ignored) */
export function sameViewParams(a: Record<string, string>, b: Record<string, string>): boolean {
    const norm = (p: Record<string, string>) => {
        const out: Record<string, string> = {};
        for (const [key, value] of Object.entries(p)) {
            const v = (value ?? '').trim();
            if (!v || (key === 'view' && v === 'kanban')) continue;
            out[key] = /^[\d,]+$/.test(v) ? v.split(',').filter(Boolean).sort((x, y) => Number(x) - Number(y)).join(',') : v;
        }
        return JSON.stringify(Object.keys(out).sort().map((k) => [k, out[k]]));
    };
    return norm(a) === norm(b);
}

interface Props {
    views: SavedBoardView[];
    activeViewId: number | null;
    defaultViewId: number | null;
    canShare: boolean;
    /** The board's current view params (filters, sort, layout, hidden list columns). */
    currentParams: Record<string, string>;
    routeParams: [string, string, string];
}

/**
 * Saved board views (BRAI-78): open one, save the current setup as a new view or into the open
 * one, rename / duplicate / share / delete, and pick a personal default for this board.
 */
export default function SavedViewsMenu({ views, activeViewId, defaultViewId, canShare, currentParams, routeParams }: Props) {
    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [name, setName] = useState('');
    const [shared, setShared] = useState(false);
    const [renaming, setRenaming] = useState<number | null>(null);
    const [renameValue, setRenameValue] = useState('');
    const renameInput = useRef<HTMLInputElement>(null);
    const startingRename = useRef(false);

    const active = views.find((v) => v.id === activeViewId) ?? null;
    const dirty = active ? !sameViewParams(active.params, currentParams) : false;
    const mine = views.filter((v) => !v.is_shared);
    const team = views.filter((v) => v.is_shared);
    const hasFilters = Object.entries(currentParams).some(([k, v]) => v && FILTER_KEY.test(k));

    const fail = (errors: Record<string, string>) => toast.error(Object.values(errors)[0] ?? 'Something went wrong.');
    const viewRoute = (routeName: string, view: SavedBoardView) => route(routeName, [...routeParams, view.id]);

    const openView = (view: SavedBoardView) => {
        setOpen(false);
        router.get(viewRoute('boards.views.open', view), {}, { preserveScroll: true });
    };
    const showAll = () => {
        setOpen(false);
        router.get(route('boards.show', routeParams), { bv: 0 }, { preserveScroll: true });
    };

    const saveNew = (e: FormEvent) => {
        e.preventDefault();
        if (!name.trim()) return;
        router.post(route('boards.views.store', routeParams), { name: name.trim(), params: currentParams, is_shared: shared }, {
            preserveScroll: true,
            // the board reloads from the saved view's URL (POSTs keep component state by default)
            preserveState: false,
            onSuccess: () => {
                setSaving(false);
                setName('');
                setShared(false);
                setOpen(false);
                toast.success('View saved');
            },
            onError: fail,
        });
    };

    const saveChanges = () => {
        if (!active) return;
        router.patch(viewRoute('boards.views.update', active), { params: currentParams }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success(`“${active.name}” updated`),
            onError: fail,
        });
    };

    const update = (view: SavedBoardView, data: Record<string, string | boolean>, message: string) =>
        router.patch(viewRoute('boards.views.update', view), data, { preserveScroll: true, preserveState: true, onSuccess: () => toast.success(message), onError: fail });

    const setDefault = (view: SavedBoardView | null) =>
        router.post(route('boards.views.default', routeParams), { board_view_id: view?.id ?? null }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success(view ? `“${view.name}” opens by default` : 'Default cleared'),
            onError: fail,
        });

    const remove = (view: SavedBoardView) => {
        if (!window.confirm(`Delete the view “${view.name}”?${view.is_shared ? ' It disappears for the whole team.' : ''}`)) return;
        router.delete(viewRoute('boards.views.destroy', view), {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => toast.success('View deleted'),
            onError: fail,
        });
    };

    const row = (view: SavedBoardView) => (
        <div key={view.id} className={cn('group flex items-center gap-1 rounded-md pr-1', view.id === activeViewId && 'bg-accent')}>
            {renaming === view.id ? (
                <form
                    className="flex flex-1 items-center gap-1 p-1"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (renameValue.trim()) update(view, { name: renameValue.trim() }, 'View renamed');
                        setRenaming(null);
                    }}
                >
                    <Input
                        ref={renameInput}
                        autoFocus
                        value={renameValue}
                        onChange={(e) => setRenameValue(e.target.value)}
                        maxLength={80}
                        className="h-7 text-sm"
                        aria-label="View name"
                    />
                </form>
            ) : (
                <button type="button" onClick={() => openView(view)} className="flex min-w-0 flex-1 items-center gap-2 px-2 py-1.5 text-left text-sm">
                    {view.id === activeViewId ? <Check className="size-3.5 shrink-0 text-primary" /> : <span className="size-3.5 shrink-0" />}
                    <span className="min-w-0 flex-1 truncate">{view.name}</span>
                    {view.id === defaultViewId && <Star className="size-3 shrink-0 fill-amber-400 text-amber-400" aria-label="Your default" />}
                    {view.is_shared && !view.is_own && view.owner && <span className="shrink-0 text-[10px] text-muted-foreground">{view.owner}</span>}
                </button>
            )}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button type="button" className="rounded p-1 text-muted-foreground hover:bg-muted focus-visible:opacity-100 group-hover:opacity-100 data-[state=open]:opacity-100 md:opacity-0" aria-label={`Options for ${view.name}`}>
                        <MoreHorizontal className="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    className="w-52"
                    onCloseAutoFocus={(e) => {
                        // Rename: keep the focus in the name field instead of the "…" button
                        if (!startingRename.current) return;
                        startingRename.current = false;
                        e.preventDefault();
                        renameInput.current?.focus();
                    }}
                >
                    {view.id === defaultViewId ? (
                        <DropdownMenuItem onSelect={() => setDefault(null)}>
                            <Star className="mr-2 size-4" /> Don't open by default
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => setDefault(view)}>
                            <Star className="mr-2 size-4" /> Open by default
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem
                        onSelect={() => router.post(viewRoute('boards.views.duplicate', view), {}, {
                            preserveScroll: true,
                            preserveState: false,
                            onSuccess: () => toast.success(`Copied to “${view.name} (copy)”`),
                            onError: fail,
                        })}
                    >
                        <Copy className="mr-2 size-4" /> Duplicate
                    </DropdownMenuItem>
                    {view.can_edit && (
                        <>
                            <DropdownMenuItem
                                onSelect={() => {
                                    startingRename.current = true;
                                    setRenaming(view.id);
                                    setRenameValue(view.name);
                                }}
                            >
                                <Pencil className="mr-2 size-4" /> Rename
                            </DropdownMenuItem>
                            {canShare && (
                                <DropdownMenuItem onSelect={() => update(view, { is_shared: !view.is_shared }, view.is_shared ? 'Now a personal view' : 'Shared with the team')}>
                                    <Users className="mr-2 size-4" /> {view.is_shared ? 'Make personal' : 'Share with team'}
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem className="text-destructive focus:text-destructive" onSelect={() => remove(view)}>
                                <Trash2 className="mr-2 size-4" /> Delete
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );

    return (
        <div className="flex items-center gap-1.5">
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button variant="outline" size="sm" className="h-8 max-w-56 gap-1.5 text-[13px]">
                        <Bookmark className="size-3.5 shrink-0" />
                        <span className="truncate">{active ? active.name : 'Views'}</span>
                        {dirty && (
                            <>
                                <span className="size-1.5 shrink-0 rounded-full bg-amber-500" aria-hidden />
                                <span className="sr-only">(unsaved changes)</span>
                            </>
                        )}
                        <ChevronDown className="size-3.5 shrink-0 opacity-60" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    className="w-72 p-1.5"
                    onEscapeKeyDown={(e) => {
                        // Escape in the rename field cancels the rename, not the whole menu
                        if (renaming === null) return;
                        e.preventDefault();
                        setRenaming(null);
                    }}
                >
                    <button type="button" onClick={showAll} className={cn('flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted', !active && !hasFilters && 'bg-accent')}>
                        {!active && !hasFilters ? <Check className="size-3.5 text-primary" /> : <span className="size-3.5" />}
                        All issues
                    </button>

                    {mine.length > 0 && <p className="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">My views</p>}
                    {mine.map(row)}
                    {team.length > 0 && <p className="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Team views</p>}
                    {team.map(row)}

                    <div className="mt-1.5 border-t pt-1.5">
                        {saving ? (
                            <form onSubmit={saveNew} className="space-y-2 p-1">
                                <Input autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder="View name, e.g. My open bugs" maxLength={80} className="h-8 text-sm" />
                                {canShare && (
                                    <label className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <input type="checkbox" checked={shared} onChange={(e) => setShared(e.target.checked)} />
                                        Share with everyone on this board
                                    </label>
                                )}
                                <div className="flex justify-end gap-1.5">
                                    <Button type="button" variant="ghost" size="sm" className="h-7" onClick={() => setSaving(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" size="sm" className="h-7" disabled={!name.trim()}>
                                        Save view
                                    </Button>
                                </div>
                            </form>
                        ) : (
                            <button type="button" onClick={() => setSaving(true)} className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-primary hover:bg-muted">
                                <Bookmark className="size-3.5" /> Save current view as…
                            </button>
                        )}
                    </div>
                </PopoverContent>
            </Popover>

            {active && dirty && active.can_edit && (
                <Button size="sm" variant="ghost" className="h-8 text-[13px] text-amber-600 hover:text-amber-700" onClick={saveChanges}>
                    Save changes
                </Button>
            )}
        </div>
    );
}
