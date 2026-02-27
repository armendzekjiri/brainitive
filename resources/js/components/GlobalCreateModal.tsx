import React, { FormEvent, Suspense, useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { PageProps, Project, Board, BoardColumn, Page as PageType, ItemType, ItemPriority } from '@/types';
import { flattenPagesForParentSelect, FlattenedPageOption } from '@/lib/pageTree';
import { ProjectIcon } from '@/components/ProjectIcon';
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    CheckSquare2,
    FileText,
    FolderOpen,
    PencilRuler,
    ChevronDown,
    ChevronRight,
    X,
} from 'lucide-react';

// Lazily import the rich editor so it doesn't bloat the main layout bundle
const RichLexicalEditor = React.lazy(() =>
    import('@/components/editor/RichLexicalEditor').then((m) => ({ default: m.RichLexicalEditor }))
);

type CreateType = 'task' | 'page' | 'whiteboard' | 'folder';

const TYPE_CONFIG: Record<CreateType, { label: string; icon: React.ReactNode; color: string }> = {
    task: {
        label: 'Task',
        icon: <CheckSquare2 className="size-4" />,
        color: 'text-blue-500',
    },
    page: {
        label: 'Page',
        icon: <FileText className="size-4" />,
        color: 'text-emerald-500',
    },
    whiteboard: {
        label: 'Whiteboard',
        icon: <PencilRuler className="size-4" />,
        color: 'text-violet-500',
    },
    folder: {
        label: 'Folder',
        icon: <FolderOpen className="size-4" />,
        color: 'text-amber-500',
    },
};

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

    // ── Shared state ──────────────────────────────────────────────
    const [selectedProjectId, setSelectedProjectId] = useState<string>(preSelectedProjectId?.toString() ?? '');

    // ── Task-specific state ───────────────────────────────────────
    const [selectedBoardId, setSelectedBoardId] = useState<string>(preSelectedBoardId?.toString() ?? '');
    const [boards, setBoards] = useState<Board[]>([]);
    const [columns, setColumns] = useState<BoardColumn[]>([]);
    const [descOpen, setDescOpen] = useState(false);

    const { data: taskData, setData: setTaskData, post: postTask, processing: taskProcessing, reset: resetTask, errors: taskErrors } = useForm({
        title: '',
        description: '',
        column_id: preSelectedColumnId?.toString() ?? '',
        item_type_id: '',
        priority_id: '',
        assigned_to: '',
    });

    // ── Page/Whiteboard/Folder state ──────────────────────────────
    const { data: pageData, setData: setPageData, processing: pageProcessing, reset: resetPage, errors: pageErrors } = useForm({
        name: '',
        parent_id: '',
    });

    // ── Reset when modal opens/closes ─────────────────────────────
    useEffect(() => {
        if (!open) return;
        setActiveType(defaultType);
        setSelectedProjectId(preSelectedProjectId?.toString() ?? '');
        setSelectedBoardId(preSelectedBoardId?.toString() ?? '');
        resetTask();
        resetPage();
        setDescOpen(false);
    }, [open]);

    // ── Sync pre-selected values ──────────────────────────────────
    useEffect(() => {
        if (preSelectedProjectId) setSelectedProjectId(preSelectedProjectId.toString());
        if (preSelectedBoardId) setSelectedBoardId(preSelectedBoardId.toString());
        if (preSelectedColumnId) setTaskData('column_id', preSelectedColumnId.toString());
    }, [preSelectedProjectId, preSelectedBoardId, preSelectedColumnId]);

    // ── Boards from selected project ──────────────────────────────
    useEffect(() => {
        if (!selectedProjectId || !currentWorkspace) {
            setBoards([]);
            setSelectedBoardId('');
            return;
        }
        const project = currentWorkspace.projects?.find((p) => p.id.toString() === selectedProjectId);
        const projectBoards = project?.boards ?? [];
        setBoards(projectBoards);
        if (!preSelectedBoardId && projectBoards.length > 0) {
            setSelectedBoardId(projectBoards[0].id.toString());
        }
    }, [selectedProjectId, currentWorkspace]);

    // ── Columns from selected board ───────────────────────────────
    useEffect(() => {
        if (!selectedBoardId) {
            setColumns([]);
            setTaskData('column_id', '');
            return;
        }
        const board = boards.find((b) => b.id.toString() === selectedBoardId);
        const boardColumns = board?.columns ?? [];
        setColumns(boardColumns);
        const valid = boardColumns.some((c) => c.id.toString() === taskData.column_id);
        if (!valid) {
            setTaskData('column_id', boardColumns[0]?.id.toString() ?? '');
        }
    }, [selectedBoardId, boards]);

    if (!currentWorkspace) return null;

    // ── Derived helpers ───────────────────────────────────────────
    const projects = currentWorkspace.projects ?? [];

    const flatPages = (): FlattenedPageOption[] => {
        if (!selectedProjectId) return [];
        const project = projects.find((p) => p.id.toString() === selectedProjectId);
        if (!project?.pages) return [];
        return flattenPagesForParentSelect(project.pages);
    };

    const isTaskReady = selectedProjectId && selectedBoardId && taskData.column_id && taskData.title.trim();
    const isPageReady = selectedProjectId && pageData.name.trim();

    // ── Submit handlers ───────────────────────────────────────────
    const handleTaskSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!isTaskReady) return;
        const project = projects.find((p) => p.id.toString() === selectedProjectId);
        const board = boards.find((b) => b.id.toString() === selectedBoardId);
        if (!project || !board) return;

        postTask(route('boards.items.store', [currentWorkspace.slug, project.slug, board.slug]), {
            onSuccess: () => {
                resetTask();
                setDescOpen(false);
                onClose();
            },
        });
    };

    const handlePageSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!isPageReady) return;
        const project = projects.find((p) => p.id.toString() === selectedProjectId);
        if (!project) return;

        const typeMap: Record<CreateType, { type: string; content_type?: string }> = {
            task: { type: 'page' },
            page: { type: 'page', content_type: 'doc' },
            whiteboard: { type: 'page', content_type: 'excalidraw' },
            folder: { type: 'folder' },
        };
        const payload = typeMap[activeType];

        router.post(
            route('pages.store', [currentWorkspace.slug, project.slug]),
            {
                name: pageData.name,
                parent_id: pageData.parent_id || null,
                ...payload,
            },
            {
                onSuccess: () => {
                    resetPage();
                    onClose();
                },
            }
        );
    };

    const handleClose = () => {
        resetTask();
        resetPage();
        setDescOpen(false);
        onClose();
    };

    const isTask = activeType === 'task';
    const submitHandler = isTask ? handleTaskSubmit : handlePageSubmit;
    const processing = isTask ? taskProcessing : pageProcessing;
    const canSubmit = isTask ? !!isTaskReady && !processing : !!isPageReady && !processing;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && handleClose()}>
            <DialogContent
                className="max-w-2xl p-0 gap-0 overflow-hidden [&>button]:hidden"
                aria-describedby={undefined}
            >
                <DialogTitle className="sr-only">
                    Create {TYPE_CONFIG[activeType].label}
                </DialogTitle>

                {/* ── Header: type tabs ──────────────────────────── */}
                <div className="flex items-center justify-between px-4 pt-4 pb-0 gap-2">
                    <div className="flex items-center gap-1 bg-muted/50 rounded-lg p-1">
                        {(Object.entries(TYPE_CONFIG) as [CreateType, typeof TYPE_CONFIG[CreateType]][]).map(
                            ([type, cfg]) => (
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
                                    <span className={cn(activeType === type ? cfg.color : 'text-muted-foreground')}>
                                        {cfg.icon}
                                    </span>
                                    {cfg.label}
                                </button>
                            )
                        )}
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 shrink-0 text-muted-foreground hover:text-foreground"
                        onClick={handleClose}
                    >
                        <X className="size-4" />
                        <span className="sr-only">Close</span>
                    </Button>
                </div>

                {/* ── Form body ─────────────────────────────────── */}
                <form onSubmit={submitHandler} className="flex flex-col">
                    <div className="px-5 py-4 space-y-4 max-h-[70vh] overflow-y-auto">

                        {/* Project selector — always visible */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Project</Label>
                            <Select value={selectedProjectId} onValueChange={setSelectedProjectId}>
                                <SelectTrigger className="h-9">
                                    <SelectValue placeholder="Select a project…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {projects.map((p) => (
                                        <SelectItem key={p.id} value={p.id.toString()}>
                                            <div className="flex items-center gap-2">
                                                <ProjectIcon project={p} size="xs" />
                                                {p.name}
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* ── TASK fields ── */}
                        {activeType === 'task' && (
                            <>
                                {/* Board + Column */}
                                {boards.length > 0 && (
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1.5">
                                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Board</Label>
                                            <Select value={selectedBoardId} onValueChange={setSelectedBoardId}>
                                                <SelectTrigger className="h-9">
                                                    <SelectValue placeholder="Select board…" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {boards.map((b) => (
                                                        <SelectItem key={b.id} value={b.id.toString()}>
                                                            {b.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        {columns.length > 0 && (
                                            <div className="space-y-1.5">
                                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Status</Label>
                                                <Select
                                                    value={taskData.column_id}
                                                    onValueChange={(v) => setTaskData('column_id', v)}
                                                >
                                                    <SelectTrigger className="h-9">
                                                        <SelectValue placeholder="Status…" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {columns.map((col) => (
                                                            <SelectItem key={col.id} value={col.id.toString()}>
                                                                <div className="flex items-center gap-2">
                                                                    <div
                                                                        className="size-2 rounded-full shrink-0"
                                                                        style={{ backgroundColor: col.color ?? '#94a3b8' }}
                                                                    />
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

                                {/* Title */}
                                <div className="space-y-1.5">
                                    <Input
                                        value={taskData.title}
                                        onChange={(e) => setTaskData('title', e.target.value)}
                                        placeholder="What needs to be done?"
                                        className="h-11 text-base font-medium border-0 border-b rounded-none px-0 shadow-none focus-visible:ring-0 focus-visible:border-primary"
                                        autoFocus
                                    />
                                    {taskErrors.title && (
                                        <p className="text-xs text-destructive">{taskErrors.title}</p>
                                    )}
                                </div>

                                {/* Description toggle */}
                                <div className="space-y-2">
                                    <button
                                        type="button"
                                        onClick={() => setDescOpen((o) => !o)}
                                        className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
                                    >
                                        {descOpen ? (
                                            <ChevronDown className="size-3.5" />
                                        ) : (
                                            <ChevronRight className="size-3.5" />
                                        )}
                                        Description
                                    </button>
                                    {descOpen && (
                                        <Suspense
                                            fallback={
                                                <div className="h-32 rounded-lg bg-muted animate-pulse" />
                                            }
                                        >
                                            <RichLexicalEditor
                                                content={taskData.description || null}
                                                onChange={(html) => setTaskData('description', html)}
                                                placeholder="Add more details…"
                                                minHeight="120px"
                                                maxHeight="280px"
                                                showToolbar={true}
                                            />
                                        </Suspense>
                                    )}
                                </div>

                                {/* Priority chips */}
                                {priorities.length > 0 && (
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Priority</Label>
                                        <div className="flex flex-wrap gap-1.5">
                                            <button
                                                type="button"
                                                onClick={() => setTaskData('priority_id', '')}
                                                className={cn(
                                                    'flex items-center gap-1.5 h-7 px-2.5 rounded-full text-xs border transition-all',
                                                    !taskData.priority_id
                                                        ? 'bg-primary/10 border-primary/30 text-primary font-medium'
                                                        : 'border-transparent bg-muted/50 text-muted-foreground hover:bg-muted hover:text-foreground'
                                                )}
                                            >
                                                None
                                            </button>
                                            {priorities.map((p) => (
                                                <button
                                                    key={p.id}
                                                    type="button"
                                                    onClick={() =>
                                                        setTaskData(
                                                            'priority_id',
                                                            taskData.priority_id === p.id.toString() ? '' : p.id.toString()
                                                        )
                                                    }
                                                    className={cn(
                                                        'flex items-center gap-1.5 h-7 px-2.5 rounded-full text-xs border transition-all',
                                                        taskData.priority_id === p.id.toString()
                                                            ? 'border-transparent font-medium text-white'
                                                            : 'border-transparent bg-muted/50 text-muted-foreground hover:bg-muted hover:text-foreground'
                                                    )}
                                                    style={
                                                        taskData.priority_id === p.id.toString() && p.color
                                                            ? { backgroundColor: p.color }
                                                            : undefined
                                                    }
                                                >
                                                    {p.icon && <span>{p.icon}</span>}
                                                    {p.name}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Type chips */}
                                {itemTypes.filter((t) => t.slug !== 'subtask').length > 0 && (
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Type</Label>
                                        <div className="flex flex-wrap gap-1.5">
                                            {itemTypes
                                                .filter((t) => t.slug !== 'subtask')
                                                .map((t) => (
                                                    <button
                                                        key={t.id}
                                                        type="button"
                                                        onClick={() =>
                                                            setTaskData(
                                                                'item_type_id',
                                                                taskData.item_type_id === t.id.toString()
                                                                    ? ''
                                                                    : t.id.toString()
                                                            )
                                                        }
                                                        className={cn(
                                                            'flex items-center gap-1.5 h-7 px-2.5 rounded-full text-xs border transition-all',
                                                            taskData.item_type_id === t.id.toString()
                                                                ? 'border-transparent font-medium text-white'
                                                                : 'border-transparent bg-muted/50 text-muted-foreground hover:bg-muted hover:text-foreground'
                                                        )}
                                                        style={
                                                            taskData.item_type_id === t.id.toString() && t.color
                                                                ? { backgroundColor: t.color }
                                                                : undefined
                                                        }
                                                    >
                                                        {t.icon && <span>{t.icon}</span>}
                                                        {t.name}
                                                    </button>
                                                ))}
                                        </div>
                                    </div>
                                )}
                            </>
                        )}

                        {/* ── PAGE / WHITEBOARD / FOLDER fields ── */}
                        {activeType !== 'task' && (
                            <>
                                {/* Parent page selector */}
                                {selectedProjectId && (
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                            Parent {activeType === 'folder' ? 'Folder' : 'Page'} (optional)
                                        </Label>
                                        <Select
                                            value={pageData.parent_id || '__none__'}
                                            onValueChange={(v) => setPageData('parent_id', v === '__none__' ? '' : v)}
                                        >
                                            <SelectTrigger className="h-9">
                                                <SelectValue placeholder="None (root)" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">
                                                    <span className="text-muted-foreground">None (root)</span>
                                                </SelectItem>
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

                                {/* Name */}
                                <div className="space-y-1.5">
                                    <Input
                                        value={pageData.name}
                                        onChange={(e) => setPageData('name', e.target.value)}
                                        placeholder={
                                            activeType === 'folder'
                                                ? 'Folder name…'
                                                : activeType === 'whiteboard'
                                                ? 'Whiteboard name…'
                                                : 'Page name…'
                                        }
                                        className="h-11 text-base font-medium border-0 border-b rounded-none px-0 shadow-none focus-visible:ring-0 focus-visible:border-primary"
                                        autoFocus
                                    />
                                    {pageErrors.name && (
                                        <p className="text-xs text-destructive">{pageErrors.name}</p>
                                    )}
                                </div>
                            </>
                        )}
                    </div>

                    {/* ── Footer ──────────────────────────────────── */}
                    <div className="flex items-center justify-between px-5 py-3 border-t bg-muted/30">
                        <p className="text-xs text-muted-foreground">
                            <kbd className="px-1.5 py-0.5 rounded bg-muted border text-[11px] font-mono">⌘↵</kbd>
                            {' '}to submit
                        </p>
                        <div className="flex items-center gap-2">
                            <Button type="button" variant="outline" size="sm" onClick={handleClose}>
                                Cancel
                            </Button>
                            <Button type="submit" size="sm" disabled={!canSubmit}>
                                {processing
                                    ? 'Creating…'
                                    : `Create ${TYPE_CONFIG[activeType].label}`}
                            </Button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
