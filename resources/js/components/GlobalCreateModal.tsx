import React, { FormEvent, Suspense, useEffect, useState } from 'react';
import axios from 'axios';
import { router, useForm, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
    PageProps, Board, BoardColumn,
    ItemType, ItemPriority, Label as LabelType, User,
} from '@/types';
import { flattenPagesForParentSelect, FlattenedPageOption } from '@/lib/pageTree';
import { ProjectIcon } from '@/components/ProjectIcon';
import { ItemTypeIcon } from '@/components/ItemTypeIcon';
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    CheckSquare2,
    FileText,
    FolderOpen,
    PencilRuler,
    ChevronDown,
    ChevronRight,
    ChevronUp,
    Equal,
    Tag,
    X,
    Check,
    Plus,
} from 'lucide-react';

const RichLexicalEditor = React.lazy(() =>
    import('@/components/editor/RichLexicalEditor').then((m) => ({ default: m.RichLexicalEditor }))
);

function PriorityIcon({ slug, className }: { slug?: string; className?: string }) {
    const cls = cn('size-3.5 shrink-0', className);
    switch (slug) {
        case 'highest': return <ChevronUp className={cn(cls, 'text-red-600')} />;
        case 'high':    return <ChevronUp className={cn(cls, 'text-orange-500')} />;
        case 'medium':  return <Equal className={cn(cls, 'text-yellow-500')} />;
        case 'low':     return <ChevronDown className={cn(cls, 'text-blue-400')} />;
        case 'lowest':  return <ChevronDown className={cn(cls, 'text-muted-foreground')} />;
        default:        return <Equal className={cn(cls, 'text-muted-foreground')} />;
    }
}

type CreateType = 'task' | 'page' | 'whiteboard' | 'folder';

const TYPE_CONFIG: Record<CreateType, { label: string; icon: React.ReactNode; color: string }> = {
    task:       { label: 'Task',       icon: <CheckSquare2 className="size-4" />, color: 'text-blue-500'    },
    page:       { label: 'Page',       icon: <FileText className="size-4" />,     color: 'text-emerald-500' },
    whiteboard: { label: 'Whiteboard', icon: <PencilRuler className="size-4" />,  color: 'text-violet-500'  },
    folder:     { label: 'Folder',     icon: <FolderOpen className="size-4" />,   color: 'text-amber-500'   },
};

interface RootItem { id: number; key: string; title: string; }

interface Props {
    open: boolean;
    onClose: () => void;
    preSelectedProjectId?: number;
    preSelectedBoardId?: number;
    preSelectedColumnId?: number;
    defaultType?: CreateType;
}

export function GlobalCreateModal({
    open,
    onClose,
    preSelectedProjectId,
    preSelectedBoardId,
    preSelectedColumnId,
    defaultType = 'task',
}: Props) {
    const { currentWorkspace, itemTypes = [], priorities = [] } = usePage<PageProps>().props;
    const [activeType, setActiveType] = useState<CreateType>(defaultType);
    const [selectedProjectId, setSelectedProjectId] = useState<string>(preSelectedProjectId?.toString() ?? '');
    const [selectedBoardId, setSelectedBoardId] = useState<string>(preSelectedBoardId?.toString() ?? '');
    const [boards, setBoards] = useState<Board[]>([]);
    const [columns, setColumns] = useState<BoardColumn[]>([]);
    const [projectLabels, setProjectLabels] = useState<LabelType[]>([]);
    const [projectUsers, setProjectUsers] = useState<User[]>([]);
    const [rootItems, setRootItems] = useState<RootItem[]>([]);
    const [descOpen, setDescOpen] = useState(false);
    const [labelSearch, setLabelSearch] = useState('');
    const [labelPopoverOpen, setLabelPopoverOpen] = useState(false);
    const [newLabelName, setNewLabelName] = useState('');

    const {
        data: taskData, setData: setTaskData,
        post: postTask, processing: taskProcessing,
        reset: resetTask, errors: taskErrors,
    } = useForm({
        title: '',
        description: '',
        column_id: preSelectedColumnId?.toString() ?? '',
        item_type_id: '',
        priority_id: '',
        assigned_to: '',
        parent_id: '' as string | number,
        label_ids: [] as number[],
    });

    const {
        data: pageData, setData: setPageData,
        processing: pageProcessing, reset: resetPage, errors: pageErrors,
    } = useForm({ name: '', parent_id: '' });

    useEffect(() => {
        if (!open) return;
        setActiveType(defaultType);
        setSelectedProjectId(preSelectedProjectId?.toString() ?? '');
        setSelectedBoardId(preSelectedBoardId?.toString() ?? '');
        resetTask();
        resetPage();
        setDescOpen(false);
        setRootItems([]);
        setLabelSearch('');
        setLabelPopoverOpen(false);
        setNewLabelName('');
    }, [open]);

    useEffect(() => {
        if (preSelectedProjectId) setSelectedProjectId(preSelectedProjectId.toString());
        if (preSelectedBoardId)   setSelectedBoardId(preSelectedBoardId.toString());
        if (preSelectedColumnId)  setTaskData('column_id', preSelectedColumnId.toString());
    }, [preSelectedProjectId, preSelectedBoardId, preSelectedColumnId]);

    useEffect(() => {
        if (!selectedProjectId || !currentWorkspace) {
            setBoards([]); setSelectedBoardId(''); setProjectLabels([]); setProjectUsers([]);
            return;
        }
        const project = currentWorkspace.projects?.find((p) => p.id.toString() === selectedProjectId);
        const projectBoards = project?.boards ?? [];
        setBoards(projectBoards);
        if (!preSelectedBoardId && projectBoards.length > 0) setSelectedBoardId(projectBoards[0].id.toString());
        if (project) {
            axios.get(route('projects.labels.index', [currentWorkspace.slug, project.slug]))
                .then((res) => setProjectLabels(res.data))
                .catch(() => setProjectLabels([]));
            axios.get(route('workspaces.users.index', [currentWorkspace.slug]))
                .then((res) => setProjectUsers(res.data))
                .catch(() => setProjectUsers([]));
        }
    }, [selectedProjectId, currentWorkspace]);

    useEffect(() => {
        if (!selectedBoardId) {
            setColumns([]); setTaskData('column_id', ''); setRootItems([]); return;
        }
        const board = boards.find((b) => b.id.toString() === selectedBoardId);
        const boardColumns = board?.columns ?? [];
        setColumns(boardColumns);
        const valid = boardColumns.some((c) => c.id.toString() === taskData.column_id);
        if (!valid) setTaskData('column_id', boardColumns[0]?.id.toString() ?? '');
        if (currentWorkspace && board) {
            const project = currentWorkspace.projects?.find((p) =>
                p.boards?.some((b) => b.id.toString() === selectedBoardId)
            );
            if (project) {
                axios.get(route('boards.root-items', [currentWorkspace.slug, project.slug, board.slug]))
                    .then((res) => setRootItems(res.data))
                    .catch(() => setRootItems([]));
            }
        }
    }, [selectedBoardId, boards]);

    if (!currentWorkspace) return null;

    const projects = currentWorkspace.projects ?? [];

    const flatPages = (): FlattenedPageOption[] => {
        if (!selectedProjectId) return [];
        const project = projects.find((p) => p.id.toString() === selectedProjectId);
        if (!project?.pages) return [];
        return flattenPagesForParentSelect(project.pages);
    };

    const subtaskTypeId = itemTypes.find((t) => t.slug === 'subtask')?.id?.toString();
    const isSubtask = taskData.item_type_id === subtaskTypeId;

    const isTaskReady =
        selectedProjectId && selectedBoardId && taskData.column_id && taskData.title.trim() &&
        (!isSubtask || taskData.parent_id !== '');
    const isPageReady = selectedProjectId && pageData.name.trim();

    const toggleLabel = (id: number) => {
        const cur = taskData.label_ids;
        setTaskData('label_ids', cur.includes(id) ? cur.filter((l) => l !== id) : [...cur, id]);
    };

    const handleCreateLabel = () => {
        if (!newLabelName.trim() || !currentWorkspace || !selectedProjectId) return;
        const project = projects.find((p) => p.id.toString() === selectedProjectId);
        if (!project) return;
        const color = '#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0');
        axios.post(route('projects.labels.store', [currentWorkspace.slug, project.slug]), {
            name: newLabelName.trim(),
            color,
        }, { headers: { Accept: 'application/json' } }).then((res) => {
            const created = res.data as LabelType;
            setProjectLabels((prev) => [...prev, created]);
            setTaskData('label_ids', [...taskData.label_ids, created.id]);
            setNewLabelName('');
        }).catch(() => {});
    };

    const filteredLabels = projectLabels.filter((label) =>
        label.name.toLowerCase().includes(labelSearch.toLowerCase())
    );

    const handleTaskSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!isTaskReady) return;
        const project = projects.find((p) => p.id.toString() === selectedProjectId);
        const board    = boards.find((b) => b.id.toString() === selectedBoardId);
        if (!project || !board) return;
        postTask(route('boards.items.store', [currentWorkspace.slug, project.slug, board.slug]), {
            onSuccess: () => { resetTask(); setDescOpen(false); onClose(); },
        });
    };

    const handlePageSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!isPageReady) return;
        const project = projects.find((p) => p.id.toString() === selectedProjectId);
        if (!project) return;
        const typeMap: Record<CreateType, { type: string; content_type?: string }> = {
            task: { type: 'page' }, page: { type: 'page', content_type: 'doc' },
            whiteboard: { type: 'page', content_type: 'excalidraw' }, folder: { type: 'folder' },
        };
        router.post(
            route('pages.store', [currentWorkspace.slug, project.slug]),
            { name: pageData.name, parent_id: pageData.parent_id || null, ...typeMap[activeType] },
            { onSuccess: () => { resetPage(); onClose(); } }
        );
    };

    const handleClose = () => { resetTask(); resetPage(); setDescOpen(false); onClose(); };

    const isTask        = activeType === 'task';
    const submitHandler = isTask ? handleTaskSubmit : handlePageSubmit;
    const processing    = isTask ? taskProcessing   : pageProcessing;
    const canSubmit     = isTask ? !!isTaskReady && !processing : !!isPageReady && !processing;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && handleClose()}>
            <DialogContent className="max-w-2xl p-0 gap-0 overflow-hidden [&>button]:hidden" aria-describedby={undefined}>
                <DialogTitle className="sr-only">Create {TYPE_CONFIG[activeType].label}</DialogTitle>

                <div className="flex items-center justify-between px-4 pt-4 pb-0 gap-2">
                    <div className="flex items-center gap-1 bg-muted/50 rounded-lg p-1">
                        {(Object.entries(TYPE_CONFIG) as [CreateType, (typeof TYPE_CONFIG)[CreateType]][]).map(([type, cfg]) => (
                            <button
                                key={type}
                                type="button"
                                onClick={() => setActiveType(type)}
                                className={cn(
                                    'flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-150',
                                    activeType === type
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                )}
                            >
                                <span className={cn(activeType === type ? cfg.color : 'text-muted-foreground')}>{cfg.icon}</span>
                                {cfg.label}
                            </button>
                        ))}
                    </div>
                    <Button variant="ghost" size="icon" className="size-8 shrink-0" onClick={handleClose}>
                        <X className="size-4" /><span className="sr-only">Close</span>
                    </Button>
                </div>

                <form onSubmit={submitHandler} className="flex flex-col">
                    <div className="px-5 py-4 space-y-4 max-h-[72vh] overflow-y-auto">

                        {/* Project */}
                        <div className="space-y-1.5">
                            <Label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Project</Label>
                            <Select value={selectedProjectId} onValueChange={setSelectedProjectId}>
                                <SelectTrigger className="h-9"><SelectValue placeholder="Select a project…" /></SelectTrigger>
                                <SelectContent>
                                    {projects.map((p) => (
                                        <SelectItem key={p.id} value={p.id.toString()}>
                                            <div className="flex items-center gap-2"><ProjectIcon project={p} size="xs" />{p.name}</div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {activeType === 'task' && (
                            <>
                                {boards.length > 0 && (
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1.5">
                                            <Label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Board</Label>
                                            <Select value={selectedBoardId} onValueChange={setSelectedBoardId}>
                                                <SelectTrigger className="h-9"><SelectValue placeholder="Board…" /></SelectTrigger>
                                                <SelectContent>
                                                    {boards.map((b) => (
                                                        <SelectItem key={b.id} value={b.id.toString()}>{b.name}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        {columns.length > 0 && (
                                            <div className="space-y-1.5">
                                                <Label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Status</Label>
                                                <Select value={taskData.column_id} onValueChange={(v) => setTaskData('column_id', v)}>
                                                    <SelectTrigger className="h-9"><SelectValue placeholder="Status…" /></SelectTrigger>
                                                    <SelectContent>
                                                        {columns.map((col) => (
                                                            <SelectItem key={col.id} value={col.id.toString()}>
                                                                <div className="flex items-center gap-2">
                                                                    <div className="size-2 rounded-full shrink-0" style={{ backgroundColor: col.color ?? '#94a3b8' }} />
                                                                    {col.name}
                                                                </div>
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}
                                    </div>
                                )}

                                <div>
                                    <Input
                                        value={taskData.title}
                                        onChange={(e) => setTaskData('title', e.target.value)}
                                        placeholder="What needs to be done?"
                                        className="h-11 text-base font-medium border-0 border-b rounded-none px-0 shadow-none focus-visible:ring-0 focus-visible:border-primary"
                                        autoFocus
                                    />
                                    {taskErrors.title && <p className="text-xs text-destructive mt-1">{taskErrors.title}</p>}
                                </div>

                                <div className="space-y-2">
                                    <button
                                        type="button"
                                        onClick={() => setDescOpen((o) => !o)}
                                        className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
                                    >
                                        {descOpen ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                                        Description
                                    </button>
                                    {descOpen && (
                                        <Suspense fallback={<div className="h-32 rounded-lg bg-muted animate-pulse" />}>
                                            <RichLexicalEditor
                                                content={taskData.description || null}
                                                onChange={(html) => setTaskData('description', html)}
                                                placeholder="Add more details…"
                                                minHeight="120px"
                                                maxHeight="280px"
                                                showToolbar
                                            />
                                        </Suspense>
                                    )}
                                </div>

                                {itemTypes.length > 0 && (
                                    <div className="space-y-1.5">
                                        <Label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Type</Label>
                                        <div className="flex flex-wrap gap-1.5">
                                            {itemTypes.map((t) => {
                                                const active = taskData.item_type_id === t.id.toString();
                                                return (
                                                    <button
                                                        key={t.id}
                                                        type="button"
                                                        onClick={() => setTaskData('item_type_id', active ? '' : t.id.toString())}
                                                        className={cn(
                                                            'flex items-center gap-1.5 h-7 px-2.5 rounded-full text-xs border transition-all',
                                                            active
                                                                ? 'font-semibold border-primary/40 bg-primary/10 text-foreground'
                                                                : 'border-transparent bg-muted/50 text-muted-foreground hover:bg-muted hover:text-foreground'
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

                                {isSubtask && (
                                    <div className="space-y-1.5">
                                        <Label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">
                                            Parent Task <span className="text-destructive">*</span>
                                        </Label>
                                        <Select
                                            value={taskData.parent_id === '' || taskData.parent_id == null ? '__none__' : String(taskData.parent_id)}
                                            onValueChange={(v) => setTaskData('parent_id', v === '__none__' ? '' : Number(v))}
                                        >
                                            <SelectTrigger className="h-9"><SelectValue placeholder="Select parent task…" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__"><span className="text-muted-foreground">None</span></SelectItem>
                                                {rootItems.map((item) => (
                                                    <SelectItem key={item.id} value={String(item.id)}>
                                                        <span className="font-mono text-xs text-muted-foreground mr-1.5">{item.key}</span>
                                                        {item.title}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {taskErrors.parent_id && <p className="text-xs text-destructive">{taskErrors.parent_id}</p>}
                                    </div>
                                )}

                                {priorities.length > 0 && (
                                    <div className="space-y-1.5">
                                        <Label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Priority</Label>
                                        <div className="flex flex-wrap gap-1.5">
                                            <button
                                                type="button"
                                                onClick={() => setTaskData('priority_id', '')}
                                                className={cn(
                                                    'flex items-center gap-1.5 h-7 px-2.5 rounded-full text-xs border transition-all',
                                                    !taskData.priority_id
                                                        ? 'border-primary/40 bg-primary/10 text-foreground font-semibold'
                                                        : 'border-transparent bg-muted/50 text-muted-foreground hover:bg-muted hover:text-foreground'
                                                )}
                                            >None</button>
                                            {priorities.map((p) => {
                                                const active = taskData.priority_id === p.id.toString();
                                                return (
                                                    <button
                                                        key={p.id}
                                                        type="button"
                                                        onClick={() => setTaskData('priority_id', active ? '' : p.id.toString())}
                                                        className={cn(
                                                            'flex items-center gap-1.5 h-7 px-2.5 rounded-full text-xs border transition-all',
                                                            active
                                                                ? 'font-semibold border-transparent text-white'
                                                                : 'border-transparent bg-muted/50 text-muted-foreground hover:bg-muted hover:text-foreground'
                                                        )}
                                                        style={active && p.color ? { backgroundColor: p.color } : undefined}
                                                    >
                                                        <PriorityIcon slug={p.slug} />
                                                        {p.name}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                {selectedProjectId && projectUsers.length >= 0 && (
                                    <div className="space-y-1.5">
                                        <Label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Assignee</Label>
                                        <Select
                                            value={taskData.assigned_to || 'unassigned'}
                                            onValueChange={(v) => setTaskData('assigned_to', v === 'unassigned' ? '' : v)}
                                        >
                                            <SelectTrigger className="h-9"><SelectValue placeholder="Unassigned" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="unassigned">Unassigned</SelectItem>
                                                {projectUsers.map((user) => (
                                                    <SelectItem key={user.id} value={user.id.toString()}>
                                                        <div className="flex items-center gap-2">
                                                            <Avatar className="size-5">
                                                                {user.avatar_display && <AvatarImage src={user.avatar_display} alt={user.name} />}
                                                                <AvatarFallback className="text-[10px]">{user.name.charAt(0).toUpperCase()}</AvatarFallback>
                                                            </Avatar>
                                                            {user.name}
                                                        </div>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}

                                {selectedProjectId && (
                                    <div className="space-y-1.5">
                                        <Label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide flex items-center gap-1">
                                            <Tag className="size-3" /> Labels
                                        </Label>
                                        <Popover open={labelPopoverOpen} onOpenChange={(o) => { setLabelPopoverOpen(o); if (!o) setLabelSearch(''); }}>
                                            <PopoverTrigger asChild>
                                                <button
                                                    type="button"
                                                    className="w-full min-h-9 rounded-md border border-input bg-background px-3 py-2 text-left text-sm flex flex-wrap gap-1.5 items-center hover:bg-muted/50 transition-colors"
                                                >
                                                    {taskData.label_ids.length === 0 ? (
                                                        <span className="text-muted-foreground">Search or add labels…</span>
                                                    ) : (
                                                        taskData.label_ids.map((id) => {
                                                            const lbl = projectLabels.find((l) => l.id === id);
                                                            if (!lbl) return null;
                                                            return (
                                                                <span
                                                                    key={id}
                                                                    className="inline-flex items-center gap-1 text-[11px] font-medium px-1.5 py-0.5 rounded-full"
                                                                    style={{
                                                                        backgroundColor: (lbl.color ?? '#94a3b8') + '22',
                                                                        color: lbl.color ?? '#475569',
                                                                        border: `1px solid ${(lbl.color ?? '#94a3b8')}55`,
                                                                    }}
                                                                >
                                                                    {lbl.name}
                                                                </span>
                                                            );
                                                        })
                                                    )}
                                                </button>
                                            </PopoverTrigger>
                                            <PopoverContent className="p-0 w-56" align="start">
                                                <div className="p-2 border-b">
                                                    <Input
                                                        autoFocus
                                                        placeholder="Search labels…"
                                                        value={labelSearch}
                                                        onChange={(e) => setLabelSearch(e.target.value)}
                                                        className="h-7 text-xs border-0 shadow-none focus-visible:ring-0 px-1"
                                                    />
                                                </div>
                                                <div className="max-h-52 overflow-y-auto p-1">
                                                    {filteredLabels.map((label) => {
                                                        const active = taskData.label_ids.includes(label.id);
                                                        return (
                                                            <button
                                                                key={label.id}
                                                                type="button"
                                                                onClick={() => toggleLabel(label.id)}
                                                                className="flex items-center gap-2 w-full px-2 py-1.5 rounded-md text-sm hover:bg-muted/60 transition-colors text-left"
                                                            >
                                                                <span className="size-3 rounded-full shrink-0 border" style={{ backgroundColor: label.color, borderColor: label.color }} />
                                                                <span className="flex-1 truncate text-xs">{label.name}</span>
                                                                {active && <Check className="size-3 shrink-0 text-primary" />}
                                                            </button>
                                                        );
                                                    })}
                                                    {filteredLabels.length === 0 && <p className="text-xs text-muted-foreground px-2 py-3 text-center">No labels found</p>}
                                                </div>
                                                <div className="p-2 border-t">
                                                    <div className="flex gap-1.5">
                                                        <Input
                                                            placeholder="New label name…"
                                                            value={newLabelName}
                                                            onChange={(e) => setNewLabelName(e.target.value)}
                                                            className="h-7 text-xs flex-1"
                                                            onKeyDown={(e) => { if (e.key === 'Enter' && newLabelName.trim()) { e.preventDefault(); handleCreateLabel(); } }}
                                                        />
                                                        <Button type="button" size="sm" className="h-7 px-2" onClick={handleCreateLabel} disabled={!newLabelName.trim()}>
                                                            <Plus className="size-3" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            </PopoverContent>
                                        </Popover>
                                    </div>
                                )}
                            </>
                        )}

                        {activeType !== 'task' && (
                            <>
                                {selectedProjectId && (
                                    <div className="space-y-1.5">
                                        <Label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">
                                            Parent {activeType === 'folder' ? 'Folder' : 'Page'}{' '}
                                            <span className="normal-case font-normal text-muted-foreground">(optional)</span>
                                        </Label>
                                        <Select
                                            value={pageData.parent_id || '__none__'}
                                            onValueChange={(v) => setPageData('parent_id', v === '__none__' ? '' : v)}
                                        >
                                            <SelectTrigger className="h-9"><SelectValue placeholder="None (root)" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__"><span className="text-muted-foreground">None (root)</span></SelectItem>
                                                {flatPages().map((p) => (
                                                    <SelectItem key={p.id} value={p.id.toString()}>
                                                        <span style={{ paddingLeft: `${p.depth * 12}px` }}>
                                                            {p.type === 'folder' ? '📁 ' : '📄 '}{p.name}
                                                        </span>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                                <div>
                                    <Input
                                        value={pageData.name}
                                        onChange={(e) => setPageData('name', e.target.value)}
                                        placeholder={
                                            activeType === 'folder' ? 'Folder name…'
                                            : activeType === 'whiteboard' ? 'Whiteboard name…'
                                            : 'Page name…'
                                        }
                                        className="h-11 text-base font-medium border-0 border-b rounded-none px-0 shadow-none focus-visible:ring-0 focus-visible:border-primary"
                                        autoFocus
                                    />
                                    {pageErrors.name && <p className="text-xs text-destructive mt-1">{pageErrors.name}</p>}
                                </div>
                            </>
                        )}
                    </div>

                    <div className="flex items-center justify-between px-5 py-3 border-t bg-muted/30">
                        <p className="text-xs text-muted-foreground">
                            <kbd className="px-1.5 py-0.5 rounded bg-muted border text-[11px] font-mono">⌘↵</kbd>{' '}to submit
                        </p>
                        <div className="flex items-center gap-2">
                            <Button type="button" variant="outline" size="sm" onClick={handleClose}>Cancel</Button>
                            <Button type="submit" size="sm" disabled={!canSubmit}>
                                {processing ? 'Creating…' : `Create ${TYPE_CONFIG[activeType].label}`}
                            </Button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
