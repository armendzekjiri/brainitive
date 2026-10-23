import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, BoardColumn, Item, ItemType, ItemPriority, User, Label as LabelType, ItemField, SavedBoardView } from '@/types';
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
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { buildLanes, canonicalConfig, Lane, MODE_LABELS, SwimlaneMode } from '@/lib/swimlanes';
import { toast } from 'sonner';
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
    Rows3,
    ArrowUp,
    ArrowDown,
    Columns3,
} from 'lucide-react';
import { useState, useRef, useEffect, DragEvent, FormEvent, useCallback, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { Comment, Attachment } from '@/types';
import { ItemTypeIcon } from '@/components/ItemTypeIcon';
import { LabelChip, PriorityIndicator, TypeBadge, AssigneeAvatar, priorityMeta, itemAccentColor } from '@/components/board/ItemPrimitives';
import CompleteSubtasksDialog from '@/components/item/CompleteSubtasksDialog';
import DuplicateItemDialog from '@/components/item/DuplicateItemDialog';
import { itemTree } from '@/lib/itemTree';
import { useItemDetail, ItemDetailMain, ItemDetailSidebar, ItemFilePreviewDialog, type ProjectItemLite } from '@/components/item/ItemDetail';
import { BoardHeader } from '@/components/board/BoardHeader';
import { CustomFieldFilters } from '@/components/board/CustomFieldFilters';
import {
    compareNullable, compareText, fieldComparator, fieldParam, fieldValue, formatFieldValue, fieldOption,
    matchesFieldFilter, readFieldFilters, serializeFieldFilter, type FieldFilter, type FieldFilters, type SortDirection,
} from '@/lib/customFields';
import { ActiveSprintBar, type SprintSummary } from '@/components/board/ActiveSprintBar';
import SavedViewsMenu from '@/components/board/SavedViewsMenu';

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
    activeSprint?: SprintSummary | null;
    plannedSprints?: { id: number; name: string }[];
    canManageSprints?: boolean;
    /** Custom fields that show on this board (BRAI-77). */
    customFields?: ItemField[];
    /** Project admins: link to field settings. */
    canManageFields?: boolean;
    /** May change issues (custom field values are read-only otherwise). */
    canEditItems?: boolean;
    /** Saved views (BRAI-78): own personal + shared views of this board. */
    savedViews?: SavedBoardView[];
    activeViewId?: number | null;
    /** The default view the server applied to a plain board URL — the page writes it into the URL. */
    appliedViewParams?: Record<string, string> | null;
    defaultViewId?: number | null;
    canShareViews?: boolean;
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
    const [duplicating, setDuplicating] = useState(false);

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
                    <DropdownMenuItem onClick={(e) => { e.stopPropagation(); setDuplicating(true); }}>Duplicate…</DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onClick={(e) => { e.stopPropagation(); setConfirmDelete(true); }}
                        className="text-destructive focus:text-destructive"
                    >Delete</DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DuplicateItemDialog
                open={duplicating}
                onOpenChange={setDuplicating}
                url={route('boards.items.duplicate', [workspace.slug, project.slug, board.slug, item.id])}
                itemKey={item.key}
                subtaskCount={subtaskCount}
            />

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
                                        {(r as Item & { depth?: number }).depth ? <span className="text-muted-foreground">{'\u00a0\u00a0↳ '}</span> : null}
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

/** The filters as URL params ('' = not set) — the format saved board views store too. */
function boardFilterParams(filters: BoardFilters, fields: ItemField[] = [], fieldFilters: FieldFilters = {}): Record<string, string> {
    const params: Record<string, string> = {};
    fields.forEach((field) => { params[fieldParam(field.id)] = serializeFieldFilter(fieldFilters[field.id]); });
    params.q = filters.q.trim();
    params.assignee = filters.assignee === 'any' ? '' : filters.assignee;
    params.status = filters.status.join(',');
    params.labels = filters.labels.join(',');
    params.priority = filters.priority.join(',');
    params.type = filters.type.join(',');
    // fixed order (as the server stores it), not click order — keeps saved-view comparisons stable
    params.quick = QUICK_FILTERS.filter((f) => filters.quick.includes(f)).join(',');
    return params;
}

/** A saved view is open (`?bv=<id>`): the URL alone describes the board, nothing comes from the remembered setup. */
function inSavedView(): boolean {
    if (typeof window === 'undefined') return false;
    return Number(new URLSearchParams(window.location.search).get('bv') ?? 0) > 0;
}

/** The default view the server applied to the plain board URL: write its params (+ bv) into the URL, keeping #item-N. */
function applyViewParamsToUrl(params: Record<string, string>, viewId: number) {
    const url = new URL(window.location.href);
    for (const [key, value] of Object.entries(params)) if (value) url.searchParams.set(key, value);
    url.searchParams.set('bv', String(viewId));
    if (url.href !== window.location.href) window.history.replaceState(window.history.state, '', url.href);
}

type BoardLayout = 'kanban' | 'list' | 'gantt' | 'calendar';
const BOARD_LAYOUTS: BoardLayout[] = ['kanban', 'list', 'gantt', 'calendar'];

/** `?view=` (saved views, backlog/report links) wins; outside a saved view the remembered tab follows. */
function readBoardLayout(storageKey: string): BoardLayout {
    if (typeof window === 'undefined') return 'kanban';
    const fromUrl = new URLSearchParams(window.location.search).get('view');
    let stored: string | null = null;
    if (!inSavedView()) {
        try { stored = window.localStorage.getItem(storageKey); } catch { /* private mode */ }
    }
    const layout = (fromUrl ?? stored) as BoardLayout | null;
    return layout && BOARD_LAYOUTS.includes(layout) ? layout : 'kanban';
}

function writeBoardLayout(storageKey: string, layout: BoardLayout) {
    if (!inSavedView()) {
        try { window.localStorage.setItem(storageKey, layout); } catch { /* private mode */ }
    }
    const url = new URL(window.location.href);
    if (layout === 'kanban') url.searchParams.delete('view'); else url.searchParams.set('view', layout);
    if (url.href !== window.location.href) window.history.replaceState(window.history.state, '', url.href);
}

/** Mirror the filters into the query string without an Inertia visit (keeps other params). */
function writeBoardFilters(filters: BoardFilters, fields: ItemField[] = [], fieldFilters: FieldFilters = {}) {
    const url = new URL(window.location.href);
    for (const [key, value] of Object.entries(boardFilterParams(filters, fields, fieldFilters))) {
        if (value) url.searchParams.set(key, value); else url.searchParams.delete(key);
    }
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

    const [renameOpen, setRenameOpen] = useState(false);
    const [nameValue, setNameValue] = useState(column.name);
    const [columnError, setColumnError] = useState('');

    const saveColumn = (changes: { name?: string; wip_limit?: number | null }, done: () => void) => {
        setColumnError('');
        router.put(route('boards.columns.update', [workspace.slug, project.slug, board.slug, column.id]), {
            name: column.name,
            color: column.color,
            is_done_column: column.is_done_column,
            wip_limit: wipLimit,
            ...changes,
        }, {
            preserveScroll: true,
            onSuccess: done,
            onError: (errors) => setColumnError(Object.values(errors)[0] ?? 'Could not save the column.'),
        });
    };

    const saveWipLimit = (e: FormEvent) => {
        e.preventDefault();
        saveColumn({ wip_limit: wipValue.trim() === '' ? null : Number(wipValue) }, () => setWipDialogOpen(false));
    };

    const saveName = (e: FormEvent) => {
        e.preventDefault();
        if (!nameValue.trim()) return;
        saveColumn({ name: nameValue.trim() }, () => setRenameOpen(false));
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
                            <DropdownMenuItem onClick={() => { setNameValue(column.name); setColumnError(''); setRenameOpen(true); }}>
                                Rename column
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => { setWipValue(wipLimit ? String(wipLimit) : ''); setColumnError(''); setWipDialogOpen(true); }}>
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
                            {columnError && <p className="text-sm text-destructive">{columnError}</p>}
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

            <Dialog open={renameOpen} onOpenChange={setRenameOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Rename column</DialogTitle>
                        <DialogDescription>Cards stay in the column; only its name changes.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={saveName} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor={`name-${column.id}`}>Name</Label>
                            <Input id={`name-${column.id}`} value={nameValue} onChange={(e) => setNameValue(e.target.value)} maxLength={255} autoFocus required />
                            {columnError && <p className="text-sm text-destructive">{columnError}</p>}
                        </div>
                        <div className="flex justify-end">
                            <Button type="submit" disabled={!nameValue.trim()}>Save</Button>
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

// Swimlanes: rows across the board; cards placed by laneOf (lib/swimlanes.ts).
function SwimlaneBoard({
    columns,
    lanes,
    laneOf,
    showEmptyLanes,
    workspace,
    project,
    board,
    projectUsers,
    onEditItem,
    onDragStart,
    onDragEnd,
    onDropCell,
}: {
    columns: (BoardColumn & { items: Item[] })[];
    lanes: Lane[];
    laneOf: (item: Item) => string;
    showEmptyLanes: boolean;
    workspace: Workspace;
    project: Project;
    board: Board;
    projectUsers: User[];
    onEditItem: (item: Item) => void;
    onDragStart: (e: DragEvent, item: Item) => void;
    onDragEnd: () => void;
    onDropCell: (e: DragEvent, column: BoardColumn, lane: Lane) => void;
}) {
    const storageKey = `board-${board.id}-collapsed-lanes`;
    const [collapsed, setCollapsed] = useState<Set<string>>(() => {
        try {
            return new Set(JSON.parse(localStorage.getItem(storageKey) ?? '[]'));
        } catch {
            return new Set();
        }
    });
    const [over, setOver] = useState<string | null>(null);
    const toggle = (id: string) => setCollapsed((prev) => {
        const next = new Set(prev);
        if (next.has(id)) next.delete(id); else next.add(id);
        try { localStorage.setItem(storageKey, JSON.stringify([...next])); } catch { /* private mode */ }
        return next;
    });

    // Subtasks shown under their parent (same column) follow the parent's lane.
    const cells = useMemo(() => {
        const map = new Map<string, { top: Item[]; subs: Map<number, Item[]> }>();
        for (const column of columns) {
            const items = column.items ?? [];
            const ids = new Set(items.map((i) => i.id));
            const byId = new Map(items.map((i) => [i.id, i]));
            const nested = (i: Item) => i.item_type?.slug === 'subtask' && i.parent_id != null && ids.has(i.parent_id);
            for (const item of items) {
                const owner = nested(item) ? byId.get(item.parent_id!)! : item;
                const key = `${laneOf(owner)}|${column.id}`;
                const cell = map.get(key) ?? { top: [] as Item[], subs: new Map<number, Item[]>() };
                if (nested(item)) cell.subs.set(item.parent_id!, [...(cell.subs.get(item.parent_id!) ?? []), item]);
                else cell.top.push(item);
                map.set(key, cell);
            }
        }
        return map;
    }, [columns, laneOf]);

    const laneCount = (lane: Lane) => columns.reduce((n, c) => n + (cells.get(`${lane.id}|${c.id}`)?.top.length ?? 0), 0);
    const visible = lanes.filter((lane) => showEmptyLanes || laneCount(lane) > 0);
    const width = 'w-[272px]';

    return (
        <div className="flex-1 min-h-0 overflow-auto bg-background">
            <div className="inline-flex min-w-full flex-col gap-2 p-[14px_20px_20px]">
                <div className="sticky top-0 z-10 -mt-[14px] flex gap-3 bg-background pt-[14px] pb-1">
                    {columns.map((c) => (
                        <div key={c.id} className={cn(width, 'shrink-0 rounded-md bg-muted/60 dark:bg-secondary/30 px-3 py-2 text-[12px] font-semibold uppercase tracking-[0.03em] text-muted-foreground')}>
                            {c.name} <span className="ml-1">{c.items?.length ?? 0}</span>
                        </div>
                    ))}
                </div>

                {visible.map((lane) => {
                    const count = laneCount(lane);
                    const isCollapsed = collapsed.has(lane.id);
                    return (
                        <section key={lane.id} aria-label={`Lane ${lane.name}`} className="rounded-md border border-border/60">
                            <button
                                type="button"
                                onClick={() => toggle(lane.id)}
                                aria-expanded={!isCollapsed}
                                className="sticky left-0 flex w-max max-w-full items-center gap-2 px-3 py-2 text-sm font-semibold hover:text-foreground"
                            >
                                <ChevronRight className={cn('size-4 text-muted-foreground transition-transform', !isCollapsed && 'rotate-90')} />
                                {lane.color && <span className="size-2.5 rounded-full" style={{ backgroundColor: lane.color }} />}
                                <span>{lane.name}</span>
                                <span className="text-xs font-medium text-muted-foreground">{count} {count === 1 ? 'card' : 'cards'}</span>
                            </button>
                            {!isCollapsed && (
                                <div className="flex gap-3 px-0 pb-2">
                                    {columns.map((column) => {
                                        const cell = cells.get(`${lane.id}|${column.id}`);
                                        const key = `${lane.id}|${column.id}`;
                                        return (
                                            <div
                                                key={column.id}
                                                onDragOver={(e) => { e.preventDefault(); setOver(key); }}
                                                onDragLeave={() => setOver((o) => (o === key ? null : o))}
                                                onDrop={(e) => { setOver(null); onDropCell(e, column, lane); }}
                                                className={cn(width, 'shrink-0 min-h-[56px] flex flex-col gap-1.5 rounded-md bg-muted/40 dark:bg-secondary/20 p-1.5 transition-colors', over === key && 'bg-primary/[0.07] ring-2 ring-primary/30')}
                                            >
                                                {cell?.top.map((item) => (
                                                    <ItemCard
                                                        key={item.id}
                                                        item={item}
                                                        workspace={workspace}
                                                        project={project}
                                                        board={board}
                                                        projectUsers={projectUsers}
                                                        subtasks={cell.subs.get(item.id)}
                                                        onEdit={onEditItem}
                                                        onDragStart={onDragStart}
                                                        onDragEnd={onDragEnd}
                                                    />
                                                ))}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </section>
                    );
                })}
                {visible.length === 0 && <p className="py-10 text-center text-sm text-muted-foreground">No cards match the current filters.</p>}
            </div>
        </div>
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
    customFields,
    canEditItems,
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
    customFields: ItemField[];
    canEditItems: boolean;
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
        customFields,
        canEditItems,
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
type ListSortKey = 'key' | 'title' | 'status' | 'priority' | 'assignee' | 'due_date' | 'story_points' | `f${number}`;
interface ListSort { key: ListSortKey; dir: SortDirection }

const keyNumber = (item: Item) => Number(/(\d+)$/.exec(item.key)?.[1] ?? 0);

/** `?sort=due_date` / `?sort=-f12` (descending). */
function readListSort(): ListSort | null {
    const raw = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('sort') ?? '';
    const dir: SortDirection = raw.startsWith('-') ? 'desc' : 'asc';
    const key = raw.replace(/^-/, '');
    const valid = ['key', 'title', 'status', 'priority', 'assignee', 'due_date', 'story_points'].includes(key) || /^f\d+$/.test(key);
    return valid ? { key: key as ListSortKey, dir } : null;
}

function writeListSort(sort: ListSort | null) {
    const url = new URL(window.location.href);
    if (sort) url.searchParams.set('sort', `${sort.dir === 'desc' ? '-' : ''}${sort.key}`); else url.searchParams.delete('sort');
    if (url.href !== window.location.href) window.history.replaceState(window.history.state, '', url.href);
}

/** Hidden custom-field columns of the list: `?hide=` (saved views) wins over the stored choice. */
function readHiddenFields(boardId: number): number[] {
    const fromUrl = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('hide');
    if (fromUrl !== null) return parseIds(fromUrl);
    if (inSavedView()) return []; // the view shows all columns
    try {
        const stored = JSON.parse(window.localStorage.getItem(`board-${boardId}-list-hidden-fields`) ?? '[]');
        return Array.isArray(stored) ? stored.filter((n) => Number.isInteger(n)) : [];
    } catch { return []; }
}

function writeHiddenFields(boardId: number, ids: number[]) {
    // a saved view's columns never become your remembered choice for the plain board
    if (!inSavedView()) {
        try { window.localStorage.setItem(`board-${boardId}-list-hidden-fields`, JSON.stringify(ids)); } catch { /* private mode */ }
    }
    const url = new URL(window.location.href);
    if (ids.length) url.searchParams.set('hide', ids.join(',')); else url.searchParams.delete('hide');
    if (url.href !== window.location.href) window.history.replaceState(window.history.state, '', url.href);
}

function SortHeader({ label, sortKey, sort, onSort, className }: {
    label: string; sortKey: ListSortKey; sort: ListSort | null; onSort: (key: ListSortKey) => void; className?: string;
}) {
    const active = sort?.key === sortKey;
    return (
        <th className={cn('pb-3 font-medium', className)} aria-sort={active ? (sort!.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
            <button type="button" onClick={() => onSort(sortKey)} className={cn('inline-flex items-center gap-1 hover:text-foreground transition-colors', active && 'text-foreground')}>
                <span className="truncate max-w-[160px]">{label}</span>
                {active && (sort!.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />)}
            </button>
        </th>
    );
}

function FieldCell({ field, item }: { field: ItemField; item: Item }) {
    const value = fieldValue(item, field);
    if (value === null) return <span className="text-muted-foreground text-sm">—</span>;
    if (field.type === 'select') {
        const option = fieldOption(field, value);
        return (
            <span className={cn('inline-flex items-center gap-1.5 text-sm', option?.archived_at && 'text-muted-foreground')}>
                {option?.color && <span className="size-2 rounded-full shrink-0" style={{ backgroundColor: option.color }} />}
                {option?.label}
            </span>
        );
    }
    const text = formatFieldValue(field, value);
    return <span className={cn('text-sm', field.type === 'number' && 'tabular-nums', field.type === 'text' && 'block max-w-[220px] truncate')} title={text}>{text}</span>;
}

function ListView({
    columns,
    workspace,
    project,
    board,
    onEditItem,
    customFields,
    sort,
    setSort,
    hiddenFieldIds,
    setHiddenFieldIds,
}: {
    columns: (BoardColumn & { items: Item[] })[];
    workspace: Workspace;
    project: Project;
    board: Board;
    onEditItem: (item: Item) => void;
    customFields: ItemField[];
    /** Kept by the page (URL + saved views). */
    sort: ListSort | null;
    setSort: React.Dispatch<React.SetStateAction<ListSort | null>>;
    hiddenFieldIds: number[];
    setHiddenFieldIds: React.Dispatch<React.SetStateAction<number[]>>;
}) {
    const allItems = columns.flatMap(col => col.items.map(item => ({ ...item, columnName: col.name })));

    // Hidden custom field columns, per board and person (new fields show up by default)
    const toggleFieldColumn = (id: number) => setHiddenFieldIds((current) => current.includes(id) ? current.filter((x) => x !== id) : [...current, id]);
    const fieldColumns = customFields.filter((f) => !hiddenFieldIds.includes(f.id));

    // Sorting: click a header for ascending, again for descending, a third time to reset.
    const onSort = (key: ListSortKey) => setSort((current) =>
        current?.key !== key ? { key, dir: 'asc' } : current.dir === 'asc' ? { key, dir: 'desc' } : null);

    // A sort on a field column that is hidden (or not on this board) would be invisible: drop it.
    const sortHidden = !!sort && sort.key.startsWith('f') && !fieldColumns.some((f) => `f${f.id}` === sort.key);
    useEffect(() => { if (sortHidden) setSort(null); }, [sortHidden]);

    const columnRank = useMemo(() => new Map(columns.map((c, index) => [c.id, index])), [columns]);
    const sortedItems = useMemo(() => {
        if (!sort || sortHidden) return allItems;
        const field = sort.key.startsWith('f') ? customFields.find((f) => `f${f.id}` === sort.key) : undefined;
        const { dir } = sort;
        const compare = (a: Item, b: Item): number => {
            if (field) return fieldComparator(field, dir)(a, b);
            switch (sort.key) {
                case 'key': return compareNullable(keyNumber(a), keyNumber(b), dir, (x, y) => x - y);
                case 'title': return compareNullable(a.title, b.title, dir, compareText);
                case 'status': return compareNullable(columnRank.get(a.column_id as number), columnRank.get(b.column_id as number), dir, (x, y) => x - y);
                case 'priority': return compareNullable(a.priority?.order, b.priority?.order, dir, (x, y) => x - y);
                case 'assignee': return compareNullable(a.assignee?.name, b.assignee?.name, dir, compareText);
                case 'due_date': return compareNullable(a.due_date ? String(a.due_date).slice(0, 10) : null, b.due_date ? String(b.due_date).slice(0, 10) : null, dir, (x, y) => x.localeCompare(y));
                case 'story_points': return compareNullable(a.story_points ?? null, b.story_points ?? null, dir, (x, y) => x - y);
                default: return 0;
            }
        };
        // Ties keep a stable order by key
        return [...allItems].sort((a, b) => compare(a, b) || keyNumber(a) - keyNumber(b));
    }, [allItems, sort, sortHidden, customFields, columnRank]);

    const columnCount = 8 + fieldColumns.length;

    return (
        <div className="flex-1 overflow-auto bg-background">
            <div className="p-6">
                {customFields.length > 0 && (
                    <div className="mb-3 flex justify-end">
                        <Popover>
                            <PopoverTrigger asChild>
                                <button type="button" className="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-[4px] text-[13px] font-medium border bg-card border-border text-muted-foreground hover:bg-secondary">
                                    <Columns3 className="size-3.5" />
                                    Fields ({fieldColumns.length}/{customFields.length})
                                </button>
                            </PopoverTrigger>
                            <PopoverContent className="w-60 p-2" align="end">
                                <p className="px-2 pb-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Show columns</p>
                                <div className="max-h-72 overflow-y-auto">
                                    {customFields.map((field) => {
                                        const shown = !hiddenFieldIds.includes(field.id);
                                        return (
                                            <button
                                                key={field.id}
                                                type="button"
                                                aria-pressed={shown}
                                                onClick={() => toggleFieldColumn(field.id)}
                                                className="w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-md text-sm hover:bg-muted"
                                            >
                                                <span className="truncate">{field.name}</span>
                                                {shown && <Check className="size-3.5 text-primary shrink-0" />}
                                            </button>
                                        );
                                    })}
                                </div>
                            </PopoverContent>
                        </Popover>
                    </div>
                )}
                <table className="w-full">
                    <thead>
                        <tr className="border-b text-left text-sm text-muted-foreground">
                            <SortHeader label="Key" sortKey="key" sort={sort} onSort={onSort} className="pl-4" />
                            <SortHeader label="Title" sortKey="title" sort={sort} onSort={onSort} />
                            <th className="pb-3 font-medium">Parent</th>
                            <SortHeader label="Status" sortKey="status" sort={sort} onSort={onSort} />
                            <SortHeader label="Priority" sortKey="priority" sort={sort} onSort={onSort} />
                            <SortHeader label="Assignee" sortKey="assignee" sort={sort} onSort={onSort} />
                            <SortHeader label="Due Date" sortKey="due_date" sort={sort} onSort={onSort} />
                            <SortHeader label="Story Points" sortKey="story_points" sort={sort} onSort={onSort} className={fieldColumns.length ? '' : 'pr-4'} />
                            {fieldColumns.map((field, index) => (
                                <SortHeader key={field.id} label={field.name} sortKey={`f${field.id}`} sort={sort} onSort={onSort} className={cn('pl-3', index === fieldColumns.length - 1 && 'pr-4')} />
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {sortedItems.map((item) => (
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
                                <td className={cn('py-3', !fieldColumns.length && 'pr-4')}>
                                    {item.story_points ? (
                                        <Badge variant="outline" className="font-mono">{item.story_points}</Badge>
                                    ) : (
                                        <span className="text-muted-foreground text-sm">—</span>
                                    )}
                                </td>
                                {fieldColumns.map((field, index) => (
                                    <td key={field.id} className={cn('py-3 pl-3', index === fieldColumns.length - 1 && 'pr-4')}>
                                        <FieldCell field={field} item={item} />
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {sortedItems.length === 0 && (
                            <tr>
                                <td colSpan={columnCount} className="py-12 text-center text-muted-foreground">
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
/** Creates a column in place: it shows up right away (the board reloads), no trip to settings. */
function NewColumnTile({ workspace, project, board }: { workspace: Workspace; project: Project; board: Board }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ name: '' });

    const close = () => {
        setEditing(false);
        reset();
        clearErrors();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!data.name.trim()) return;
        post(route('boards.columns.store', [workspace.slug, project.slug, board.slug]), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => reset(),
        });
    };

    if (!editing) {
        return (
            <button
                type="button"
                onClick={() => setEditing(true)}
                className="flex-shrink-0 w-[282px] h-full min-h-[220px] border border-dashed border-border rounded-[10px] flex flex-col items-center justify-center gap-1.5 text-muted-foreground text-[12.5px] font-medium hover:bg-card hover:text-foreground hover:border-border transition-colors"
            >
                <Plus className="size-[14px]" />
                <span>New column</span>
            </button>
        );
    }

    return (
        <form onSubmit={submit} className="flex-shrink-0 w-[282px] self-start rounded-[10px] border bg-card p-2.5 space-y-2">
            <Input
                value={data.name}
                onChange={(e) => setData('name', e.target.value)}
                onKeyDown={(e) => e.key === 'Escape' && close()}
                placeholder="Column name, e.g. Review"
                maxLength={255}
                autoFocus
            />
            {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
            <div className="flex items-center gap-2">
                <Button type="submit" size="sm" disabled={processing || !data.name.trim()}>Add column</Button>
                <Button type="button" size="sm" variant="ghost" onClick={close}>Done</Button>
                <Link href={route('boards.edit', [workspace.slug, project.slug, board.slug])} className="ml-auto text-xs text-muted-foreground hover:text-foreground">
                    Settings
                </Link>
            </div>
        </form>
    );
}

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
    activeSprint = null,
    plannedSprints = [],
    canManageSprints = false,
    customFields = [],
    canManageFields = false,
    canEditItems = true,
    savedViews = [],
    activeViewId = null,
    appliedViewParams = null,
    defaultViewId = null,
    canShareViews = false,
}: Props) {
    // The default view applied by the server: into the URL before any URL-backed state below reads
    // it — then into Inertia's page too (a client-side visit, no request), or Inertia's first page
    // load puts the plain URL back
    useState(() => {
        if (appliedViewParams && activeViewId && typeof window !== 'undefined') applyViewParamsToUrl(appliedViewParams, activeViewId);
    });
    useEffect(() => {
        if (!appliedViewParams || !activeViewId) return;
        const url = new URL(window.location.href);
        for (const [key, value] of Object.entries(appliedViewParams)) if (value) url.searchParams.set(key, value);
        url.searchParams.set('bv', String(activeViewId));
        router.replace({ url: url.pathname + url.search + url.hash, preserveState: true, preserveScroll: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const { flash } = usePage<PageProps>().props;
    const itemTypes = boardItemTypes;
    const [editingItem, setEditingItem] = useState<Item | null>(null);

    // Links like …/boards/x#item-123 (search, command palette, AI connector) open that issue;
    // one that isn't on the board right now (e.g. outside the running sprint) opens its own page.
    useEffect(() => {
        const openFromHash = () => {
            const match = window.location.hash.match(/^#item-(\d+)$/);
            if (!match) return;
            const id = Number(match[1]);
            const found = board.columns.flatMap((c) => c.items ?? []).find((i) => i.id === id);
            if (found) setEditingItem(found);
            else router.visit(route('boards.items.show', [workspace.slug, project.slug, board.slug, id]));
        };
        openFromHash();
        window.addEventListener('hashchange', openFromHash);
        return () => window.removeEventListener('hashchange', openFromHash);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const closeItem = useCallback(() => {
        setEditingItem(null);
        if (window.location.hash.startsWith('#item-')) {
            window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
        }
    }, []);
    const [draggedItem, setDraggedItem] = useState<Item | null>(null);
    const [dragOverColumn, setDragOverColumn] = useState<number | null>(null);
    // Filters live in the URL so a filtered board can be shared or bookmarked
    const initialFilters = useMemo(readBoardFilters, []);
    const [searchQuery, setSearchQuery] = useState(initialFilters.q);
    const layoutKey = `board-view-${project.id}-${board.id}`;
    const [currentView, setCurrentView] = useState<BoardLayout>(() => readBoardLayout(layoutKey));
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
    const [fieldFilters, setFieldFilters] = useState<FieldFilters>(() =>
        readFieldFilters(new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search), customFields));
    const setFieldFilter = (fieldId: number, filter: FieldFilter | null) =>
        setFieldFilters((current) => {
            const next = { ...current };
            if (filter) next[fieldId] = filter; else delete next[fieldId];
            return next;
        });
    const [createInColumnId, setCreateInColumnId] = useState<number | null>(null);
    const currentUserId = auth.user?.id;

    // List sort + hidden list columns live here (URL-backed) so saved views can capture them
    const [listSort, setListSort] = useState<ListSort | null>(readListSort);
    useEffect(() => writeListSort(listSort), [listSort]);
    const [hiddenFieldIds, setHiddenFieldIds] = useState<number[]>(() => readHiddenFields(board.id));
    useEffect(() => writeHiddenFields(board.id, hiddenFieldIds), [board.id, hiddenFieldIds]);

    useEffect(() => {
        writeBoardFilters({
            q: searchQuery,
            assignee: assigneeFilter,
            status: statusFilter,
            labels: labelFilter,
            priority: priorityFilter,
            type: typeFilter,
            quick: quickFilters,
        }, customFields, fieldFilters);
    }, [searchQuery, assigneeFilter, statusFilter, labelFilter, priorityFilter, typeFilter, quickFilters, fieldFilters, customFields]);

    const toggleQuickFilter = (filter: QuickFilter) =>
        setQuickFilters((current) => current.includes(filter) ? current.filter((f) => f !== filter) : [...current, filter]);

    const activeFiltersCount = useMemo(
        () =>
            (assigneeFilter !== 'any' ? 1 : 0) +
            (statusFilter.length ? 1 : 0) +
            (labelFilter.length ? 1 : 0) +
            (priorityFilter.length ? 1 : 0) +
            (typeFilter.length ? 1 : 0) +
            quickFilters.length +
            Object.keys(fieldFilters).length,
        [assigneeFilter, statusFilter.length, labelFilter.length, priorityFilter.length, typeFilter.length, quickFilters.length, fieldFilters],
    );

    // The layout tab lives in the URL too (and is remembered per board outside saved views)
    useEffect(() => writeBoardLayout(layoutKey, currentView), [layoutKey, currentView]);

    // What a saved view captures: the URL view params of the current board setup
    const currentViewParams = useMemo<Record<string, string>>(() => {
        const params = boardFilterParams({
            q: searchQuery, assignee: assigneeFilter, status: statusFilter, labels: labelFilter,
            priority: priorityFilter, type: typeFilter, quick: quickFilters,
        }, customFields, fieldFilters);
        params.sort = listSort ? `${listSort.dir === 'desc' ? '-' : ''}${listSort.key}` : '';
        params.view = currentView;
        params.hide = hiddenFieldIds.filter((id) => customFields.some((f) => f.id === id)).join(',');
        return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== ''));
    }, [searchQuery, assigneeFilter, statusFilter, labelFilter, priorityFilter, typeFilter, quickFilters, customFields, fieldFilters, listSort, currentView, hiddenFieldIds]);

    const draggedElement = useRef<HTMLElement | null>(null);
    const handleDragStart = (e: DragEvent, item: Item) => {
        setDraggedItem(item);
        e.dataTransfer.effectAllowed = 'move';
        // Add visual feedback
        if (e.currentTarget instanceof HTMLElement) {
            e.currentTarget.style.opacity = '0.5';
            draggedElement.current = e.currentTarget;
        }
    };

    const handleDragEnd = () => {
        // Undo the fade even when the drop changed nothing (same column, refused lane, cancel).
        if (draggedElement.current) draggedElement.current.style.opacity = '';
        draggedElement.current = null;
        setDraggedItem(null);
        setDragOverColumn(null);
    };

    const handleDragOver = (e: DragEvent, column: BoardColumn) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        setDragOverColumn(column.id);
    };

    // Dropping a parent on a done column with open subtasks below asks first.
    const [completePrompt, setCompletePrompt] = useState<{ item: Item; column: BoardColumn; openCount: number } | null>(null);
    const boardTree = useMemo(() => itemTree(board.columns.flatMap((c) => c.items ?? [])), [board.columns]);

    const moveItem = (item: Item, column: BoardColumn, withSubtasks = false) =>
        router.patch(
            route('boards.items.move', [workspace.slug, project.slug, board.slug, item.id]),
            { column_id: column.id, position: 0, with_subtasks: withSubtasks },
            { preserveScroll: true },
        );

    const dropToColumn = (item: Item, column: BoardColumn) => {
        if (item.column_id === column.id) return;
        const doneIds = new Set(board.columns.filter((c) => c.is_done_column).map((c) => c.id));
        const open = column.is_done_column ? boardTree.descendants(item.id).filter((d) => !doneIds.has(d.column_id as number)) : [];
        if (open.length > 0) {
            setCompletePrompt({ item, column, openCount: open.length });
        } else {
            moveItem(item, column);
        }
    };

    const handleDrop = (e: DragEvent, column: BoardColumn) => {
        e.preventDefault();
        if (draggedItem) dropToColumn(draggedItem, column);
        setDraggedItem(null);
        setDragOverColumn(null);
    };

    // ── Swimlanes ────────────────────────────────────────────────────────
    const boardLanes = board.settings?.swimlanes ?? null;
    const laneStorageKey = `board-${board.id}-lane-mode`;
    // A personal lane choice only holds for the board setup it was made on: once the board's
    // swimlanes are saved again, everyone starts from the new default.
    const laneSetup = JSON.stringify(canonicalConfig(boardLanes));
    const [laneChoice, setLaneChoice] = useState<{ mode: SwimlaneMode; setup: string } | null>(() => {
        try {
            const stored = JSON.parse(localStorage.getItem(laneStorageKey) ?? 'null');
            if (stored && typeof stored.setup === 'string' && Object.prototype.hasOwnProperty.call(MODE_LABELS, stored.mode)) return stored;
        } catch { /* ignore (also the old plain-string format) */ }
        return null;
    });
    const hasCustomLanes = (boardLanes?.lanes?.length ?? 0) > 0;
    const usable = (mode: SwimlaneMode | undefined) => (mode && (mode !== 'custom' || hasCustomLanes) ? mode : null);
    const laneMode: SwimlaneMode = (laneChoice?.setup === laneSetup ? usable(laneChoice.mode) : null) ?? usable(boardLanes?.mode) ?? 'none';
    const setLaneMode = (mode: SwimlaneMode) => {
        const choice = { mode, setup: laneSetup };
        setLaneChoice(choice);
        try { localStorage.setItem(laneStorageKey, JSON.stringify(choice)); } catch { /* ignore */ }
    };

    const handleLaneDrop = (e: DragEvent, column: BoardColumn, lane: Lane, laneOf: (item: Item) => string, lanes: Lane[]) => {
        e.preventDefault();
        const item = draggedItem;
        setDraggedItem(null);
        setDragOverColumn(null);
        if (!item) return;
        if (laneOf(item) !== lane.id) {
            if (lane.assign) {
                // Grouped lanes: the drop sets that field (assignee, priority, type, fix version), then the column.
                router.patch(
                    route('boards.items.update', [workspace.slug, project.slug, board.slug, item.id]),
                    { [lane.assign.field]: lane.assign.value },
                    {
                        preserveScroll: true,
                        onSuccess: () => dropToColumn(item, column),
                        onError: (errors) => toast.error(Object.values(errors)[0] ?? `Could not move ${item.key} to “${lane.name}”.`),
                    },
                );
                return;
            }
            const home = lanes.find((l) => l.id === laneOf(item));
            toast.info(`Lanes follow each card’s fields — ${item.key} stays in “${home?.name ?? 'its lane'}”.`);
        }
        dropToColumn(item, column);
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

                // Custom fields (all set filters must match)
                if (!customFields.every((field) => !fieldFilters[field.id] || matchesFieldFilter(item, field, fieldFilters[field.id]))) {
                    return false;
                }

                // Quick filters (all selected must match)
                return quickFilters.every((filter) => matchesQuickFilter(item, filter));
            }) || [],
    }));

    // Lanes for the chosen mode (null = plain columns, e.g. "custom" without lanes set up)
    const laneModel = buildLanes(laneMode, boardLanes, filteredColumns.flatMap((c) => c.items ?? []), { priorities, itemTypes });

    // Possible parents for a new issue: top-level issues and first-level subtasks (3 levels max),
    // each followed by its children.
    const rootItems = useMemo(() => {
        const all = board.columns.flatMap((c) => c.items ?? []);
        const roots = all.filter((i) => !i.parent_id);
        return roots.flatMap((r) => [{ ...r, depth: 0 }, ...all.filter((c) => c.parent_id === r.id).map((c) => ({ ...c, depth: 1 }))]);
    }, [board.columns]);

    return (
        <AppLayout>
            <Head title={`${board.name} - ${project.name}`} />

            <div className="flex flex-col h-full overflow-hidden">
                <BoardHeader
                    workspace={workspace}
                    project={project}
                    board={board}
                    activeTab={currentView}
                    onSelectView={setCurrentView}
                    onCreate={() => {
                        const firstColumn = [...board.columns].sort((a, b) => a.position - b.position)[0];
                        if (firstColumn) setCreateInColumnId(firstColumn.id);
                    }}
                />

                {/* Active sprint bar (Scrum boards) */}
                {board.type === 'scrum' && (
                    <ActiveSprintBar
                        workspace={workspace}
                        project={project}
                        board={board}
                        activeSprint={activeSprint}
                        plannedSprints={plannedSprints}
                        canManage={canManageSprints}
                        openIssueCount={board.columns.filter((c) => !c.is_done_column).reduce((sum, c) => sum + (c.items?.filter((i) => !i.parent_id).length ?? 0), 0)}
                    />
                )}

                {flash?.warning && (
                    <div className="mx-6 mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-[13px] text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" role="status">
                        {flash.warning}
                    </div>
                )}

                {/* ─── Toolbar: saved views · search · people · quick filters ─── */}
                <div className="flex items-center gap-3 px-6 py-3 flex-wrap shrink-0">
                    <SavedViewsMenu
                        views={savedViews}
                        activeViewId={activeViewId}
                        defaultViewId={defaultViewId}
                        canShare={canShareViews}
                        currentParams={currentViewParams}
                        routeParams={[workspace.slug, project.slug, board.slug]}
                    />
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


                        {/* Swimlanes (personal choice; board default from settings) */}
                        {currentView === 'kanban' && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        className={cn(
                                            'inline-flex items-center gap-1.5 h-8 px-2.5 rounded-[4px] text-[13px] font-medium border cursor-pointer transition-colors',
                                            laneMode !== 'none' ? 'border-primary/40 bg-primary/5 text-foreground' : 'border-border bg-background text-muted-foreground hover:text-foreground',
                                        )}
                                        aria-label="Swimlanes"
                                    >
                                        <Rows3 className="size-[14px]" />
                                        <span className="hidden sm:inline">{laneMode === 'none' ? 'Lanes' : MODE_LABELS[laneMode]}</span>
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-56">
                                    <DropdownMenuLabel>Swimlanes</DropdownMenuLabel>
                                    <DropdownMenuRadioGroup value={laneMode} onValueChange={(v) => setLaneMode(v as SwimlaneMode)}>
                                        {(['none', 'custom', 'assignee', 'priority', 'type', 'release'] as SwimlaneMode[])
                                            .filter((m) => m !== 'custom' || hasCustomLanes)
                                            .map((m) => (
                                                <DropdownMenuRadioItem key={m} value={m}>
                                                    {m === 'custom' ? `${MODE_LABELS.custom} (${boardLanes?.lanes.length})` : MODE_LABELS[m]}
                                                </DropdownMenuRadioItem>
                                            ))}
                                    </DropdownMenuRadioGroup>
                                    {canManageSprints && (
                                        <>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem asChild>
                                                <Link href={route('boards.edit', [workspace.slug, project.slug, board.slug]) + '#swimlanes'}>
                                                    {(boardLanes?.lanes?.length ?? 0) > 0 ? 'Edit custom lanes…' : 'Create custom lanes…'}
                                                </Link>
                                            </DropdownMenuItem>
                                        </>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}

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
                            <PopoverContent className="w-80 p-4 space-y-4 max-h-[75vh] overflow-y-auto" align="end">
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
                                                setFieldFilters({});
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

                                {/* Custom fields */}
                                <CustomFieldFilters fields={customFields} filters={fieldFilters} onChange={setFieldFilter} />
                                {canManageFields && (
                                    <Link
                                        href={route('projects.edit', [workspace.slug, project.slug]) + '?tab=fields'}
                                        className="block text-xs text-primary hover:underline"
                                    >
                                        {customFields.length ? 'Manage fields' : 'Add custom fields'}
                                    </Link>
                                )}
                            </PopoverContent>
                        </Popover>

                </div>

                {/* View Content */}
                {currentView === 'kanban' && laneModel && (
                    <SwimlaneBoard
                        columns={filteredColumns}
                        lanes={laneModel.lanes}
                        laneOf={laneModel.laneOf}
                        showEmptyLanes={laneMode === 'custom'}
                        workspace={workspace}
                        project={project}
                        board={board}
                        projectUsers={projectUsers}
                        onEditItem={setEditingItem}
                        onDragStart={handleDragStart}
                        onDragEnd={handleDragEnd}
                        onDropCell={(e, column, lane) => handleLaneDrop(e, column, lane, laneModel.laneOf, laneModel.lanes)}
                    />
                )}

                {currentView === 'kanban' && !laneModel && (
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

                            <NewColumnTile workspace={workspace} project={project} board={board} />
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
                        customFields={customFields}
                        sort={listSort}
                        setSort={setListSort}
                        hiddenFieldIds={hiddenFieldIds}
                        setHiddenFieldIds={setHiddenFieldIds}
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
                onClose={closeItem}
                onOpenItem={setEditingItem}
                customFields={customFields}
                canEditItems={canEditItems}
            />
            <CompleteSubtasksDialog
                open={completePrompt !== null}
                itemKey={completePrompt?.item.key ?? ''}
                openCount={completePrompt?.openCount ?? 0}
                columnName={completePrompt?.column.name ?? ''}
                onCancel={() => setCompletePrompt(null)}
                onChoose={(withSubtasks) => {
                    if (completePrompt) moveItem(completePrompt.item, completePrompt.column, withSubtasks);
                    setCompletePrompt(null);
                }}
            />
        </AppLayout>
    );
}
