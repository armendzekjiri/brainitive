import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, BoardColumn, Item, ItemType, ItemPriority, User, Label as LabelType } from '@/types';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { ScrollArea } from '@/components/ui/scroll-area';
import { ProjectIcon } from '@/components/ProjectIcon';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Sheet,
    SheetContent,
    SheetTrigger,
} from '@/components/ui/sheet';
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
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Plus,
    MoreHorizontal,
    User as UserIcon,
    Calendar,
    Flag,
    Search,
    Filter,
    Settings,
    CheckSquare,
    MessageSquare,
    Paperclip,
    X,
    GripVertical,
    Clock,
    Tag,
    ChevronUp,
    ChevronDown,
    Equal,
    AlertCircle,
    Bug,
    Bookmark,
    Layers,
    Square,
    ExternalLink,
    Maximize2,
    Check,
    CalendarDays,
    Upload,
    File,
    FileImage,
    FileText,
    FileSpreadsheet,
    FileArchive,
    FileCode,
    FileType,
    Trash2,
    Reply,
    Download,
    Kanban,
    List,
    GanttChart,
    ChevronLeft,
    ChevronRight,
    Pencil,
    Eye,
    Link2,
    SlidersHorizontal,
    Ban,
} from 'lucide-react';
import { useState, useRef, useEffect, DragEvent, FormEvent, useCallback, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { Comment, Attachment } from '@/types';
import { ItemTypeIcon } from '@/components/ItemTypeIcon';
import { LabelChip, PriorityIndicator, TypeBadge, AssigneeAvatar, priorityMeta, itemAccentColor } from '@/components/board/ItemPrimitives';
import { useItemDetail, ItemDetailMain, ItemDetailSidebar, ItemFilePreviewDialog, type ProjectItemLite } from '@/components/item/ItemDetail';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    board: Board & { columns: (BoardColumn & { items: Item[] })[] };
    /** Item types for this board (from controller; avoids conflict with shared itemTypes). */
    boardItemTypes?: ItemType[];
    priorities: ItemPriority[];
    projectUsers: User[];
    projectLabels: LabelType[];
    projectItems?: ProjectItemLite[];
}

// Item Card Component — redesigned (utility-first dense kanban)
function ItemCard({
    item,
    workspace,
    project,
    board,
    projectUsers,
    subtasks,
    onEdit,
    onDragStart,
    onDragEnd,
}: {
    item: Item;
    workspace: Workspace;
    project: Project;
    board: Board;
    projectUsers: User[];
    subtasks?: Item[];
    onEdit: (item: Item) => void;
    onDragStart: (e: DragEvent, item: Item) => void;
    onDragEnd: () => void;
}) {
    const dueTime = item.due_date ? new Date(String(item.due_date).split('T')[0] + 'T23:59:59').getTime() : null;
    const isOverdue = dueTime !== null && dueTime < Date.now();
    const isDueSoon = dueTime !== null && !isOverdue && dueTime - Date.now() < 2 * 86400000;
    const isDone = !!item.completed_at;
    const blocked = isBlocked(item);
    const [assignOpen, setAssignOpen] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const subtaskCount = item.children_count ?? 0;

    const assign = (userId: number | null) => {
        setAssignOpen(false);
        router.patch(
            route('boards.items.update', [workspace.slug, project.slug, board.slug, item.id]),
            { assigned_to: userId },
            { preserveScroll: true },
        );
    };

    return (
        <article
            draggable
            onDragStart={(e) => onDragStart(e, item)}
            onDragEnd={onDragEnd}
            onClick={() => onEdit(item)}
            className={cn(
                'group relative flex flex-col gap-2 cursor-pointer',
                'rounded-[4px] bg-card px-3 pt-2.5 pb-2',
                // Jira-like elevation: hairline outline + 1px drop, a touch more on hover
                'shadow-[0_1px_1px_rgba(9,30,66,0.25),0_0_1px_rgba(9,30,66,0.31)] dark:shadow-[0_0_0_1px_hsl(var(--border))]',
                'transition-colors duration-100 hover:bg-secondary/60 dark:hover:bg-secondary/40',
            )}
        >
            {/* Summary */}
            <h4 className={cn(
                'm-0 pr-5 text-[14px] font-normal leading-[1.42] text-foreground break-words line-clamp-3',
                isDone && 'text-muted-foreground',
            )}>
                {item.title}
            </h4>

            {/* Parent + labels (lozenges) */}
            {(item.parent || (item.labels && item.labels.length > 0)) && (
                <div className="flex items-center gap-1 flex-wrap">
                    {item.parent && (
                        <Link
                            href={route('boards.items.show', [workspace.slug, project.slug, board.slug, item.parent.id])}
                            onClick={(e) => e.stopPropagation()}
                            className="inline-flex items-center h-5 max-w-[150px] px-1.5 rounded-[3px] bg-primary/10 text-primary text-[11px] font-semibold hover:bg-primary/15"
                            title={`Parent: ${item.parent.key} ${item.parent.title}`}
                        >
                            <span className="truncate">{item.parent.title}</span>
                        </Link>
                    )}
                    {item.labels?.slice(0, 3).map((label) => (
                        <span key={label.id} className="inline-flex items-center h-5 max-w-[120px] px-1.5 rounded-[3px] bg-muted text-[11px] font-medium text-foreground/75">
                            <span className="truncate">{label.name}</span>
                        </span>
                    ))}
                    {(item.labels?.length ?? 0) > 3 && (
                        <span className="text-[11px] text-muted-foreground font-medium">+{(item.labels?.length ?? 0) - 3}</span>
                    )}
                </div>
            )}

            {/* Due date */}
            {item.due_date && !isDone && (
                <span className={cn(
                    'inline-flex w-fit items-center gap-1 h-5 px-1.5 rounded-[3px] border text-[11px] font-medium',
                    isOverdue ? 'border-destructive/40 bg-destructive/10 text-destructive'
                        : isDueSoon ? 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-400'
                        : 'border-border text-muted-foreground',
                )}>
                    <Calendar className="size-3" />
                    {new Date(item.due_date).toLocaleDateString('en', { month: 'short', day: 'numeric' })}
                </span>
            )}

            {/* Footer: type + key | points · priority · assignee */}
            <div className="flex items-center gap-1.5 min-h-[24px]">
                <ItemTypeIcon type={item.item_type} className="size-4 shrink-0" />
                <span className={cn('text-[12px] font-semibold text-muted-foreground tracking-tight', isDone && 'line-through')}>
                    {item.key}
                </span>
                {(item.children_count ?? 0) > 0 && (
                    <span className="inline-flex items-center gap-0.5 text-[11px] text-muted-foreground" title={`${item.children_count} subtask(s)`}>
                        <CheckSquare className="size-3" />
                        {item.children_count}
                    </span>
                )}
                {(item.comments_count ?? 0) > 0 && (
                    <span className="inline-flex items-center gap-0.5 text-[11px] text-muted-foreground" title={`${item.comments_count} comment(s)`}>
                        <MessageSquare className="size-3" />
                        {item.comments_count}
                    </span>
                )}
                {blocked && (
                    <span className="inline-flex items-center text-destructive" title="Blocked">
                        <Ban className="size-3.5" />
                    </span>
                )}

                <span className="flex-1" />

                {item.story_points != null && item.story_points > 0 && (
                    <span className="inline-grid place-items-center min-w-[20px] h-5 px-1.5 rounded-full bg-muted text-[11px] font-semibold text-foreground/80" title="Story points">
                        {item.story_points}
                    </span>
                )}
                {item.priority && <PriorityIndicator slug={item.priority.slug} />}

                <Popover open={assignOpen} onOpenChange={setAssignOpen}>
                    <PopoverTrigger asChild onClick={(e) => e.stopPropagation()}>
                        <button
                            type="button"
                            title={item.assignee ? `Assignee: ${item.assignee.name} — click to change` : 'Unassigned — click to assign'}
                            className="rounded-full shrink-0 outline-none transition-opacity hover:opacity-80 focus-visible:ring-2 focus-visible:ring-primary ring-offset-1 ring-offset-card"
                        >
                            {item.assignee ? (
                                <AssigneeAvatar user={item.assignee} size={24} />
                            ) : (
                                <span className="grid size-6 place-items-center rounded-full bg-muted text-muted-foreground transition-colors hover:text-primary">
                                    <UserIcon className="size-3.5" />
                                </span>
                            )}
                        </button>
                    </PopoverTrigger>
                    <PopoverContent align="end" className="p-1 w-56" onClick={(e) => e.stopPropagation()}>
                        <div className="max-h-60 overflow-y-auto">
                            <button
                                onClick={(e) => { e.stopPropagation(); assign(null); }}
                                className="flex items-center gap-2 w-full px-2 py-1.5 rounded-md text-sm hover:bg-muted/60 transition-colors text-left"
                            >
                                <AssigneeAvatar user={null} size={24} />
                                <span className="flex-1 truncate text-muted-foreground">Unassigned</span>
                                {!item.assigned_to && <Check className="size-3.5 shrink-0 text-primary" />}
                            </button>
                            {projectUsers.map((u) => (
                                <button
                                    key={u.id}
                                    onClick={(e) => { e.stopPropagation(); assign(u.id); }}
                                    className="flex items-center gap-2 w-full px-2 py-1.5 rounded-md text-sm hover:bg-muted/60 transition-colors text-left"
                                >
                                    <AssigneeAvatar user={u} size={24} />
                                    <span className="flex-1 truncate">{u.name}</span>
                                    {item.assigned_to === u.id && <Check className="size-3.5 shrink-0 text-primary" />}
                                </button>
                            ))}
                        </div>
                    </PopoverContent>
                </Popover>
            </div>

            {/* Nested subtasks (same column) */}
            {subtasks && subtasks.length > 0 && (
                <div className="mt-0.5 overflow-hidden rounded-lg border border-border/60 bg-secondary/30">
                    <div className="flex items-center gap-1.5 px-2.5 pt-1.5 pb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                        <CheckSquare className="size-3" />
                        Subtasks
                        <span className="font-normal normal-case">{subtasks.length}</span>
                    </div>
                    <div className="divide-y divide-border/50">
                        {subtasks.map((st) => {
                            const done = !!st.completed_at;
                            return (
                                <button
                                    key={st.id}
                                    type="button"
                                    onClick={(e) => { e.stopPropagation(); onEdit(st); }}
                                    className="flex w-full items-center gap-2 px-2.5 py-1.5 text-left transition-colors hover:bg-secondary/70"
                                >
                                    <ItemTypeIcon type={st.item_type} className="size-3.5 shrink-0" />
                                    <span className={cn('flex-1 truncate text-[11.5px] font-medium text-foreground/90', done && 'line-through text-muted-foreground')}>
                                        {st.title}
                                    </span>
                                    <span className="shrink-0 font-mono text-[9.5px] text-muted-foreground">{st.key}</span>
                                    <AssigneeAvatar user={st.assignee} size={15} />
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {/* Hover context menu */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                    <button className="absolute top-2 right-2 size-5 rounded-md grid place-items-center opacity-0 group-hover:opacity-100 transition-opacity text-muted-foreground hover:text-foreground hover:bg-secondary">
                        <MoreHorizontal className="size-3.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onClick={(e) => { e.stopPropagation(); onEdit(item); }}>Edit</DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onClick={(e) => { e.stopPropagation(); setConfirmDelete(true); }}
                        className="text-destructive focus:text-destructive"
                    >Delete</DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Delete confirmation */}
            <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}>
                <AlertDialogContent onClick={(e) => e.stopPropagation()}>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete {item.key}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {subtaskCount > 0
                                ? `This task has ${subtaskCount} subtask${subtaskCount > 1 ? 's' : ''}, which will be deleted too. This action cannot be undone.`
                                : 'This action cannot be undone.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel onClick={(e) => e.stopPropagation()}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={(e) => {
                                e.stopPropagation();
                                router.delete(route('boards.items.destroy', [workspace.slug, project.slug, board.slug, item.id]));
                            }}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </article>
    );
}

// Create Item Dialog
function CreateItemDialog({
    column,
    workspace,
    project,
    board,
    itemTypes,
    rootItems,
    open,
    onOpenChange,
}: {
    column: BoardColumn;
    workspace: Workspace;
    project: Project;
    board: Board;
    itemTypes: ItemType[];
    rootItems: Item[];
    /** Optional: open the dialog from outside (column "+" or the board's Create button). */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const [localOpen, setLocalOpen] = useState(false);
    const isCreating = open ?? localOpen;
    const setIsCreating = (value: boolean) => {
        setLocalOpen(value);
        onOpenChange?.(value);
    };
    const inputRef = useRef<HTMLInputElement>(null);
    const subtaskTypeId = itemTypes.find((t) => t.slug === 'subtask')?.id?.toString();
    const { data, setData, post, processing, reset } = useForm({
        title: '',
        column_id: column.id,
        item_type_id: itemTypes.find((t) => t.slug === 'task')?.id?.toString() || '',
        parent_id: '' as string | number,
    });

    const isSubtask = data.item_type_id === subtaskTypeId;
    const canSubmit = data.title.trim() && (!isSubtask || (data.parent_id !== '' && data.parent_id != null));

    useEffect(() => {
        if (isCreating) {
            window.setTimeout(() => inputRef.current?.focus(), 0);
        }
    }, [isCreating]);

    const closeDialog = () => {
        setIsCreating(false);
        reset();
        setData('parent_id', '');
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!canSubmit) return;
        const payload = {
            title: data.title,
            column_id: data.column_id,
            item_type_id: data.item_type_id,
            parent_id: data.parent_id ? Number(data.parent_id) : null,
        };
        router.post(route('boards.items.store', [workspace.slug, project.slug, board.slug]), payload, {
            onSuccess: () => {
                reset('title', 'parent_id');
                setData('parent_id', '');
                inputRef.current?.focus();
            },
        });
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Escape') {
            closeDialog();
        }
    };

    return (
        <Dialog
            open={isCreating}
            onOpenChange={(open) => {
                if (open) {
                    setIsCreating(true);
                } else {
                    closeDialog();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="w-full justify-start text-muted-foreground hover:text-foreground gap-2 h-9"
                >
                    <Plus className="size-4" />
                    Create
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md p-0 overflow-hidden">
                <DialogHeader className="border-b px-5 py-4">
                    <DialogTitle className="text-base">Create card</DialogTitle>
                    <DialogDescription>
                        Add a card to {column.name}.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4 px-5 py-4">
                    <div className="space-y-1.5">
                        <Label className="text-xs font-semibold text-muted-foreground">Title</Label>
                        <Input
                            ref={inputRef}
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            onKeyDown={handleKeyDown}
                            placeholder="What needs to be done?"
                            className="h-9 text-sm"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label className="text-xs font-semibold text-muted-foreground">Type</Label>
                        <Select value={data.item_type_id || ''} onValueChange={(v) => setData('item_type_id', v)}>
                            <SelectTrigger className="h-9 text-sm">
                                <SelectValue placeholder="Type" />
                            </SelectTrigger>
                            <SelectContent>
                                {itemTypes.map((type) => (
                                    <SelectItem key={type.id} value={String(type.id)}>
                                        <div className="flex items-center gap-2">
                                            <ItemTypeIcon type={type} />
                                            {type.name}
                                        </div>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5">
                        <Label className="text-xs font-semibold text-muted-foreground">
                            Parent task {isSubtask && <span className="text-destructive">*</span>}
                        </Label>
                        <Select
                            value={data.parent_id === '' || data.parent_id == null ? 'none' : String(data.parent_id)}
                            onValueChange={(v) => setData('parent_id', v === 'none' ? '' : Number(v))}
                        >
                            <SelectTrigger className="h-9 text-sm">
                                <SelectValue placeholder="None" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">None</SelectItem>
                                {rootItems.map((r) => (
                                    <SelectItem key={r.id} value={String(r.id)}>
                                        <span className="font-mono text-xs">{r.key}</span> {r.title}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-center justify-end gap-2 border-t pt-4">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={closeDialog}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" size="sm" disabled={processing || !canSubmit}>
                            Create
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// Column Component — redesigned
// ── Board filters: URL-backed + quick filters ───────────────────────────────
type QuickFilter = 'recent' | 'due' | 'blocked';
const QUICK_FILTERS: QuickFilter[] = ['recent', 'due', 'blocked'];
const RECENTLY_UPDATED_DAYS = 7;
const DUE_SOON_DAYS = 3;

interface BoardFilters {
    q: string;
    assignee: string;
    status: number[];
    labels: number[];
    priority: number[];
    type: number[];
    quick: QuickFilter[];
}

const parseIds = (value: string | null): number[] =>
    (value ?? '').split(',').map(Number).filter((n) => Number.isInteger(n) && n > 0);

function readBoardFilters(): BoardFilters {
    const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    return {
        q: params.get('q') ?? '',
        assignee: params.get('assignee') ?? 'any',
        status: parseIds(params.get('status')),
        labels: parseIds(params.get('labels')),
        priority: parseIds(params.get('priority')),
        type: parseIds(params.get('type')),
        quick: (params.get('quick') ?? '').split(',').filter((f): f is QuickFilter => (QUICK_FILTERS as string[]).includes(f)),
    };
}

/** Mirror the filters into the query string without an Inertia visit (keeps other params). */
function writeBoardFilters(filters: BoardFilters) {
    const url = new URL(window.location.href);
    const set = (key: string, value: string) => (value ? url.searchParams.set(key, value) : url.searchParams.delete(key));
    set('q', filters.q.trim());
    set('assignee', filters.assignee === 'any' ? '' : filters.assignee);
    set('status', filters.status.join(','));
    set('labels', filters.labels.join(','));
    set('priority', filters.priority.join(','));
    set('type', filters.type.join(','));
    set('quick', filters.quick.join(','));
    if (url.href !== window.location.href) {
        window.history.replaceState(window.history.state, '', url.href);
    }
}

function isBlocked(item: Item): boolean {
    return (item.outgoing_links ?? []).some((link) => link.link_type === 'is_blocked_by')
        || (item.incoming_links ?? []).some((link) => link.link_type === 'blocks');
}

function matchesQuickFilter(item: Item, filter: QuickFilter): boolean {
    const now = Date.now();
    const day = 24 * 60 * 60 * 1000;
    switch (filter) {
        case 'recent':
            return now - new Date(item.updated_at).getTime() <= RECENTLY_UPDATED_DAYS * day;
        case 'due': {
            if (!item.due_date || item.completed_at) return false;
            const due = new Date(String(item.due_date).split('T')[0] + 'T23:59:59').getTime();
            return due - now <= DUE_SOON_DAYS * day;
        }
        case 'blocked':
            return isBlocked(item);
    }
}

function QuickFilterChip({ active, onClick, icon, label }: { active: boolean; onClick: () => void; icon?: React.ReactNode; label: string }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'inline-flex items-center gap-1.5 h-8 px-2.5 rounded-[4px] text-[13px] font-medium transition-colors whitespace-nowrap',
                active
                    ? 'bg-primary/10 text-primary'
                    : 'text-foreground/80 hover:bg-secondary',
            )}
        >
            {icon}
            {label}
        </button>
    );
}

function BoardColumnComponent({
    column,
    workspace,
    project,
    board,
    itemTypes,
    rootItems,
    projectUsers,
    onEditItem,
    onDragStart,
    onDragEnd,
    onDragOver,
    onDrop,
    isDragOver,
    totalItemCount,
    createOpen,
    onCreateOpenChange,
}: {
    column: BoardColumn & { items: Item[] };
    createOpen: boolean;
    onCreateOpenChange: (open: boolean) => void;
    /** Cards in the column before filters — what the WIP limit counts. */
    totalItemCount: number;
    workspace: Workspace;
    project: Project;
    board: Board;
    itemTypes: ItemType[];
    rootItems: Item[];
    projectUsers: User[];
    onEditItem: (item: Item) => void;
    onDragStart: (e: DragEvent, item: Item) => void;
    onDragEnd: () => void;
    onDragOver: (e: DragEvent, column: BoardColumn) => void;
    onDrop: (e: DragEvent, column: BoardColumn) => void;
    isDragOver: boolean;
}) {
    const columnColor = column.color || '#94a3b8';
    const wipLimit = column.wip_limit ?? null;
    const isOverWip = wipLimit !== null && wipLimit > 0 && totalItemCount > wipLimit;
    const isAtWip = wipLimit !== null && wipLimit > 0 && totalItemCount === wipLimit;
    const [wipDialogOpen, setWipDialogOpen] = useState(false);
    const [wipValue, setWipValue] = useState(wipLimit ? String(wipLimit) : '');
    const settingsUrl = route('boards.edit', [workspace.slug, project.slug, board.slug]);

    const saveWipLimit = (e: FormEvent) => {
        e.preventDefault();
        router.put(route('boards.columns.update', [workspace.slug, project.slug, board.slug, column.id]), {
            name: column.name,
            color: column.color,
            is_done_column: column.is_done_column,
            wip_limit: wipValue.trim() === '' ? null : Number(wipValue),
        }, { preserveScroll: true, onSuccess: () => setWipDialogOpen(false) });
    };

    // Nest subtasks under their parent when both live in this column.
    const items = column.items ?? [];
    const idsInColumn = new Set(items.map((i) => i.id));
    const isNestedSubtask = (i: Item) =>
        i.item_type?.slug === 'subtask' && i.parent_id != null && idsInColumn.has(i.parent_id);
    const subtasksByParent = new Map<number, Item[]>();
    for (const i of items) {
        if (isNestedSubtask(i)) {
            const arr = subtasksByParent.get(i.parent_id!) ?? [];
            arr.push(i);
            subtasksByParent.set(i.parent_id!, arr);
        }
    }
    const topLevelItems = items.filter((i) => !isNestedSubtask(i));

    return (
        <section
            className={cn(
                'flex-shrink-0 w-[272px] h-full min-h-0 flex flex-col rounded-md bg-muted/60 dark:bg-secondary/30 transition-colors',
                isDragOver && 'bg-primary/[0.07] ring-2 ring-primary/30',
                isOverWip && 'bg-destructive/[0.08] dark:bg-destructive/[0.12]',
            )}
            onDragOver={(e) => onDragOver(e, column)}
            onDrop={(e) => onDrop(e, column)}
        >
            {/* Column Header */}
            <header className="group/col flex shrink-0 items-center justify-between pl-3 pr-1.5 pt-2.5 pb-2">
                <div className="flex items-center gap-1.5 min-w-0">
                    <h3 className="m-0 truncate text-[12px] font-semibold uppercase tracking-[0.03em] text-muted-foreground" title={column.name}>
                        {column.name}
                    </h3>
                    <span
                        className={cn(
                            'shrink-0 text-[12px] font-semibold text-muted-foreground',
                            isAtWip && 'text-amber-700 dark:text-amber-400',
                            isOverWip && 'text-red-600 dark:text-red-400',
                        )}
                        title={wipLimit ? `${totalItemCount} of max. ${wipLimit} cards (WIP limit)` : undefined}
                    >
                        {wipLimit ? `${totalItemCount} / MAX ${wipLimit}` : column.items?.length || 0}
                    </span>
                    {column.is_done_column && <Check className="size-3.5 shrink-0 text-brand-green" aria-label="Done column" />}
                </div>
                <div className="flex items-center gap-[2px] opacity-60 transition-opacity group-hover/col:opacity-100">
                    <button
                        type="button"
                        onClick={() => onCreateOpenChange(true)}
                        title={`Create in ${column.name}`}
                        className="size-6 grid place-items-center rounded-[4px] text-muted-foreground hover:bg-secondary hover:text-foreground transition-colors"
                    >
                        <Plus className="size-[14px]" />
                    </button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button className="size-6 grid place-items-center rounded-[5px] text-muted-foreground hover:bg-secondary hover:text-foreground transition-colors">
                                <MoreHorizontal className="size-[13px]" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => setWipDialogOpen(true)}>
                                {wipLimit ? `WIP limit: ${wipLimit}` : 'Set WIP limit'}
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <Link href={settingsUrl}>Edit columns…</Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <Dialog open={wipDialogOpen} onOpenChange={setWipDialogOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>WIP limit for {column.name}</DialogTitle>
                        <DialogDescription>
                            Maximum number of cards in this column. The column turns red when it has more — moving cards stays possible.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={saveWipLimit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor={`wip-${column.id}`}>Max. cards</Label>
                            <Input id={`wip-${column.id}`} type="number" min={0} value={wipValue} onChange={(e) => setWipValue(e.target.value)} placeholder="No limit" autoFocus />
                        </div>
                        <div className="flex justify-end gap-2">
                            {wipLimit !== null && (
                                <Button type="button" variant="ghost" onClick={() => setWipValue('')}>Remove limit</Button>
                            )}
                            <Button type="submit">Save</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Items */}
            <ScrollArea className="flex-1 min-h-0">
                <div className="flex flex-col gap-1.5 px-1.5 pb-2">
                    {topLevelItems.map((item) => (
                        <ItemCard
                            key={item.id}
                            item={item}
                            workspace={workspace}
                            project={project}
                            board={board}
                            projectUsers={projectUsers}
                            subtasks={subtasksByParent.get(item.id)}
                            onEdit={onEditItem}
                            onDragStart={onDragStart}
                            onDragEnd={onDragEnd}
                        />
                    ))}
                </div>
            </ScrollArea>

            {/* Create Item */}
            <div className="shrink-0 px-2 pb-3 pt-0">
                <CreateItemDialog
                    column={column}
                    workspace={workspace}
                    project={project}
                    board={board}
                    itemTypes={itemTypes}
                    rootItems={rootItems}
                    open={createOpen}
                    onOpenChange={onCreateOpenChange}
                />
            </div>
        </section>
    );
}

// Item Edit Modal
function ItemEditModal({
    item,
    workspace,
    project,
    board,
    itemTypes,
    priorities,
    projectUsers,
    projectLabels,
    projectItems,
    currentUserId,
    columns,
    open,
    onClose,
    onOpenItem,
}: {
    item: Item | null;
    workspace: Workspace;
    project: Project;
    board: Board;
    itemTypes: ItemType[];
    priorities: ItemPriority[];
    projectUsers: User[];
    projectLabels: LabelType[];
    projectItems: ProjectItemLite[];
    currentUserId: number;
    columns: BoardColumn[];
    open: boolean;
    onClose: () => void;
    onOpenItem: (item: Item) => void;
}) {
    const d = useItemDetail({
        item,
        workspace,
        project,
        board,
        itemTypes,
        priorities,
        projectUsers,
        projectLabels,
        projectItems,
        columns,
        currentUserId,
        onOpenItem,
    });
    const [detailsSheetOpen, setDetailsSheetOpen] = useState(false);

    if (!item) return null;

    return (
        <>
            <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
                <DialogContent className="max-w-6xl max-h-[92vh] p-0 gap-0 overflow-hidden [&>button]:hidden" aria-describedby={undefined}>
                    <DialogTitle className="sr-only">Edit {item.key}: {item.title}</DialogTitle>


                    {/* ── Header ─────────────────────────────────────────── */}
                    <div className="flex items-center justify-between px-4 sm:px-6 py-3 border-b bg-background shrink-0">
                        <nav className="flex items-center gap-1.5 min-w-0 text-[13px] text-muted-foreground" aria-label="Breadcrumb">
                            <span className="truncate">{project.name}</span>
                            <span aria-hidden>/</span>
                            {item.parent && (
                                <>
                                    <button type="button" onClick={() => onOpenItem(item.parent as Item)} className="hover:text-foreground hover:underline">
                                        {item.parent.key}
                                    </button>
                                    <span aria-hidden>/</span>
                                </>
                            )}
                            <ItemTypeIcon type={item.item_type} className="size-4 shrink-0" />
                            <Link
                                href={route('boards.items.show', [workspace.slug, project.slug, board.slug, item.id])}
                                className="font-medium text-foreground/80 hover:text-primary hover:underline"
                            >
                                {item.key}
                            </Link>
                        </nav>
                        <div className="flex items-center gap-1">
                            <Sheet open={detailsSheetOpen} onOpenChange={setDetailsSheetOpen}>
                                <SheetTrigger asChild>
                                    <Button variant="ghost" size="icon" className="size-8 lg:hidden">
                                        <SlidersHorizontal className="size-4" />
                                    </Button>
                                </SheetTrigger>
                                <SheetContent side="right" className="w-[300px] sm:w-[360px] p-0">
                                    <div className="px-5 py-5 h-full overflow-y-auto">
                                        <ItemDetailSidebar d={d} />
                                    </div>
                                </SheetContent>
                            </Sheet>
                            <Link href={route('boards.items.show', [workspace.slug, project.slug, board.slug, item.id])} className="inline-flex">
                                <Button variant="ghost" size="sm" className="gap-1.5 text-muted-foreground hover:text-foreground h-8 text-xs">
                                    <Maximize2 className="size-3.5" />
                                    <span className="hidden sm:inline">Full page</span>
                                </Button>
                            </Link>
                            <Button variant="ghost" size="icon" className="size-8 text-muted-foreground" onClick={onClose}>
                                <X className="size-4" />
                            </Button>
                        </div>
                    </div>

                    {/* ── Two-panel body ──────────────────────────────────── */}
                    <div className="flex overflow-hidden" style={{ maxHeight: 'calc(92vh - 61px)' }}>
                        <div className="flex-1 overflow-y-auto min-w-0">
                            <ItemDetailMain d={d} />
                        </div>
                        <div className="hidden lg:block w-[340px] shrink-0 overflow-y-auto bg-background">
                            <div className="px-5 py-5">
                                <ItemDetailSidebar d={d} />
                            </div>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            <ItemFilePreviewDialog d={d} />
        </>
    );
}

// List View Component
function ListView({
    columns,
    workspace,
    project,
    board,
    onEditItem,
}: {
    columns: (BoardColumn & { items: Item[] })[];
    workspace: Workspace;
    project: Project;
    board: Board;
    onEditItem: (item: Item) => void;
}) {
    const allItems = columns.flatMap(col => col.items.map(item => ({ ...item, columnName: col.name })));

    return (
        <div className="flex-1 overflow-auto bg-background">
            <div className="p-6">
                <table className="w-full">
                    <thead>
                        <tr className="border-b text-left text-sm text-muted-foreground">
                            <th className="pb-3 pl-4 font-medium">Key</th>
                            <th className="pb-3 font-medium">Title</th>
                            <th className="pb-3 font-medium">Parent</th>
                            <th className="pb-3 font-medium">Status</th>
                            <th className="pb-3 font-medium">Priority</th>
                            <th className="pb-3 font-medium">Assignee</th>
                            <th className="pb-3 font-medium">Due Date</th>
                            <th className="pb-3 pr-4 font-medium">Story Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        {allItems.map((item) => (
                            <tr
                                key={item.id}
                                className="border-b hover:bg-muted/50 cursor-pointer transition-colors"
                                onClick={() => onEditItem(item)}
                            >
                                <td className="py-3 pl-4">
                                    <div className="flex items-center gap-2">
                                        <ItemTypeIcon type={item.item_type} className="size-4" />
                                        <span className="font-mono text-sm text-primary font-medium">{item.key}</span>
                                    </div>
                                </td>
                                <td className="py-3">
                                    <span className="font-medium">{item.title}</span>
                                </td>
                                <td className="py-3">
                                    {item.parent ? (
                                        <Link
                                            href={route('boards.items.show', [workspace.slug, project.slug, board.slug, item.parent.id])}
                                            className="text-primary hover:underline font-mono text-sm"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {item.parent.key}
                                        </Link>
                                    ) : (
                                        <span className="text-muted-foreground text-sm">—</span>
                                    )}
                                </td>
                                <td className="py-3">
                                    <Badge variant="secondary" className="font-normal">
                                        {item.columnName}
                                    </Badge>
                                </td>
                                <td className="py-3">
                                    <div className="flex items-center gap-2">
                                        <PriorityIndicator slug={item.priority?.slug} />
                                        <span className="text-sm">{item.priority?.name || 'None'}</span>
                                    </div>
                                </td>
                                <td className="py-3">
                                    {item.assignee ? (
                                        <div className="flex items-center gap-2">
                                            <Avatar className="size-6">
                                                {item.assignee.avatar_display && (
                                                    <AvatarImage src={item.assignee.avatar_display} alt={item.assignee.name} />
                                                )}
                                                <AvatarFallback className="text-xs">
                                                    {item.assignee.name.charAt(0).toUpperCase()}
                                                </AvatarFallback>
                                            </Avatar>
                                            <span className="text-sm">{item.assignee.name}</span>
                                        </div>
                                    ) : (
                                        <span className="text-muted-foreground text-sm">Unassigned</span>
                                    )}
                                </td>
                                <td className="py-3">
                                    {item.due_date ? (
                                        <span className="text-sm">{new Date(item.due_date).toLocaleDateString()}</span>
                                    ) : (
                                        <span className="text-muted-foreground text-sm">—</span>
                                    )}
                                </td>
                                <td className="py-3 pr-4">
                                    {item.story_points ? (
                                        <Badge variant="outline" className="font-mono">{item.story_points}</Badge>
                                    ) : (
                                        <span className="text-muted-foreground text-sm">—</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {allItems.length === 0 && (
                            <tr>
                                <td colSpan={8} className="py-12 text-center text-muted-foreground">
                                    No items found
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

// Format date as YYYY-MM-DD for API
function toISODate(d: Date): string {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

// Short month names for fast tooltip formatting (avoids expensive toLocaleDateString)
const SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function formatShortDate(d: Date): string {
    return `${SHORT_MONTHS[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`;
}

// Weekday first-letter for header
const WEEKDAY_LETTERS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

// Gantt/Timeline View Component
function GanttView({
    columns,
    workspace,
    project,
    board,
    onEditItem,
    onUpdateItemDates,
}: {
    columns: (BoardColumn & { items: Item[] })[];
    workspace: Workspace;
    project: Project;
    board: Board;
    onEditItem: (item: Item) => void;
    onUpdateItemDates: (item: Item, start_date: string | null, due_date: string | null) => void;
}) {
    // Build tree-ordered list: parents first, children indented underneath
    const rawItems = columns.flatMap(col => col.items.map(item => ({ ...item, columnName: col.name })));
    type TreeItem = typeof rawItems[number] & { depth: number };
    const treeItems = useMemo(() => {
        const roots = rawItems.filter(i => !i.parent_id).sort((a, b) => a.position - b.position);
        const childMap = new Map<number, typeof rawItems>();
        rawItems.forEach(i => {
            if (i.parent_id) {
                const arr = childMap.get(i.parent_id) || [];
                arr.push(i);
                childMap.set(i.parent_id, arr);
            }
        });
        const result: TreeItem[] = [];
        roots.forEach(root => {
            result.push({ ...root, depth: 0 });
            const children = (childMap.get(root.id) || []).sort((a, b) => a.position - b.position);
            children.forEach(child => result.push({ ...child, depth: 1 }));
        });
        // Items whose parent isn't in this set (orphans) go at the end
        const addedIds = new Set(result.map(i => i.id));
        rawItems.filter(i => !addedIds.has(i.id)).forEach(i => result.push({ ...i, depth: 0 }));
        return result;
    }, [rawItems]);

    // Sidebar drag-to-reorder state
    const [sidebarDragId, setSidebarDragId] = useState<number | null>(null);
    const [sidebarDragOverId, setSidebarDragOverId] = useState<number | null>(null);
    const [collapsedIds, setCollapsedIds] = useState<Set<number>>(new Set());

    // Items visible after collapsing: hide children of collapsed parents
    const visibleItems = useMemo(() =>
        treeItems.filter(i => !i.parent_id || !collapsedIds.has(i.parent_id)),
        [treeItems, collapsedIds]
    );

    const toggleCollapse = (id: number) => {
        setCollapsedIds(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };

    const today = new Date();
    const startDate = new Date(today.getFullYear(), 0, 1);
    const endDate = new Date(today.getFullYear(), 11, 31);

    const totalDays = Math.ceil((endDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24)) + 1;
    const DAY_WIDTH = 40;
    const TOTAL_WIDTH = totalDays * DAY_WIDTH;
    const todayIndex = Math.floor((today.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24));

    // Grid lines + alternating subtle day-striping for visual distinction
    // Two-day-wide repeating gradient: one day slightly tinted, next day transparent, with a border line between each
    const gridBg = [
        `repeating-linear-gradient(to right, hsl(var(--border)) 0px, hsl(var(--border)) 1px, transparent 1px, transparent ${DAY_WIDTH}px)`,
        `repeating-linear-gradient(to right, hsl(var(--muted) / 0.18) 0px, hsl(var(--muted) / 0.18) ${DAY_WIDTH}px, transparent ${DAY_WIDTH}px, transparent ${DAY_WIDTH * 2}px)`,
    ].join(', ');

    // ─── Drag: ZERO state updates during drag. Only refs + direct DOM. ───
    type DragMode = 'move' | 'resize-left' | 'resize-right';
    const dragRef = useRef<{
        item: Item;
        mode: DragMode;
        startX: number;
        startStart: Date;
        startDue: Date;
    } | null>(null);
    const [isDragging, setIsDragging] = useState(false); // only toggled on mousedown/up
    const didDragRef = useRef(false);
    const dragPreviewRef = useRef<{ start_date: string; due_date: string } | null>(null);
    const dragBarRef = useRef<HTMLDivElement | null>(null);
    const tooltipRef = useRef<HTMLDivElement | null>(null);
    const rafIdRef = useRef<number>(0);

    const getItemPosition = (item: Item) => {
        if (!item.start_date && !item.due_date) return null;
        const itemStart = item.start_date ? new Date(item.start_date) : new Date(item.due_date!);
        const itemEnd = item.due_date ? new Date(item.due_date) : new Date(item.start_date!);
        const startOffset = Math.floor((itemStart.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24));
        const duration = Math.max(1, Math.ceil((itemEnd.getTime() - itemStart.getTime()) / (1000 * 60 * 60 * 24)) + 1);
        return { left: startOffset * DAY_WIDTH, width: duration * DAY_WIDTH };
    };

    // Global mouse handlers — pure DOM manipulation, no React state during drag
    useEffect(() => {
        const onMove = (e: MouseEvent) => {
            const ds = dragRef.current;
            if (!ds) return;
            if (rafIdRef.current) cancelAnimationFrame(rafIdRef.current);
            const clientX = e.clientX;
            rafIdRef.current = requestAnimationFrame(() => {
                const dayDelta = Math.round((clientX - ds.startX) / DAY_WIDTH);
                let newStart = new Date(ds.startStart);
                let newDue = new Date(ds.startDue);
                if (ds.mode === 'move') {
                    newStart.setDate(newStart.getDate() + dayDelta);
                    newDue.setDate(newDue.getDate() + dayDelta);
                } else if (ds.mode === 'resize-left') {
                    newStart.setDate(newStart.getDate() + dayDelta);
                    if (newStart > newDue) newStart = new Date(newDue);
                } else {
                    newDue.setDate(newDue.getDate() + dayDelta);
                    if (newDue < newStart) newDue = new Date(newStart);
                }
                // clamp
                if (newStart < startDate) newStart = new Date(startDate);
                if (newStart > endDate) newStart = new Date(endDate);
                if (newDue < startDate) newDue = new Date(startDate);
                if (newDue > endDate) newDue = new Date(endDate);
                if (newStart > newDue) [newStart, newDue] = [newDue, newStart];

                dragPreviewRef.current = { start_date: toISODate(newStart), due_date: toISODate(newDue) };

                // Direct DOM update — bar position
                const so = Math.floor((newStart.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24));
                const dur = Math.max(1, Math.ceil((newDue.getTime() - newStart.getTime()) / (1000 * 60 * 60 * 24)) + 1);
                if (dragBarRef.current) {
                    dragBarRef.current.style.left = `${Math.max(0, so * DAY_WIDTH)}px`;
                    dragBarRef.current.style.width = `${dur * DAY_WIDTH}px`;
                }

                // Direct DOM update — tooltip
                if (tooltipRef.current) {
                    const s = formatShortDate(newStart);
                    const d = formatShortDate(newDue);
                    tooltipRef.current.textContent = s === d ? s : `${s} → ${d}`;
                }
                didDragRef.current = true;
            });
        };

        const onUp = () => {
            if (!dragRef.current) return;
            if (rafIdRef.current) cancelAnimationFrame(rafIdRef.current);
            const latest = dragPreviewRef.current;
            if (didDragRef.current && latest) {
                onUpdateItemDates(dragRef.current.item, latest.start_date, latest.due_date);
            }
            dragRef.current = null;
            dragPreviewRef.current = null;
            if (tooltipRef.current) tooltipRef.current.style.display = 'none';
            setIsDragging(false);
            setTimeout(() => { didDragRef.current = false; }, 0);
        };

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        return () => {
            if (rafIdRef.current) cancelAnimationFrame(rafIdRef.current);
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        };
    }, [startDate, endDate, onUpdateItemDates]);

    // Scroll to today on mount
    const scrollRef = useRef<HTMLDivElement | null>(null);
    const didInitialScrollRef = useRef(false);
    useEffect(() => {
        if (didInitialScrollRef.current) return;
        const container = scrollRef.current;
        if (!container || todayIndex < 0 || todayIndex >= totalDays) return;
        container.scrollLeft = Math.max(0, todayIndex * DAY_WIDTH - container.clientWidth / 2);
        didInitialScrollRef.current = true;
    }, [todayIndex, totalDays]);

    const handleBarClick = (item: Item) => {
        if (didDragRef.current) return;
        if (!item.start_date && item.due_date) {
            onUpdateItemDates(item, item.due_date, item.due_date);
            return;
        }
        onEditItem(item);
    };

    const handleDayCellClick = (item: Item, clickX: number) => {
        const dayIndex = Math.floor(clickX / DAY_WIDTH);
        if (dayIndex < 0 || dayIndex >= totalDays) return;
        const clickedDate = new Date(startDate);
        clickedDate.setDate(clickedDate.getDate() + dayIndex);
        onUpdateItemDates(item, toISODate(clickedDate), toISODate(clickedDate));
    };

    const beginDrag = (e: React.MouseEvent, item: Item, mode: DragMode) => {
        if (e.button !== 0) return;
        e.preventDefault();
        const itemStart = item.start_date ? new Date(item.start_date) : new Date(item.due_date!);
        const itemEnd = item.due_date ? new Date(item.due_date) : new Date(item.start_date!);
        dragRef.current = { item, mode, startX: e.clientX, startStart: new Date(itemStart), startDue: new Date(itemEnd) };
        didDragRef.current = false;
        dragPreviewRef.current = null;
        // Show tooltip immediately
        if (tooltipRef.current) {
            const s = formatShortDate(itemStart);
            const d = formatShortDate(itemEnd);
            tooltipRef.current.textContent = s === d ? s : `${s} → ${d}`;
            tooltipRef.current.style.display = 'block';
        }
        setIsDragging(true); // single re-render to show drag styling on bar
    };

    // ─── Build header days lazily via ref-based scroll observation ───
    // Instead of state-based virtualization (which causes re-renders on scroll),
    // we render the header via a canvas-like approach: a ref + direct DOM.
    const headerRef = useRef<HTMLDivElement | null>(null);
    const headerScrollSyncRef = useRef<HTMLDivElement | null>(null);
    const headerRenderedRangeRef = useRef<{ from: number; to: number }>({ from: -1, to: -1 });

    const renderHeader = useCallback(() => {
        const container = scrollRef.current;
        const header = headerRef.current;
        if (!container || !header) return;
        const sl = container.scrollLeft;
        const cw = container.clientWidth;
        const from = Math.max(0, Math.floor(sl / DAY_WIDTH) - 5);
        const to = Math.min(totalDays - 1, Math.ceil((sl + cw) / DAY_WIDTH) + 5);
        const prev = headerRenderedRangeRef.current;
        // Only re-render header if range shifted significantly (by more than 3 days)
        if (prev.from >= 0 && Math.abs(prev.from - from) < 3 && Math.abs(prev.to - to) < 3) return;
        headerRenderedRangeRef.current = { from, to };

        // Clear and rebuild header children directly
        header.innerHTML = '';
        for (let i = from; i <= to; i++) {
            const d = new Date(startDate);
            d.setDate(d.getDate() + i);
            const isToday = i === todayIndex;
            const isWeekend = d.getDay() === 0 || d.getDay() === 6;
            const el = document.createElement('div');
            el.style.cssText = `position:absolute;top:0;bottom:0;left:${i * DAY_WIDTH}px;width:${DAY_WIDTH}px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:11px;border-right:1px solid var(--border);`;
            if (isToday) {
                el.style.background = 'hsl(var(--primary) / 0.1)';
                el.style.color = 'hsl(var(--primary))';
                el.style.fontWeight = '700';
            } else if (isWeekend) {
                el.style.background = 'hsl(var(--muted) / 0.5)';
                el.style.color = 'hsl(var(--muted-foreground))';
            }
            el.innerHTML = `<span>${d.getDate()}</span><span style="font-size:10px">${WEEKDAY_LETTERS[d.getDay()]}</span>`;
            header.appendChild(el);
        }
    }, [totalDays, startDate, todayIndex]);

    useEffect(() => {
        renderHeader();
        const container = scrollRef.current;
        if (!container) return;
        // Only call renderHeader on scroll — viewportScroll is updated via the React onScroll prop
        container.addEventListener('scroll', renderHeader, { passive: true });
        return () => container.removeEventListener('scroll', renderHeader);
    }, [renderHeader]);

    const draggingItemId = dragRef.current?.item.id;
    // Track horizontal scroll position for out-of-view indicators
    const [viewportScroll, setViewportScroll] = useState<{ left: number; width: number }>({ left: 0, width: 0 });
    // Initialize: left=0 (raw scrollLeft), width=timeline right edge
    useEffect(() => {
        if (scrollRef.current) {
            const el = scrollRef.current;
            setViewportScroll({ left: 0, width: el.clientWidth - 288 });
        }
    }, []);

    return (
        <div className="flex-1 overflow-auto bg-background relative" ref={scrollRef}
            onScroll={(e) => {
                const el = e.currentTarget;
                // Sync header horizontal scroll
                if (headerScrollSyncRef.current) headerScrollSyncRef.current.scrollLeft = el.scrollLeft;
                // Store: left = raw scrollLeft (for indicator positioning)
                //        width = timeline visible right edge = scrollLeft + clientWidth - 288
                if (rafIdRef.current) cancelAnimationFrame(rafIdRef.current);
                rafIdRef.current = requestAnimationFrame(() =>
                    setViewportScroll({
                        left: el.scrollLeft,                          // raw scroll offset
                        width: el.scrollLeft + el.clientWidth - 288,  // timeline visible right edge
                    })
                );
            }}
        >
            {/* Tooltip — always in DOM, shown/hidden via direct style */}
            <div
                ref={tooltipRef}
                className="fixed right-4 top-4 z-50 px-2 py-1.5 rounded-md bg-primary text-primary-foreground text-xs font-medium shadow-lg whitespace-nowrap pointer-events-none"
                style={{ display: 'none' }}
            />

            {/* Min width so scrollbar spans full content */}
            <div style={{ minWidth: TOTAL_WIDTH + 288 }}>

                {/* ── Sticky header row: "Items" label + date header ── */}
                <div className="sticky top-0 z-20 flex bg-background border-b">
                    <div className="w-72 flex-shrink-0 border-r h-12 px-4 flex items-center font-medium text-sm sticky left-0 bg-background z-30">
                        Items
                    </div>
                    {/* Date header — overflow:hidden + scrollLeft synced via JS */}
                    <div className="flex-1 overflow-hidden" ref={headerScrollSyncRef}>
                        <div ref={headerRef} className="relative h-12" style={{ width: TOTAL_WIDTH }} />
                    </div>
                </div>

                {/* ── Body: sidebar (sticky-left) + timeline rows ── */}
                <div className="flex">
                    {/* Left sidebar — sticky left, solid bg so timeline doesn't bleed through */}
                    <div className="w-72 flex-shrink-0 border-r sticky left-0 z-10 bg-background">
                        {visibleItems.map((item) => (
                            <div
                                key={item.id}
                                className={cn(
                                    'h-10 border-b flex items-center gap-2 hover:bg-muted/50 cursor-pointer transition-colors bg-muted/30',
                                    sidebarDragOverId === item.id && sidebarDragId !== item.id && 'border-t-2 border-t-primary',
                                    sidebarDragId === item.id && 'opacity-40'
                                )}
                                style={{ paddingLeft: 16 + item.depth * 20, paddingRight: 16 }}
                                onClick={() => onEditItem(item)}
                                draggable
                                onDragStart={(e) => { e.dataTransfer.effectAllowed = 'move'; setSidebarDragId(item.id); }}
                                onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; setSidebarDragOverId(item.id); }}
                                onDragLeave={() => setSidebarDragOverId(null)}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    setSidebarDragOverId(null);
                                    const draggedId = sidebarDragId;
                                    setSidebarDragId(null);
                                    if (!draggedId || draggedId === item.id) return;
                                    const dragItem = treeItems.find(i => i.id === draggedId);
                                    if (!dragItem) return;
                                    const dropParentId = item.parent_id || null;
                                    const siblings = treeItems.filter(i =>
                                        (i.parent_id || null) === dropParentId && i.id !== draggedId
                                    );
                                    const dropIdx = siblings.findIndex(i => i.id === item.id);
                                    const newOrder = [...siblings];
                                    newOrder.splice(dropIdx >= 0 ? dropIdx : newOrder.length, 0, dragItem);
                                    const updates = newOrder.map((it, idx) => ({ id: it.id, position: idx }));
                                    router.post(
                                        route('boards.items.reorder', [workspace.slug, project.slug, board.slug]),
                                        { items: updates },
                                        { preserveScroll: true },
                                    );
                                }}
                                onDragEnd={() => { setSidebarDragId(null); setSidebarDragOverId(null); }}
                            >
                                {item.depth === 0 && (item.children_count ?? 0) > 0 ? (
                                    <button
                                        className="flex-shrink-0 w-4 h-4 flex items-center justify-center text-muted-foreground hover:text-foreground"
                                        onClick={(e) => { e.stopPropagation(); toggleCollapse(item.id); }}
                                    >
                                        {collapsedIds.has(item.id) ? '▶' : '▼'}
                                    </button>
                                ) : (
                                    <span className="flex-shrink-0 w-4" />
                                )}
                                {item.depth > 0 && <span className="text-muted-foreground text-xs">↳</span>}
                                <ItemTypeIcon type={item.item_type} className="size-4" />
                                <span className="font-mono text-xs text-primary">{item.key}</span>
                                <span className="text-sm truncate flex-1">{item.title}</span>
                            </div>
                        ))}
                    </div>

                    {/* Timeline rows */}
                    <div className="flex-1 relative" style={{ width: TOTAL_WIDTH }}>
                        {/* Today column highlight */}
                        <div
                            className="absolute top-0 bottom-0 pointer-events-none"
                            style={{ left: todayIndex * DAY_WIDTH, width: DAY_WIDTH, background: 'hsl(var(--primary) / 0.05)', zIndex: 0 }}
                        />

                        {visibleItems.map((item, rowIndex) => {
                            const isThisDragging = isDragging && draggingItemId === item.id;
                            const position = getItemPosition(item);
                            const hasNoDates = !item.start_date && !item.due_date;
                            // Out-of-view indicators.
                            // viewportScroll.left = raw scrollLeft (for indicator positioning)
                            // viewportScroll.width = timeline visible right edge
                            // Timeline visible left = max(0, scrollLeft - 288)
                            const barLeft = position ? position.left : null;
                            const barRight = position ? position.left + position.width : null;
                            const rawScroll = viewportScroll.left;
                            const viewRight = viewportScroll.width; // right edge of visible timeline
                            // Bar is fully behind the sticky sidebar when barRight < rawScroll
                            // (sidebar covers timeline pixels 0..scrollLeft-1)
                            const isOffLeft = barRight !== null && barRight < rawScroll;
                            const isOffRight = barLeft !== null && barLeft > viewRight;
                            return (
                                <div
                                    key={item.id}
                                    className={`h-10 relative border-b ${rowIndex === 0 ? 'border-t' : ''} ${hasNoDates ? 'cursor-pointer' : ''}`}
                                    style={{ backgroundImage: gridBg }}
                                    onClick={hasNoDates ? (e) => {
                                        const rect = e.currentTarget.getBoundingClientRect();
                                        handleDayCellClick(item, e.clientX - rect.left);
                                    } : undefined}
                                >
                                    {position && position.width >= 20 && (
                                        <div
                                            ref={isThisDragging ? dragBarRef : undefined}
                                            className={cn(
                                                'absolute top-1 h-8 rounded-md bg-primary/80 text-primary-foreground text-xs flex items-center px-2 shadow-sm select-none',
                                                isThisDragging ? 'cursor-grabbing ring-2 ring-primary ring-offset-1 z-20' : 'cursor-grab hover:bg-primary transition-colors'
                                            )}
                                            style={{ left: Math.max(0, position.left), width: position.width }}
                                            onClick={(e) => { e.stopPropagation(); handleBarClick(item); }}
                                            onMouseDown={(e) => {
                                                e.stopPropagation();
                                                const rect = e.currentTarget.getBoundingClientRect();
                                                const x = e.clientX - rect.left;
                                                const edge = 8;
                                                let mode: DragMode = 'move';
                                                if (x < edge) mode = 'resize-left';
                                                else if (x > position.width - edge) mode = 'resize-right';
                                                beginDrag(e, item, mode);
                                            }}
                                        >
                                            {!isThisDragging && position.width > 24 && (
                                                <>
                                                    <div
                                                        className="absolute left-0 top-0 bottom-0 w-2 cursor-ew-resize rounded-l-md hover:bg-primary-foreground/20"
                                                        onMouseDown={(e) => { e.stopPropagation(); beginDrag(e, item, 'resize-left'); }}
                                                    />
                                                    <div
                                                        className="absolute right-0 top-0 bottom-0 w-2 cursor-ew-resize rounded-r-md hover:bg-primary-foreground/20"
                                                        onMouseDown={(e) => { e.stopPropagation(); beginDrag(e, item, 'resize-right'); }}
                                                    />
                                                </>
                                            )}
                                            <span className="truncate pointer-events-none">{item.key}{position.width > 120 ? ` · ${item.title}` : ''}</span>
                                        </div>
                                    )}
                                    {position && position.width < 20 && (
                                        <div
                                            className="absolute top-1 h-8 rounded-md bg-primary/80 text-primary-foreground text-xs flex items-center px-2 cursor-pointer hover:bg-primary transition-colors shadow-sm min-w-[20px]"
                                            style={{ left: Math.max(0, position.left), width: position.width }}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                if (!item.start_date && item.due_date) { onUpdateItemDates(item, item.due_date, item.due_date); return; }
                                                onEditItem(item);
                                            }}
                                        >
                                            <span className="truncate">{item.key}</span>
                                        </div>
                                    )}
                                    {/* Out-of-view indicators — positioned at visible timeline edges */}
                                    {isOffLeft && barLeft !== null && (
                                        <div
                                            className="absolute top-1 h-8 z-10 flex items-center cursor-pointer"
                                            style={{ left: rawScroll + 4 }}
                                            title="Click to jump to date"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                const el = scrollRef.current;
                                                if (!el) return;
                                                // Center barLeft in the timeline viewport
                                                const target = barLeft + 288 - (el.clientWidth - 288) / 2;
                                                el.scrollTo({ left: Math.max(0, target), behavior: 'smooth' });
                                            }}
                                        >
                                            <span className="bg-primary/90 text-primary-foreground text-[10px] px-1.5 py-0.5 rounded-r-md font-medium shadow-md hover:bg-primary transition-colors">
                                                ◀ {item.key}
                                            </span>
                                        </div>
                                    )}
                                    {isOffRight && barLeft !== null && (
                                        <div
                                            className="absolute top-1 h-8 z-10 flex items-center cursor-pointer"
                                            style={{ left: viewRight - 72 }}
                                            title="Click to jump to date"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                const el = scrollRef.current;
                                                if (!el) return;
                                                // Center barLeft in the timeline viewport
                                                const target = barLeft + 288 - (el.clientWidth - 288) / 2;
                                                el.scrollTo({ left: Math.max(0, target), behavior: 'smooth' });
                                            }}
                                        >
                                            <span className="bg-primary/90 text-primary-foreground text-[10px] px-1.5 py-0.5 rounded-l-md font-medium shadow-md hover:bg-primary transition-colors">
                                                {item.key} ▶
                                            </span>
                                        </div>
                                    )}
                                </div>
                            );
                        })}

                        {treeItems.length === 0 && (
                            <div className="py-12 text-center text-muted-foreground">No items found</div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

// Calendar View Component
function CalendarView({
    columns,
    workspace,
    project,
    board,
    onEditItem,
    calendarDate,
    setCalendarDate,
}: {
    columns: (BoardColumn & { items: Item[] })[];
    workspace: Workspace;
    project: Project;
    board: Board;
    onEditItem: (item: Item) => void;
    calendarDate: Date;
    setCalendarDate: (date: Date) => void;
}) {
    const allItems = columns.flatMap(col => col.items.map(item => ({ ...item, columnName: col.name })));

    // Get calendar data for the current month
    const year = calendarDate.getFullYear();
    const month = calendarDate.getMonth();

    const firstDayOfMonth = new Date(year, month, 1);
    const lastDayOfMonth = new Date(year, month + 1, 0);
    const startDay = firstDayOfMonth.getDay();
    const daysInMonth = lastDayOfMonth.getDate();

    // Create calendar grid
    const calendarDays: (Date | null)[] = [];

    // Add empty cells for days before the first of the month
    for (let i = 0; i < startDay; i++) {
        calendarDays.push(null);
    }

    // Add days of the month
    for (let day = 1; day <= daysInMonth; day++) {
        calendarDays.push(new Date(year, month, day));
    }

    // Get items for a specific date
    const getItemsForDate = (date: Date) => {
        return allItems.filter(item => {
            if (item.due_date) {
                const dueDate = new Date(item.due_date);
                return dueDate.toDateString() === date.toDateString();
            }
            return false;
        });
    };

    const prevMonth = () => {
        setCalendarDate(new Date(year, month - 1, 1));
    };

    const nextMonth = () => {
        setCalendarDate(new Date(year, month + 1, 1));
    };

    const today = new Date();

    return (
        <div className="flex-1 overflow-auto bg-background p-6">
            {/* Calendar Header */}
            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-4">
                    <h2 className="text-xl font-semibold">
                        {calendarDate.toLocaleDateString('en', { month: 'long', year: 'numeric' })}
                    </h2>
                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="icon" className="size-8" onClick={prevMonth}>
                            <ChevronLeft className="size-4" />
                        </Button>
                        <Button variant="outline" size="icon" className="size-8" onClick={nextMonth}>
                            <ChevronRight className="size-4" />
                        </Button>
                    </div>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCalendarDate(new Date())}
                >
                    Today
                </Button>
            </div>

            {/* Calendar Grid */}
            <div className="border rounded-lg overflow-hidden">
                {/* Day headers */}
                <div className="grid grid-cols-7 bg-muted/50 border-b">
                    {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day) => (
                        <div key={day} className="py-2 text-center text-sm font-medium text-muted-foreground">
                            {day}
                        </div>
                    ))}
                </div>

                {/* Calendar days */}
                <div className="grid grid-cols-7">
                    {calendarDays.map((date, index) => {
                        const isToday = date?.toDateString() === today.toDateString();
                        const items = date ? getItemsForDate(date) : [];

                        return (
                            <div
                                key={index}
                                className={`min-h-[120px] border-b border-r p-2 ${!date ? 'bg-muted/30' : ''
                                    } ${isToday ? 'bg-primary/5' : ''}`}
                            >
                                {date && (
                                    <>
                                        <div className={`text-sm font-medium mb-2 ${isToday ? 'text-primary' : 'text-foreground'
                                            }`}>
                                            {date.getDate()}
                                        </div>
                                        <div className="space-y-1">
                                            {items.slice(0, 3).map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="text-xs p-1.5 bg-primary/10 text-primary rounded cursor-pointer hover:bg-primary/20 transition-colors truncate"
                                                    onClick={() => onEditItem(item)}
                                                    title={item.title}
                                                >
                                                    <span className="font-mono">{item.key}</span> {item.title}
                                                </div>
                                            ))}
                                            {items.length > 3 && (
                                                <div className="text-xs text-muted-foreground px-1">
                                                    +{items.length - 3} more
                                                </div>
                                            )}
                                        </div>
                                    </>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

// Main Board Component
export default function BoardsShow({
    auth,
    workspace,
    project,
    board,
    boardItemTypes = [],
    priorities,
    projectUsers,
    projectLabels,
    projectItems = [],
}: Props) {
    const itemTypes = boardItemTypes;
    const [editingItem, setEditingItem] = useState<Item | null>(null);
    const [draggedItem, setDraggedItem] = useState<Item | null>(null);
    const [dragOverColumn, setDragOverColumn] = useState<number | null>(null);
    // Filters live in the URL so a filtered board can be shared or bookmarked
    const initialFilters = useMemo(readBoardFilters, []);
    const [searchQuery, setSearchQuery] = useState(initialFilters.q);
    const [currentView, setCurrentView] = useState<'kanban' | 'list' | 'gantt' | 'calendar'>('kanban');
    const [calendarDate, setCalendarDate] = useState(new Date());
    const [filterOpen, setFilterOpen] = useState(false);
    const [assigneeFilter, setAssigneeFilter] = useState<string>(initialFilters.assignee);
    const [assigneePopoverOpen, setAssigneePopoverOpen] = useState(false);
    const [assigneeSearch, setAssigneeSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState<number[]>(initialFilters.status);
    const [labelFilter, setLabelFilter] = useState<number[]>(initialFilters.labels);
    const [priorityFilter, setPriorityFilter] = useState<number[]>(initialFilters.priority);
    const [typeFilter, setTypeFilter] = useState<number[]>(initialFilters.type);
    const [quickFilters, setQuickFilters] = useState<QuickFilter[]>(initialFilters.quick);
    const [createInColumnId, setCreateInColumnId] = useState<number | null>(null);
    const currentUserId = auth.user?.id;

    useEffect(() => {
        writeBoardFilters({
            q: searchQuery,
            assignee: assigneeFilter,
            status: statusFilter,
            labels: labelFilter,
            priority: priorityFilter,
            type: typeFilter,
            quick: quickFilters,
        });
    }, [searchQuery, assigneeFilter, statusFilter, labelFilter, priorityFilter, typeFilter, quickFilters]);

    const toggleQuickFilter = (filter: QuickFilter) =>
        setQuickFilters((current) => current.includes(filter) ? current.filter((f) => f !== filter) : [...current, filter]);

    const activeFiltersCount = useMemo(
        () =>
            (assigneeFilter !== 'any' ? 1 : 0) +
            (statusFilter.length ? 1 : 0) +
            (labelFilter.length ? 1 : 0) +
            (priorityFilter.length ? 1 : 0) +
            (typeFilter.length ? 1 : 0) +
            quickFilters.length,
        [assigneeFilter, statusFilter.length, labelFilter.length, priorityFilter.length, typeFilter.length, quickFilters.length],
    );

    // Persist board view preference per project so user sees same view next time
    useEffect(() => {
        if (typeof window === 'undefined') return;
        const key = `board-view-${project.id}-${board.id}`;
        const stored = window.localStorage.getItem(key) as 'kanban' | 'list' | 'gantt' | 'calendar' | null;
        if (stored && ['kanban', 'list', 'gantt', 'calendar'].includes(stored)) {
            setCurrentView(stored);
        }
    }, [project.id, board.id]);

    useEffect(() => {
        if (typeof window === 'undefined') return;
        const key = `board-view-${project.id}-${board.id}`;
        window.localStorage.setItem(key, currentView);
    }, [currentView, project.id, board.id]);

    const handleDragStart = (e: DragEvent, item: Item) => {
        setDraggedItem(item);
        e.dataTransfer.effectAllowed = 'move';
        // Add visual feedback
        if (e.currentTarget instanceof HTMLElement) {
            e.currentTarget.style.opacity = '0.5';
        }
    };

    const handleDragEnd = () => {
        setDraggedItem(null);
        setDragOverColumn(null);
    };

    const handleDragOver = (e: DragEvent, column: BoardColumn) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        setDragOverColumn(column.id);
    };

    const handleDrop = (e: DragEvent, column: BoardColumn) => {
        e.preventDefault();
        if (draggedItem && draggedItem.column_id !== column.id) {
            router.patch(
                route('boards.items.move', [workspace.slug, project.slug, board.slug, draggedItem.id]),
                {
                    column_id: column.id,
                    position: 0,
                },
                { preserveScroll: true }
            );
        }
        setDraggedItem(null);
        setDragOverColumn(null);
    };

    // Filter items based on search + filter controls
    const filteredColumns = board.columns.map((col) => ({
        ...col,
        items:
            col.items?.filter((item) => {
                const matchesSearch = searchQuery
                    ? item.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
                    item.key.toLowerCase().includes(searchQuery.toLowerCase())
                    : true;
                if (!matchesSearch) return false;

                // Assignee filter
                if (assigneeFilter === 'me' && currentUserId) {
                    if (item.assigned_to !== currentUserId) return false;
                } else if (assigneeFilter === 'unassigned') {
                    if (item.assigned_to) return false;
                } else if (assigneeFilter.startsWith('user-')) {
                    const id = Number(assigneeFilter.split('-')[1] || '');
                    if (!id || item.assigned_to !== id) return false;
                }

                // Status filter (columns)
                if (statusFilter.length && !statusFilter.includes(col.id)) {
                    return false;
                }

                // Label filter (must have at least one of selected labels)
                if (labelFilter.length) {
                    if (!item.labels || !item.labels.some((lbl) => labelFilter.includes(lbl.id))) {
                        return false;
                    }
                }

                // Priority filter
                if (priorityFilter.length && !(item.priority_id && priorityFilter.includes(item.priority_id))) {
                    return false;
                }

                // Type filter
                if (typeFilter.length && !(item.item_type_id && typeFilter.includes(item.item_type_id))) {
                    return false;
                }

                // Quick filters (all selected must match)
                return quickFilters.every((filter) => matchesQuickFilter(item, filter));
            }) || [],
    }));

    // Root items (no parent) for parent-task selector
    const rootItems = useMemo(
        () => filteredColumns.flatMap((c) => c.items || []).filter((i) => !i.parent_id),
        [filteredColumns],
    );

    return (
        <AppLayout>
            <Head title={`${board.name} - ${project.name}`} />

            <div className="flex flex-col h-full overflow-hidden">
                {/* ─── Board Header (Jira-style: breadcrumb, title, view tabs) ─── */}
                <div className="px-6 pt-4 shrink-0">
                    <nav className="flex items-center gap-1.5 text-[13px] text-muted-foreground" aria-label="Breadcrumb">
                        <Link href={route('projects.show', [workspace.slug, project.slug])} className="hover:text-foreground hover:underline">Projects</Link>
                        <span aria-hidden>/</span>
                        <Link href={route('projects.show', [workspace.slug, project.slug])} className="inline-flex items-center gap-1.5 hover:text-foreground hover:underline">
                            <span className="size-4 rounded-[3px] grid place-items-center" style={{ background: project.color || '#0E83DC' }}>
                                <Kanban className="size-2.5 text-white" />
                            </span>
                            {project.name}
                        </Link>
                    </nav>
                    <div className="flex items-center justify-between gap-4 mt-2">
                        <h1 className="m-0 text-2xl font-semibold tracking-[-0.01em] text-foreground">{board.name}</h1>
                        <div className="flex items-center gap-2">
                            <Link href={route('boards.edit', [workspace.slug, project.slug, board.slug])}>
                                <Button variant="ghost" size="icon" className="size-8 text-muted-foreground" title="Board settings">
                                    <Settings className="size-4" />
                                </Button>
                            </Link>
                            <Button
                                size="sm"
                                className="h-8 gap-1.5"
                                onClick={() => {
                                    const firstColumn = [...board.columns].sort((a, b) => a.position - b.position)[0];
                                    if (firstColumn) setCreateInColumnId(firstColumn.id);
                                }}
                            >
                                <Plus className="size-4" />Create
                            </Button>
                        </div>
                    </div>

                    {/* View tabs */}
                    <div className="flex items-center gap-4 mt-3 border-b border-border" role="tablist">
                        {(['kanban', 'list', 'gantt', 'calendar'] as const).map(v => {
                            const icons = { kanban: Kanban, list: List, gantt: GanttChart, calendar: Calendar };
                            const labels = { kanban: 'Board', list: 'List', gantt: 'Timeline', calendar: 'Calendar' };
                            const Ic = icons[v];
                            return (
                                <button
                                    key={v}
                                    role="tab"
                                    aria-selected={currentView === v}
                                    onClick={() => setCurrentView(v)}
                                    className={cn(
                                        'relative -mb-px inline-flex items-center gap-1.5 pb-2 pt-1 text-[14px] font-medium border-b-2 transition-colors',
                                        currentView === v
                                            ? 'border-primary text-primary'
                                            : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border',
                                    )}
                                >
                                    <Ic className="size-3.5" />
                                    {labels[v]}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* ─── Toolbar: search · people · quick filters ─── */}
                <div className="flex items-center gap-3 px-6 py-3 flex-wrap shrink-0">
                    <div className="relative">
                        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 size-[14px] text-muted-foreground" />
                        <Input
                            type="search"
                            placeholder="Search board"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="pl-8 h-8 w-44 text-[13px] bg-card"
                        />
                    </div>

                    {/* People: click an avatar to show only their issues */}
                    <div className="flex items-center">
                        {projectUsers.slice(0, 6).map((u, i) => {
                            const active = assigneeFilter === `user-${u.id}` || (assigneeFilter === 'me' && u.id === currentUserId);
                            return (
                                <button
                                    key={u.id}
                                    type="button"
                                    onClick={() => setAssigneeFilter(active ? 'any' : `user-${u.id}`)}
                                    title={active ? `Showing ${u.name}'s issues — click to clear` : `Show ${u.name}'s issues`}
                                    aria-pressed={active}
                                    className={cn(
                                        'relative rounded-full transition-transform hover:-translate-y-0.5 hover:z-10',
                                        i > 0 && '-ml-1',
                                        active && 'z-10',
                                    )}
                                >
                                    <Avatar className={cn('size-8 ring-2 ring-background', active && 'ring-primary')}>
                                        {u.avatar_display && <AvatarImage src={u.avatar_display} alt={u.name} />}
                                        <AvatarFallback className="text-[11px] bg-brand-slate text-white font-bold">{u.name.charAt(0)}</AvatarFallback>
                                    </Avatar>
                                </button>
                            );
                        })}
                        {projectUsers.length > 6 && (
                            <span className="-ml-1 size-8 rounded-full bg-muted ring-2 ring-background grid place-items-center text-[11px] font-semibold text-muted-foreground">
                                +{projectUsers.length - 6}
                            </span>
                        )}
                    </div>

                    {/* Quick filters */}
                    <div className="flex items-center gap-1">
                        <QuickFilterChip
                            active={assigneeFilter === 'me'}
                            onClick={() => setAssigneeFilter(assigneeFilter === 'me' ? 'any' : 'me')}
                            label="Only my issues"
                        />
                        <QuickFilterChip
                            active={quickFilters.includes('recent')}
                            onClick={() => toggleQuickFilter('recent')}
                            label="Recently updated"
                        />
                        <QuickFilterChip
                            active={quickFilters.includes('due')}
                            onClick={() => toggleQuickFilter('due')}
                            label="Due soon"
                        />
                        <QuickFilterChip
                            active={quickFilters.includes('blocked')}
                            onClick={() => toggleQuickFilter('blocked')}
                            label="Blocked"
                        />
                    </div>

                    {/* push search + filter to the right */}
                    <div className="flex-1" />


                        {/* Filter */}
                        <Popover open={filterOpen} onOpenChange={setFilterOpen}>
                            <PopoverTrigger asChild>
                                <button className={cn(
                                    'inline-flex items-center gap-1.5 h-8 px-2.5 rounded-[4px] text-[13px] font-medium border cursor-pointer transition-colors',
                                    activeFiltersCount > 0
                                        ? 'bg-accent border-transparent text-accent-foreground'
                                        : 'bg-card border-border text-muted-foreground hover:bg-secondary',
                                )}>
                                    <Filter className="size-3.5" />
                                    {activeFiltersCount ? `Filter (${activeFiltersCount})` : 'Filter'}
                                </button>
                            </PopoverTrigger>
                            <PopoverContent className="w-80 p-4 space-y-4" align="end">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                                        Filters
                                    </p>
                                    {activeFiltersCount > 0 && (
                                        <button
                                            type="button"
                                            className="text-xs text-primary hover:underline"
                                            onClick={() => {
                                                setAssigneeFilter('any');
                                                setAssigneeSearch('');
                                                setAssigneePopoverOpen(false);
                                                setStatusFilter([]);
                                                setLabelFilter([]);
                                                setPriorityFilter([]);
                                                setTypeFilter([]);
                                                setQuickFilters([]);
                                            }}
                                        >
                                            Clear all
                                        </button>
                                    )}
                                </div>

                                {/* Assignee */}
                                <div className="space-y-2">
                                    <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">
                                        Assignee
                                    </p>
                                    <Popover
                                        open={assigneePopoverOpen}
                                        onOpenChange={(open) => {
                                            setAssigneePopoverOpen(open);
                                            if (!open) setAssigneeSearch('');
                                        }}
                                    >
                                        <PopoverTrigger asChild>
                                            <button
                                                type="button"
                                                className="w-full flex items-center justify-between px-2.5 py-1.5 rounded-md border text-xs bg-background hover:bg-muted transition-colors"
                                            >
                                                <span className="truncate max-w-[160px]">
                                                    {assigneeFilter === 'any'
                                                        ? 'Any user'
                                                        : assigneeFilter === 'me'
                                                            ? 'Me'
                                                            : assigneeFilter === 'unassigned'
                                                                ? 'Unassigned'
                                                                : (() => {
                                                                    const id = Number(assigneeFilter.split('-')[1] || '');
                                                                    const u = projectUsers.find((usr) => usr.id === id);
                                                                    return u ? u.name : 'Any user';
                                                                })()}
                                                </span>
                                                <ChevronDown className="size-3 text-muted-foreground" />
                                            </button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-64 p-2 space-y-2" align="start">
                                            <Input
                                                placeholder="Search people…"
                                                value={assigneeSearch}
                                                onChange={(e) => setAssigneeSearch(e.target.value)}
                                                className="h-8 text-xs"
                                            />
                                            <div className="max-h-44 overflow-y-auto space-y-1 pt-1">
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setAssigneeFilter('any');
                                                        setAssigneePopoverOpen(false);
                                                    }}
                                                    className={cn(
                                                        'w-full flex items-center justify-between px-2.5 py-1.5 rounded-md text-xs transition-colors',
                                                        assigneeFilter === 'any'
                                                            ? 'bg-primary/10 text-primary font-semibold'
                                                            : 'hover:bg-muted text-muted-foreground',
                                                    )}
                                                >
                                                    <span>Any user</span>
                                                    {assigneeFilter === 'any' && <Check className="size-3" />}
                                                </button>
                                                {currentUserId && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setAssigneeFilter('me');
                                                            setAssigneePopoverOpen(false);
                                                        }}
                                                        className={cn(
                                                            'w-full flex items-center justify-between px-2.5 py-1.5 rounded-md text-xs transition-colors',
                                                            assigneeFilter === 'me'
                                                                ? 'bg-primary/10 text-primary font-semibold'
                                                                : 'hover:bg-muted text-muted-foreground',
                                                        )}
                                                    >
                                                        <span>Me</span>
                                                        {assigneeFilter === 'me' && <Check className="size-3" />}
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setAssigneeFilter('unassigned');
                                                        setAssigneePopoverOpen(false);
                                                    }}
                                                    className={cn(
                                                        'w-full flex items-center justify-between px-2.5 py-1.5 rounded-md text-xs transition-colors',
                                                        assigneeFilter === 'unassigned'
                                                            ? 'bg-primary/10 text-primary font-semibold'
                                                            : 'hover:bg-muted text-muted-foreground',
                                                    )}
                                                >
                                                    <span>Unassigned</span>
                                                    {assigneeFilter === 'unassigned' && <Check className="size-3" />}
                                                </button>
                                                {projectUsers
                                                    .filter((u) =>
                                                        !assigneeSearch
                                                            ? true
                                                            : u.name.toLowerCase().includes(assigneeSearch.toLowerCase()) ||
                                                            u.email.toLowerCase().includes(assigneeSearch.toLowerCase()),
                                                    )
                                                    .map((user) => {
                                                        const value = `user-${user.id}`;
                                                        const active = assigneeFilter === value;
                                                        return (
                                                            <button
                                                                key={user.id}
                                                                type="button"
                                                                onClick={() => {
                                                                    setAssigneeFilter(value);
                                                                    setAssigneePopoverOpen(false);
                                                                }}
                                                                className={cn(
                                                                    'w-full flex items-center justify-between px-2.5 py-1.5 rounded-md text-xs transition-colors',
                                                                    active
                                                                        ? 'bg-primary/10 text-primary font-semibold'
                                                                        : 'hover:bg-muted text-muted-foreground',
                                                                )}
                                                            >
                                                                <div className="flex items-center gap-2">
                                                                    <Avatar className="size-5">
                                                                        {user.avatar_display && (
                                                                            <AvatarImage src={user.avatar_display} alt={user.name} />
                                                                        )}
                                                                        <AvatarFallback className="text-[10px]">
                                                                            {user.name.charAt(0).toUpperCase()}
                                                                        </AvatarFallback>
                                                                    </Avatar>
                                                                    <span className="truncate max-w-[140px]">{user.name}</span>
                                                                </div>
                                                                {active && <Check className="size-3" />}
                                                            </button>
                                                        );
                                                    })}
                                            </div>
                                        </PopoverContent>
                                    </Popover>
                                </div>

                                {/* Status / Columns */}
                                {board.columns.length > 0 && (
                                    <div className="space-y-2">
                                        <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">
                                            Status
                                        </p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {board.columns.map((col) => {
                                                const active = statusFilter.includes(col.id);
                                                return (
                                                    <button
                                                        key={col.id}
                                                        type="button"
                                                        onClick={() =>
                                                            setStatusFilter((prev) =>
                                                                prev.includes(col.id)
                                                                    ? prev.filter((id) => id !== col.id)
                                                                    : [...prev, col.id],
                                                            )
                                                        }
                                                        className={cn(
                                                            'flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs border transition-all',
                                                            active
                                                                ? 'border-primary bg-primary/10 text-primary font-semibold'
                                                                : 'border-transparent bg-muted/60 text-muted-foreground hover:bg-muted',
                                                        )}
                                                    >
                                                        <span
                                                            className="size-2 rounded-full"
                                                            style={{ backgroundColor: col.color || '#94a3b8' }}
                                                        />
                                                        {col.name}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                {/* Labels */}
                                {projectLabels.length > 0 && (
                                    <div className="space-y-2">
                                        <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">
                                            Labels
                                        </p>
                                        <div className="flex flex-wrap gap-1.5 max-h-32 overflow-y-auto pr-1">
                                            {projectLabels.map((lbl) => {
                                                const active = labelFilter.includes(lbl.id);
                                                return (
                                                    <button
                                                        key={lbl.id}
                                                        type="button"
                                                        onClick={() =>
                                                            setLabelFilter((prev) =>
                                                                prev.includes(lbl.id)
                                                                    ? prev.filter((id) => id !== lbl.id)
                                                                    : [...prev, lbl.id],
                                                            )
                                                        }
                                                        className={cn(
                                                            'flex items-center gap-1.5 h-7 px-2.5 rounded-full text-[11px] border transition-all',
                                                            active
                                                                ? 'font-semibold'
                                                                : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground',
                                                        )}
                                                        style={
                                                            active
                                                                ? {
                                                                    backgroundColor: `${lbl.color}22`,
                                                                    borderColor: lbl.color ?? undefined,
                                                                    color: lbl.color ?? undefined,
                                                                }
                                                                : { borderColor: `${lbl.color}66` }
                                                        }
                                                    >
                                                        <span
                                                            className="size-2 rounded-full shrink-0"
                                                            style={{ backgroundColor: lbl.color ?? '#94a3b8' }}
                                                        />
                                                        {lbl.name}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                {/* Priority */}
                                {priorities.length > 0 && (
                                    <div className="space-y-2">
                                        <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">
                                            Priority
                                        </p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {priorities.map((p) => {
                                                const active = priorityFilter.includes(p.id);
                                                return (
                                                    <button
                                                        key={p.id}
                                                        type="button"
                                                        onClick={() =>
                                                            setPriorityFilter((prev) =>
                                                                prev.includes(p.id)
                                                                    ? prev.filter((id) => id !== p.id)
                                                                    : [...prev, p.id],
                                                            )
                                                        }
                                                        className={cn(
                                                            'flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs border transition-all',
                                                            active
                                                                ? 'border-primary bg-primary/10 text-primary font-semibold'
                                                                : 'border-transparent bg-muted/60 text-muted-foreground hover:bg-muted',
                                                        )}
                                                    >
                                                        <PriorityIndicator slug={p.slug} className="[&_svg]:size-3" />
                                                        {p.name}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                {/* Type */}
                                {itemTypes.length > 0 && (
                                    <div className="space-y-2">
                                        <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">
                                            Type
                                        </p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {itemTypes.map((t) => {
                                                const active = typeFilter.includes(t.id);
                                                return (
                                                    <button
                                                        key={t.id}
                                                        type="button"
                                                        onClick={() =>
                                                            setTypeFilter((prev) =>
                                                                prev.includes(t.id)
                                                                    ? prev.filter((id) => id !== t.id)
                                                                    : [...prev, t.id],
                                                            )
                                                        }
                                                        className={cn(
                                                            'flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs border transition-all',
                                                            active
                                                                ? 'border-primary bg-primary/10 text-primary font-semibold'
                                                                : 'border-transparent bg-muted/60 text-muted-foreground hover:bg-muted',
                                                        )}
                                                    >
                                                        <ItemTypeIcon type={t} className="size-3.5" />
                                                        {t.name}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}
                            </PopoverContent>
                        </Popover>

                </div>

                {/* View Content */}
                {currentView === 'kanban' && (
                    <div className="flex-1 min-h-0 overflow-hidden bg-background">
                        <div className="flex h-full min-h-0 items-stretch gap-3 overflow-x-auto overflow-y-hidden p-[14px_20px_20px]">
                            {filteredColumns.map((column) => (
                                <BoardColumnComponent
                                    key={column.id}
                                    column={column}
                                    workspace={workspace}
                                    project={project}
                                    board={board}
                                    itemTypes={itemTypes}
                                    rootItems={rootItems}
                                    projectUsers={projectUsers}
                                    onEditItem={setEditingItem}
                                    onDragStart={handleDragStart}
                                    onDragEnd={handleDragEnd}
                                    onDragOver={handleDragOver}
                                    onDrop={handleDrop}
                                    isDragOver={dragOverColumn === column.id}
                                    totalItemCount={board.columns.find((c) => c.id === column.id)?.items?.length ?? 0}
                                    createOpen={createInColumnId === column.id}
                                    onCreateOpenChange={(open) => setCreateInColumnId(open ? column.id : null)}
                                />
                            ))}

                            {/* Add Column Button */}
                            <Link href={route('boards.edit', [workspace.slug, project.slug, board.slug])} className="flex-shrink-0 h-full">
                                <button className="w-[282px] h-full min-h-[220px] border border-dashed border-border rounded-[10px] flex flex-col items-center justify-center gap-1.5 text-muted-foreground text-[12.5px] font-medium hover:bg-card hover:text-foreground hover:border-border transition-colors">
                                    <Plus className="size-[14px]" />
                                    <span>New column</span>
                                </button>
                            </Link>
                        </div>
                    </div>
                )}

                {currentView === 'list' && (
                    <ListView
                        columns={filteredColumns}
                        workspace={workspace}
                        project={project}
                        board={board}
                        onEditItem={setEditingItem}
                    />
                )}

                {currentView === 'gantt' && (
                    <GanttView
                        columns={filteredColumns}
                        workspace={workspace}
                        project={project}
                        board={board}
                        onEditItem={setEditingItem}
                        onUpdateItemDates={(item, start_date, due_date) => {
                            router.patch(
                                route('boards.items.update', [workspace.slug, project.slug, board.slug, item.id]),
                                { start_date: start_date || undefined, due_date: due_date || undefined },
                                { preserveScroll: true },
                            );
                        }}
                    />
                )}

                {currentView === 'calendar' && (
                    <CalendarView
                        columns={filteredColumns}
                        workspace={workspace}
                        project={project}
                        board={board}
                        onEditItem={setEditingItem}
                        calendarDate={calendarDate}
                        setCalendarDate={setCalendarDate}
                    />
                )}
            </div>

            {/* Item Edit Modal */}
            <ItemEditModal
                key={editingItem?.id}
                item={editingItem}
                workspace={workspace}
                project={project}
                board={board}
                itemTypes={itemTypes}
                priorities={priorities}
                projectUsers={projectUsers}
                projectLabels={projectLabels}
                projectItems={projectItems}
                currentUserId={auth.user.id}
                columns={board.columns}
                open={!!editingItem}
                onClose={() => setEditingItem(null)}
                onOpenItem={setEditingItem}
            />
        </AppLayout>
    );
}
