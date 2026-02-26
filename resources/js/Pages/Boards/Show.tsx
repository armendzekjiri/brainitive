import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, BoardColumn, Item, ItemType, ItemPriority, User, Label as LabelType } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
} from 'lucide-react';
import { useState, useRef, useEffect, DragEvent, FormEvent, useCallback, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { Comment, Attachment } from '@/types';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    board: Board & { columns: (BoardColumn & { items: Item[] })[] };
    itemTypes: ItemType[];
    priorities: ItemPriority[];
    projectUsers: User[];
    projectLabels: LabelType[];
}

// ── File-type helpers (matching Items/Show) ────────────────────────────────
type PreviewType = 'pdf' | 'image' | 'video' | 'other';

function getFileIconByName(filename: string): { Icon: React.ComponentType<{ className?: string }>; colorClass: string } {
    const ext = filename?.split('.').pop()?.toLowerCase() ?? '';
    const map: Record<string, { Icon: React.ComponentType<{ className?: string }>; colorClass: string }> = {
        pdf:  { Icon: FileText,        colorClass: 'text-red-600' },
        doc:  { Icon: FileText,        colorClass: 'text-blue-600' },
        docx: { Icon: FileText,        colorClass: 'text-blue-600' },
        rtf:  { Icon: FileText,        colorClass: 'text-blue-500' },
        txt:  { Icon: FileType,        colorClass: 'text-muted-foreground' },
        md:   { Icon: FileType,        colorClass: 'text-muted-foreground' },
        xls:  { Icon: FileSpreadsheet, colorClass: 'text-green-600' },
        xlsx: { Icon: FileSpreadsheet, colorClass: 'text-green-600' },
        csv:  { Icon: FileSpreadsheet, colorClass: 'text-green-500' },
        ppt:  { Icon: FileText,        colorClass: 'text-orange-600' },
        pptx: { Icon: FileText,        colorClass: 'text-orange-600' },
        jpg:  { Icon: FileImage,       colorClass: 'text-purple-500' },
        jpeg: { Icon: FileImage,       colorClass: 'text-purple-500' },
        png:  { Icon: FileImage,       colorClass: 'text-sky-500' },
        gif:  { Icon: FileImage,       colorClass: 'text-pink-500' },
        webp: { Icon: FileImage,       colorClass: 'text-teal-500' },
        svg:  { Icon: FileImage,       colorClass: 'text-amber-500' },
        bmp:  { Icon: FileImage,       colorClass: 'text-muted-foreground' },
        ico:  { Icon: FileImage,       colorClass: 'text-amber-500' },
        zip:  { Icon: FileArchive,     colorClass: 'text-amber-600' },
        rar:  { Icon: FileArchive,     colorClass: 'text-amber-600' },
        '7z': { Icon: FileArchive,     colorClass: 'text-amber-600' },
        json: { Icon: FileCode,        colorClass: 'text-amber-500' },
        xml:  { Icon: FileCode,        colorClass: 'text-orange-500' },
    };
    return map[ext] ?? { Icon: File, colorClass: 'text-muted-foreground' };
}

function getFilePreviewType(filename: string): PreviewType {
    const ext = filename?.split('.').pop()?.toLowerCase() ?? '';
    if (['pdf'].includes(ext)) return 'pdf';
    if (['jpg','jpeg','png','gif','webp','bmp','svg','ico'].includes(ext)) return 'image';
    if (['mp4','webm','ogg','mov','avi'].includes(ext)) return 'video';
    return 'other';
}

// Priority icon component
function PriorityIcon({ slug, className }: { slug?: string; className?: string }) {
    const baseClass = `size-4 ${className || ''}`;
    switch (slug) {
        case 'highest':
            return <ChevronUp className={`${baseClass} text-red-600`} />;
        case 'high':
            return <ChevronUp className={`${baseClass} text-orange-500`} />;
        case 'medium':
            return <Equal className={`${baseClass} text-yellow-500`} />;
        case 'low':
            return <ChevronDown className={`${baseClass} text-brand-blue`} />;
        case 'lowest':
            return <ChevronDown className={`${baseClass} text-brand-gray`} />;
        default:
            return <Equal className={`${baseClass} text-muted-foreground`} />;
    }
}

// Item type icon component
function ItemTypeIcon({ slug, className }: { slug?: string; className?: string }) {
    const baseClass = `size-4 ${className || ''}`;
    switch (slug) {
        case 'bug':
            return <Bug className={`${baseClass} text-red-500`} />;
        case 'story':
            return <Bookmark className={`${baseClass} text-brand-green`} />;
        case 'epic':
            return <Layers className={`${baseClass} text-brand-slate`} />;
        case 'subtask':
            return <Square className={`${baseClass} text-primary/60`} />;
        case 'task':
        default:
            return <CheckSquare className={`${baseClass} text-primary`} />;
    }
}

// Recursive CommentItem component for nested replies
interface CommentItemProps {
    comment: Comment;
    depth: number;
    replyingTo: { id: number; parentId?: number; userName: string } | null;
    replyText: string;
    setReplyText: (text: string) => void;
    handleReply: (commentId: number, userName: string, parentId?: number) => void;
    handlePostComment: (parentId?: number) => void;
    cancelReply: () => void;
    replyEditorKey: number;
}

function CommentItem({
    comment,
    depth,
    replyingTo,
    replyText,
    setReplyText,
    handleReply,
    handlePostComment,
    cancelReply,
    replyEditorKey,
}: CommentItemProps) {
    const maxDepth = 5;
    const avatarSize = depth === 0 ? 'size-8' : 'size-6';
    const avatarTextSize = depth === 0 ? 'text-xs' : 'text-[10px]';
    const nameTextSize = depth === 0 ? 'text-sm' : 'text-xs';
    const timeTextSize = depth === 0 ? 'text-xs' : 'text-[10px]';

    return (
        <div className={`${depth > 0 ? 'border-l-2 border-muted pl-4' : ''}`}>
            <div className="flex gap-3">
                <Avatar className={`${avatarSize} shrink-0`}>
                    <AvatarFallback className={`${avatarTextSize} ${depth === 0 ? 'bg-muted' : 'bg-muted/50'}`}>
                        {comment.user?.name?.charAt(0).toUpperCase() || 'U'}
                    </AvatarFallback>
                </Avatar>
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className={`font-medium ${nameTextSize}`}>{comment.user?.name || 'Unknown'}</span>
                        <span className={`${timeTextSize} text-muted-foreground`}>
                            {new Date(comment.created_at).toLocaleDateString()} at {new Date(comment.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        </span>
                    </div>
                    <div
                        className="text-sm mt-1 prose prose-sm max-w-none"
                        dangerouslySetInnerHTML={{ __html: comment.body }}
                    />
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-6 px-2 text-xs text-muted-foreground hover:text-foreground mt-1"
                        onClick={() => handleReply(comment.id, comment.user?.name || 'Unknown', comment.parent_id || undefined)}
                    >
                        <Reply className="size-3 mr-1" />
                        Reply
                    </Button>

                    {replyingTo?.id === comment.id && (
                        <div className="mt-3">
                            <div className="flex gap-2">
                                <Avatar className="size-6 shrink-0 mt-1">
                                    <AvatarFallback className="text-[10px] bg-primary text-primary-foreground">U</AvatarFallback>
                                </Avatar>
                                <div className="flex-1 min-w-0">
                                    <div className="rounded-lg border border-primary ring-1 ring-primary/20">
                                        <RichLexicalEditor
                                            key={replyEditorKey}
                                            content={replyText || null}
                                            onChange={(html) => setReplyText(html)}
                                            placeholder={`Reply to ${replyingTo.userName}…`}
                                            editable={true}
                                            showToolbar={true}
                                            minHeight="60px"
                                            maxHeight="240px"
                                            contentKey={`reply-${comment.id}-${replyEditorKey}`}
                                        />
                                    </div>
                                    <div className="flex justify-end gap-2 mt-2">
                                        <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={cancelReply}>Cancel</Button>
                                        <Button
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={() => handlePostComment(comment.id)}
                                            disabled={!replyText.replace(/<[^>]*>/g, '').trim()}
                                        >Reply</Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {comment.replies && comment.replies.length > 0 && (
                        <div className="mt-3 space-y-3">
                            {comment.replies.map((reply) => (
                                <CommentItem
                                    key={reply.id}
                                    comment={reply}
                                    depth={Math.min(depth + 1, maxDepth)}
                                    replyingTo={replyingTo}
                                    replyText={replyText}
                                    setReplyText={setReplyText}
                                    handleReply={handleReply}
                                    handlePostComment={handlePostComment}
                                    cancelReply={cancelReply}
                                    replyEditorKey={replyEditorKey}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// Item Card Component
function ItemCard({
    item,
    workspace,
    project,
    board,
    onEdit,
    onDragStart,
    onDragEnd,
}: {
    item: Item;
    workspace: Workspace;
    project: Project;
    board: Board;
    onEdit: (item: Item) => void;
    onDragStart: (e: DragEvent, item: Item) => void;
    onDragEnd: () => void;
}) {
    return (
        <Card
            draggable
            onDragStart={(e) => onDragStart(e, item)}
            onDragEnd={onDragEnd}
            onClick={() => onEdit(item)}
            className="cursor-pointer hover:shadow-md hover:border-primary/30 transition-all group bg-background border border-border/50"
        >
            <CardContent className="p-3">
                {/* Item Key and Labels */}
                <div className="flex items-center justify-between mb-2">
                    <div className="flex items-center gap-2">
                        <ItemTypeIcon slug={item.item_type?.slug} />
                        <span className="text-xs font-medium text-primary">{item.key}</span>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-6 opacity-0 group-hover:opacity-100 transition-opacity"
                            >
                                <MoreHorizontal className="size-3.5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={(e) => { e.stopPropagation(); onEdit(item); }}>
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onClick={(e) => {
                                    e.stopPropagation();
                                    const hasSubtasks = (item.children_count ?? 0) > 0;
                                    if (hasSubtasks && !window.confirm(`This task has ${item.children_count} subtask(s). Deleting it will delete those subtasks too. Continue?`)) {
                                        return;
                                    }
                                    router.delete(route('boards.items.destroy', [workspace.slug, project.slug, board.slug, item.id]));
                                }}
                                className="text-destructive focus:text-destructive"
                            >
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                {/* Title */}
                <p className="text-sm font-medium mb-3 line-clamp-2 leading-snug">{item.title}</p>

                {/* Parent (subtask link) */}
                {item.parent && (
                    <div className="mb-2">
                        <Link
                            href={route('boards.items.show', [workspace.slug, project.slug, board.slug, item.parent.id])}
                            onClick={(e) => e.stopPropagation()}
                            className="text-[10px] text-muted-foreground hover:text-primary hover:underline flex items-center gap-1"
                        >
                            <ChevronUp className="size-3 shrink-0" />
                            <span>Parent: {item.parent.key}</span>
                        </Link>
                    </div>
                )}

                {/* Labels */}
                {item.labels && item.labels.length > 0 && (
                    <div className="flex flex-wrap gap-1 mb-3">
                        {item.labels.slice(0, 2).map((label) => (
                            <Badge
                                key={label.id}
                                variant="outline"
                                className="text-[10px] px-1.5 py-0"
                                style={{ borderColor: label.color, color: label.color }}
                            >
                                {label.name}
                            </Badge>
                        ))}
                        {item.labels.length > 2 && (
                            <Badge variant="secondary" className="text-[10px] px-1.5 py-0">
                                +{item.labels.length - 2}
                            </Badge>
                        )}
                    </div>
                )}

                {/* Footer */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <PriorityIcon slug={item.priority?.slug} />
                        {item.story_points && (
                            <Badge variant="secondary" className="text-[10px] px-1.5 py-0 font-mono">
                                {item.story_points}
                            </Badge>
                        )}
                        {item.due_date && (
                            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                <Calendar className="size-3" />
                            </div>
                        )}
                        {(item.comments_count ?? 0) > 0 && (
                            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                <MessageSquare className="size-3" />
                                <span>{item.comments_count}</span>
                            </div>
                        )}
                    </div>
                    {item.assignee ? (
                        <Avatar className="size-6 ring-2 ring-background">
                            {item.assignee.avatar_display && (
                                <AvatarImage src={item.assignee.avatar_display} alt={item.assignee.name} />
                            )}
                            <AvatarFallback className="text-[10px] bg-gradient-to-br from-brand-blue to-brand-slate text-white font-semibold">
                                {item.assignee.name.charAt(0).toUpperCase()}
                            </AvatarFallback>
                        </Avatar>
                    ) : (
                        <div className="size-6 rounded-full border-2 border-dashed border-muted-foreground/30 flex items-center justify-center">
                            <UserIcon className="size-3 text-muted-foreground/50" />
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

// Create Item Inline
function CreateItemInline({
    column,
    workspace,
    project,
    board,
    itemTypes,
    rootItems,
}: {
    column: BoardColumn;
    workspace: Workspace;
    project: Project;
    board: Board;
    itemTypes: ItemType[];
    rootItems: Item[];
}) {
    const [isCreating, setIsCreating] = useState(false);
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
            setIsCreating(false);
            reset();
        }
    };

    if (!isCreating) {
        return (
            <Button
                variant="ghost"
                size="sm"
                className="w-full justify-start text-muted-foreground hover:text-foreground gap-2 h-9"
                onClick={() => {
                    setIsCreating(true);
                    setTimeout(() => inputRef.current?.focus(), 0);
                }}
            >
                <Plus className="size-4" />
                Create
            </Button>
        );
    }

    return (
        <form onSubmit={handleSubmit} className="space-y-2">
            <Card className="bg-background shadow-sm">
                <CardContent className="p-3 space-y-2">
                    <Input
                        ref={inputRef}
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder="What needs to be done?"
                        className="border-0 p-0 h-auto text-sm focus-visible:ring-0 placeholder:text-muted-foreground/60"
                        autoFocus
                    />
                    <div className="flex flex-col gap-1">
                        <Label className="text-[10px] text-muted-foreground">Type</Label>
                        <Select value={data.item_type_id || ''} onValueChange={(v) => setData('item_type_id', v)}>
                            <SelectTrigger className="h-8 text-xs">
                                <SelectValue placeholder="Type" />
                            </SelectTrigger>
                            <SelectContent>
                                {itemTypes.map((type) => (
                                    <SelectItem key={type.id} value={String(type.id)}>
                                        <div className="flex items-center gap-2">
                                            <ItemTypeIcon slug={type.slug} />
                                            {type.name}
                                        </div>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1">
                        <Label className="text-[10px] text-muted-foreground">
                            Parent task {isSubtask && <span className="text-destructive">*</span>}
                        </Label>
                        <Select
                            value={data.parent_id === '' || data.parent_id == null ? 'none' : String(data.parent_id)}
                            onValueChange={(v) => setData('parent_id', v === 'none' ? '' : Number(v))}
                        >
                            <SelectTrigger className="h-8 text-xs">
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
                </CardContent>
            </Card>
            <div className="flex items-center gap-2">
                <Button type="submit" size="sm" disabled={processing || !canSubmit}>
                    Create
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setIsCreating(false);
                        reset();
                    }}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

// Column Component
function BoardColumnComponent({
    column,
    workspace,
    project,
    board,
    itemTypes,
    rootItems,
    onEditItem,
    onDragStart,
    onDragEnd,
    onDragOver,
    onDrop,
    isDragOver,
}: {
    column: BoardColumn & { items: Item[] };
    workspace: Workspace;
    project: Project;
    board: Board;
    itemTypes: ItemType[];
    rootItems: Item[];
    onEditItem: (item: Item) => void;
    onDragStart: (e: DragEvent, item: Item) => void;
    onDragEnd: () => void;
    onDragOver: (e: DragEvent, column: BoardColumn) => void;
    onDrop: (e: DragEvent, column: BoardColumn) => void;
    isDragOver: boolean;
}) {
    const columnColor = column.color || '#94a3b8';

    return (
        <div
            className={`flex-shrink-0 w-[280px] flex flex-col max-h-full rounded-xl transition-colors ${
                isDragOver ? 'ring-2 ring-primary/40' : ''
            } bg-muted dark:bg-muted/60`}
            onDragOver={(e) => onDragOver(e, column)}
            onDrop={(e) => onDrop(e, column)}
        >
            {/* Column Header */}
            <div className="px-3 py-3 flex items-center justify-between bg-secondary dark:bg-secondary/50 rounded-t-xl border-b border-border">
                <div className="flex items-center gap-2">
                    <div
                        className="w-1 h-4 rounded-full"
                        style={{ backgroundColor: columnColor }}
                    />
                    <h3 className="font-semibold text-sm uppercase tracking-wide text-foreground/80">
                        {column.name}
                    </h3>
                    <Badge variant="secondary" className="text-xs font-medium px-1.5 py-0 h-5 min-w-[20px] flex items-center justify-center">
                        {column.items?.length || 0}
                    </Badge>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-7">
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem>Edit column</DropdownMenuItem>
                        <DropdownMenuItem>Set WIP limit</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem className="text-destructive">Delete column</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            {/* Items */}
            <ScrollArea className="flex-1 px-2 pt-2">
                <div className="space-y-2 pb-2 pr-1">
                    {column.items?.map((item) => (
                        <ItemCard
                            key={item.id}
                            item={item}
                            workspace={workspace}
                            project={project}
                            board={board}
                            onEdit={onEditItem}
                            onDragStart={onDragStart}
                            onDragEnd={onDragEnd}
                        />
                    ))}
                </div>
            </ScrollArea>

            {/* Create Item */}
            <div className="p-2 pt-0">
                <CreateItemInline
                    column={column}
                    workspace={workspace}
                    project={project}
                    board={board}
                    itemTypes={itemTypes}
                    rootItems={rootItems}
                />
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
    columns,
    open,
    onClose,
}: {
    item: Item | null;
    workspace: Workspace;
    project: Project;
    board: Board;
    itemTypes: ItemType[];
    priorities: ItemPriority[];
    projectUsers: User[];
    projectLabels: LabelType[];
    columns: BoardColumn[];
    open: boolean;
    onClose: () => void;
}) {
    const [selectedLabels, setSelectedLabels] = useState<number[]>(
        item?.labels?.map(l => l.id) || []
    );
    const [labelSearch, setLabelSearch] = useState('');
    const [labelPopoverOpen, setLabelPopoverOpen] = useState(false);
    const [newLabelName, setNewLabelName] = useState('');
    const [comment, setComment] = useState('');
    const [commentEditorKey, setCommentEditorKey] = useState(0);
    const [commentActive, setCommentActive] = useState(false);
    const [replyingTo, setReplyingTo] = useState<{ id: number; parentId?: number; userName: string } | null>(null);
    const [replyText, setReplyText] = useState('');
    const [replyEditorKey, setReplyEditorKey] = useState(0);
    const [localComments, setLocalComments] = useState<Comment[]>(item?.comments || []);
    const [localAttachments, setLocalAttachments] = useState<Attachment[]>(item?.attachments || []);
    const [isUploading, setIsUploading] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Title inline editing
    const [titleValue, setTitleValue] = useState(item?.title || '');
    const [isEditingTitle, setIsEditingTitle] = useState(false);
    const [titleDraft, setTitleDraft] = useState(item?.title || '');
    const titleInputRef = useRef<HTMLInputElement>(null);

    // Description edit/view state
    const parseDescription = useCallback((desc: string | null | undefined): string => {
        if (!desc) return '';
        try {
            const parsed = JSON.parse(desc);
            if (parsed && typeof parsed === 'object' && Array.isArray(parsed.blocks)) {
                return parsed.blocks.map((block: { type: string; data: { text?: string } }) => {
                    if (block.type === 'paragraph') return `<p>${block.data?.text || ''}</p>`;
                    return block.data?.text || '';
                }).join('');
            }
            return desc;
        } catch { return desc || ''; }
    }, []);

    const [editorContent, setEditorContent] = useState<string>(() => parseDescription(item?.description));
    const [savedDescription, setSavedDescription] = useState<string>(() => parseDescription(item?.description));
    const [editorKey, setEditorKey] = useState(0);
    const [isEditingDescription, setIsEditingDescription] = useState(false);

    // Sidebar property state
    const [columnId, setColumnId] = useState(item?.column_id?.toString() || '');
    const [assignedTo, setAssignedTo] = useState(item?.assigned_to?.toString() || '');
    const [itemTypeId, setItemTypeId] = useState(item?.item_type_id?.toString() || '');
    const [priorityId, setPriorityId] = useState(item?.priority_id?.toString() || '');
    const [parentId, setParentId] = useState(item?.parent_id?.toString() || '');
    const [storyPoints, setStoryPoints] = useState(item?.story_points?.toString() || '');
    const [dueDate, setDueDate] = useState(item?.due_date?.toString().split('T')[0] || '');

    // File preview
    const [filePreview, setFilePreview] = useState<{ url: string; title: string; previewType: PreviewType } | null>(null);

    // Update local data when item changes
    useEffect(() => {
        if (item?.comments) setLocalComments(item.comments);
        if (item?.attachments) setLocalAttachments(item.attachments);
    }, [item?.comments, item?.attachments]);

    // Sync state when item changes (on open)
    useEffect(() => {
        if (item) {
            setTitleValue(item.title || '');
            setTitleDraft(item.title || '');
            setSelectedLabels(item.labels?.map(l => l.id) || []);
            setColumnId(item.column_id?.toString() || '');
            setAssignedTo(item.assigned_to?.toString() || '');
            setItemTypeId(item.item_type_id?.toString() || '');
            setPriorityId(item.priority_id?.toString() || '');
            setParentId(item.parent_id?.toString() || '');
            setStoryPoints(item.story_points?.toString() || '');
            setDueDate(item.due_date?.toString().split('T')[0] || '');
            const parsed = parseDescription(item.description);
            setEditorContent(parsed);
            setSavedDescription(parsed);
        }
    }, [item?.id]);

    // Generic save helper
    const saveProp = useCallback((fields: Record<string, any>) => {
        if (!item) return;
        router.patch(
            route('boards.items.update', [workspace.slug, project.slug, board.slug, item.id]),
            fields,
            { preserveScroll: true },
        );
    }, [workspace.slug, project.slug, board.slug, item?.id]);

    // Title handlers
    const startEditTitle = () => {
        setTitleDraft(titleValue);
        setIsEditingTitle(true);
        setTimeout(() => titleInputRef.current?.focus(), 0);
    };
    const saveTitle = () => {
        if (!titleDraft.trim()) return;
        setTitleValue(titleDraft);
        setIsEditingTitle(false);
        saveProp({ title: titleDraft });
    };
    const cancelTitle = () => {
        setTitleDraft(titleValue);
        setIsEditingTitle(false);
    };

    // Description handlers
    const handleEditorChange = useCallback((content: string) => {
        setEditorContent(content);
    }, []);
    const startEditDescription = () => setIsEditingDescription(true);
    const saveDescription = () => {
        setSavedDescription(editorContent);
        setIsEditingDescription(false);
        saveProp({ description: editorContent });
    };
    const cancelDescription = () => {
        setEditorContent(savedDescription);
        setEditorKey(k => k + 1);
        setIsEditingDescription(false);
    };

    // Column/label handlers
    const handleMoveToColumn = (newColId: string) => {
        setColumnId(newColId);
        if (!item) return;
        router.patch(route('boards.items.move', [workspace.slug, project.slug, board.slug, item.id]), {
            column_id: parseInt(newColId),
            position: 0,
        }, { preserveScroll: true });
    };

    const toggleLabel = (labelId: number) => {
        if (!item) return;
        const newLabels = selectedLabels.includes(labelId)
            ? selectedLabels.filter(id => id !== labelId)
            : [...selectedLabels, labelId];
        setSelectedLabels(newLabels);
        router.patch(route('boards.items.labels', [workspace.slug, project.slug, board.slug, item.id]), {
            labels: newLabels,
        }, { preserveScroll: true });
    };

    const handleCreateLabel = () => {
        if (!newLabelName.trim()) return;
        router.post(route('projects.labels.store', [workspace.slug, project.slug]), {
            name: newLabelName.trim(),
            color: '#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0'),
        }, { preserveScroll: true, onSuccess: () => setNewLabelName('') });
    };

    // Comment helpers
    const addReplyToComments = (comments: Comment[], parentId: number, newReply: Comment): Comment[] =>
        comments.map(c => {
            if (c.id === parentId) return { ...c, replies: [...(c.replies || []), newReply] };
            if (c.replies?.length) return { ...c, replies: addReplyToComments(c.replies, parentId, newReply) };
            return c;
        });

    const handlePostComment = (parentId?: number) => {
        const rawBody = replyingTo ? replyText : comment;
        const textBody = rawBody.replace(/<[^>]*>/g, '').trim();
        if (!textBody || !item) return;

        const optimisticComment: Comment = {
            id: Date.now(),
            item_id: item.id,
            user_id: 0,
            parent_id: parentId || undefined,
            body: rawBody,
            user: { id: 0, name: 'You', email: '' },
            replies: [],
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
        };

        if (parentId) {
            setLocalComments(prev => addReplyToComments(prev, parentId, optimisticComment));
            setReplyText('');
            setReplyEditorKey(k => k + 1);
            setReplyingTo(null);
        } else {
            setLocalComments(prev => [optimisticComment, ...prev]);
            setComment('');
            setCommentEditorKey(k => k + 1);
            setCommentActive(false);
        }

        router.post(route('boards.items.comments.store', [workspace.slug, project.slug, board.slug, item.id]), {
            body: rawBody,
            parent_id: parentId || null,
        }, { preserveScroll: true });
    };

    const handleReply = (commentId: number, userName: string, parentId?: number) => {
        setReplyingTo({ id: commentId, parentId, userName });
        setReplyText('');
    };

    const cancelReply = () => { setReplyingTo(null); setReplyText(''); setReplyEditorKey(k => k + 1); };

    // Attachment handlers
    const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = e.target.files;
        if (!files || files.length === 0 || !item) return;
        setIsUploading(true);
        const formData = new FormData();
        formData.append('file', files[0]);
        router.post(route('boards.items.attachments.store', [workspace.slug, project.slug, board.slug, item.id]), formData, {
            preserveScroll: true,
            onFinish: () => {
                setIsUploading(false);
                if (fileInputRef.current) fileInputRef.current.value = '';
            },
        });
    };

    const handleDeleteAttachment = (attachmentId: number) => {
        if (!item) return;
        setLocalAttachments(prev => prev.filter(a => a.id !== attachmentId));
        router.delete(route('boards.items.attachments.destroy', [workspace.slug, project.slug, board.slug, item.id, attachmentId]), {
            preserveScroll: true,
        });
    };

    // Checkbox toggle in view mode
    const handleCheckboxToggle = useCallback((e: React.MouseEvent<HTMLDivElement>) => {
        const target = e.target as HTMLElement;
        const li = target.closest('li[role="checkbox"]') as HTMLElement | null;
        if (!li) return;
        e.preventDefault();
        e.stopPropagation();
        const container = e.currentTarget;
        const allItems = Array.from(container.querySelectorAll('li[role="checkbox"]'));
        const idx = allItems.indexOf(li);
        if (idx === -1) return;
        const parser = new DOMParser();
        const doc = parser.parseFromString(savedDescription, 'text/html');
        const docItems = Array.from(doc.querySelectorAll('li[role="checkbox"]'));
        if (!docItems[idx]) return;
        const isChecked = docItems[idx].getAttribute('aria-checked') === 'true';
        docItems[idx].setAttribute('aria-checked', isChecked ? 'false' : 'true');
        if (isChecked) {
            docItems[idx].classList.remove('lexical-listitem-checked', 'line-through', 'text-muted-foreground');
            docItems[idx].classList.add('lexical-listitem-unchecked');
        } else {
            docItems[idx].classList.remove('lexical-listitem-unchecked');
            docItems[idx].classList.add('lexical-listitem-checked', 'line-through', 'text-muted-foreground');
        }
        const newHtml = doc.body.innerHTML;
        setSavedDescription(newHtml);
        setEditorContent(newHtml);
        setEditorKey(k => k + 1);
        saveProp({ description: newHtml });
    }, [savedDescription, saveProp]);

    const filteredLabels = projectLabels.filter(label =>
        label.name.toLowerCase().includes(labelSearch.toLowerCase())
    );

    if (!item) return null;

    return (
        <>
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-6xl max-h-[92vh] p-0 gap-0 overflow-hidden [&>button]:hidden" aria-describedby={undefined}>
                <DialogTitle className="sr-only">Edit {item.key}: {item.title}</DialogTitle>

                {/* ── Header ─────────────────────────────────────────── */}
                <div className="flex items-center justify-between px-6 py-3 border-b bg-background shrink-0">
                    <div className="flex items-center gap-3">
                        <ItemTypeIcon slug={item.item_type?.slug} className="size-4" />
                        <span className="font-mono text-xs font-semibold text-primary tracking-wider">{item.key}</span>
                        {item.column && (
                            <Badge variant="secondary" className="text-xs">
                                <span className="w-1.5 h-1.5 rounded-full mr-1.5 inline-block" style={{ backgroundColor: (item.column as any).color || '#94a3b8' }} />
                                {(item.column as any).name}
                            </Badge>
                        )}
                    </div>
                    <div className="flex items-center gap-1">
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
                <div className="flex overflow-hidden" style={{ maxHeight: 'calc(92vh - 57px)' }}>

                    {/* ── Main column ──────────────────────────────────── */}
                    <div className="flex-1 overflow-y-auto min-w-0">
                        <div className="px-8 py-6 space-y-0">

                            {/* ── Title ──────────────────────────────────── */}
                            <div className="mb-0">
                                {isEditingTitle ? (
                                    <div className="flex items-center gap-2">
                                        <Input
                                            ref={titleInputRef}
                                            value={titleDraft}
                                            onChange={(e) => setTitleDraft(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') saveTitle();
                                                if (e.key === 'Escape') cancelTitle();
                                            }}
                                            className="text-xl font-semibold border-0 border-b-2 border-primary rounded-none px-0 py-1 h-auto focus-visible:ring-0 bg-transparent flex-1 leading-snug"
                                            placeholder="Issue title"
                                        />
                                        <Button size="icon" variant="ghost" className="size-8 text-green-600 hover:text-green-700 hover:bg-green-50 shrink-0" onClick={saveTitle}>
                                            <Check className="size-4" />
                                        </Button>
                                        <Button size="icon" variant="ghost" className="size-8 text-muted-foreground shrink-0" onClick={cancelTitle}>
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                ) : (
                                    <div
                                        className="group flex items-center gap-2 cursor-text rounded-md -mx-2 px-2 py-1 hover:bg-muted/60 transition-colors"
                                        onClick={startEditTitle}
                                    >
                                        <h1 className="text-xl font-semibold text-foreground leading-snug flex-1">
                                            {titleValue || <span className="text-muted-foreground font-normal">Issue title</span>}
                                        </h1>
                                        <Pencil className="size-3.5 text-muted-foreground/0 group-hover:text-muted-foreground/60 transition-colors shrink-0" />
                                    </div>
                                )}
                            </div>

                            {/* ── Description ────────────────────────────── */}
                            <section className="pt-3 pb-4 border-t border-border/60">
                                <h2 className="text-sm font-semibold mb-3">Description</h2>
                                {isEditingDescription ? (
                                    <div>
                                        <RichLexicalEditor
                                            key={`edit-${editorKey}`}
                                            content={editorContent}
                                            onChange={handleEditorChange}
                                            placeholder="Add a description…"
                                            editable={true}
                                            minHeight="160px"
                                            maxHeight="460px"
                                            contentKey={`${item.id}-edit-${editorKey}`}
                                            workspaceSlug={workspace.slug}
                                            itemTitle={titleValue}
                                            projectName={project.name}
                                        />
                                        <div className="flex items-center gap-2 mt-3 justify-end">
                                            <Button variant="outline" size="sm" onClick={cancelDescription}>Discard</Button>
                                            <Button size="sm" onClick={saveDescription}>Save</Button>
                                        </div>
                                    </div>
                                ) : (
                                    <div
                                        className="cursor-text rounded-md -mx-2 px-2 py-1 hover:bg-muted/60 transition-colors group relative select-text"
                                        onClick={handleCheckboxToggle}
                                        onDoubleClick={startEditDescription}
                                    >
                                        {savedDescription ? (
                                            <RichLexicalEditor
                                                key={`view-${editorKey}`}
                                                content={savedDescription}
                                                onChange={() => {}}
                                                editable={false}
                                                showToolbar={false}
                                                minHeight="40px"
                                                maxHeight="460px"
                                                className="border-0 bg-transparent rounded-none shadow-none"
                                                contentKey={`${item.id}-view-${editorKey}`}
                                            />
                                        ) : (
                                            <p className="text-sm text-muted-foreground py-2">Add a description…</p>
                                        )}
                                        <div className="absolute bottom-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                                            <span className="text-[11px] bg-muted/80 text-muted-foreground px-1.5 py-0.5 rounded border">Double-click to edit</span>
                                        </div>
                                    </div>
                                )}
                            </section>

                            {/* ── Attachments ────────────────────────────── */}
                            <section className="py-5 border-t border-border/60">
                                <div className="flex items-center justify-between mb-3">
                                    <h2 className="text-sm font-semibold flex items-center gap-2">
                                        <Paperclip className="size-4 text-muted-foreground" />
                                        Attachments
                                        {localAttachments.length > 0 && (
                                            <span className="text-xs font-normal text-muted-foreground">({localAttachments.length})</span>
                                        )}
                                    </h2>
                                    <div>
                                        <input type="file" ref={fileInputRef} onChange={handleFileUpload} className="hidden" />
                                        <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground hover:text-foreground gap-1.5" onClick={() => fileInputRef.current?.click()} disabled={isUploading}>
                                            <Upload className="size-3.5" />
                                            {isUploading ? 'Uploading…' : 'Add'}
                                        </Button>
                                    </div>
                                </div>

                                {localAttachments.length > 0 ? (
                                    <div className="space-y-1.5">
                                        {localAttachments.map((attachment) => {
                                            const { Icon, colorClass } = getFileIconByName(attachment.original_filename || attachment.filename || '');
                                            const previewType = getFilePreviewType(attachment.original_filename || attachment.filename || '');
                                            const canPreview = previewType !== 'other';
                                            return (
                                                <div key={attachment.id} className="flex items-center gap-3 px-3 py-2 rounded-lg group hover:bg-muted/80 transition-colors -mx-1">
                                                    {previewType === 'image' ? (
                                                        <div className="size-9 rounded border overflow-hidden bg-muted shrink-0">
                                                            <img src={attachment.url} alt={attachment.original_filename} className="size-full object-cover" />
                                                        </div>
                                                    ) : (
                                                        <div className="size-9 flex items-center justify-center rounded border bg-background shrink-0">
                                                            <Icon className={`size-4 ${colorClass}`} />
                                                        </div>
                                                    )}
                                                    <div className="flex-1 min-w-0">
                                                        <p className="text-sm font-medium truncate leading-none">{attachment.original_filename}</p>
                                                        <p className="text-xs text-muted-foreground mt-0.5">
                                                            {attachment.formatted_size || `${Math.round(attachment.size / 1024)} KB`} · {attachment.user?.name || 'Unknown'}
                                                        </p>
                                                    </div>
                                                    <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                                        {canPreview && (
                                                            <Button variant="ghost" size="icon" className="size-7" title="Preview"
                                                                onClick={() => setFilePreview({ url: attachment.url ?? '', title: attachment.original_filename ?? '', previewType })}>
                                                                <Eye className="size-3.5" />
                                                            </Button>
                                                        )}
                                                        <a href={attachment.url} target="_blank" rel="noopener noreferrer" download={attachment.original_filename}>
                                                            <Button variant="ghost" size="icon" className="size-7" title="Download"><Download className="size-3.5" /></Button>
                                                        </a>
                                                        <Button variant="ghost" size="icon" className="size-7 text-destructive hover:text-destructive" onClick={() => handleDeleteAttachment(attachment.id)}>
                                                            <Trash2 className="size-3.5" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <button onClick={() => fileInputRef.current?.click()} className="w-full text-left text-sm text-muted-foreground hover:text-foreground transition-colors py-1">
                                        + Add attachment
                                    </button>
                                )}
                            </section>

                            {/* ── Activity / Comments ─────────────────────── */}
                            <section className="py-5 border-t border-border/60">
                                <h2 className="text-sm font-semibold flex items-center gap-2 mb-5">
                                    <MessageSquare className="size-4 text-muted-foreground" />
                                    Activity
                                    {localComments.length > 0 && <span className="text-xs font-normal text-muted-foreground">({localComments.length})</span>}
                                </h2>

                                {/* New comment input */}
                                <div className="flex gap-3 mb-6">
                                    <Avatar className="size-8 shrink-0 mt-2">
                                        <AvatarFallback className="text-xs bg-primary text-primary-foreground">U</AvatarFallback>
                                    </Avatar>
                                    <div className="flex-1 min-w-0">
                                        <div
                                            className={`rounded-lg border transition-colors ${commentActive ? 'border-primary ring-1 ring-primary/20' : 'border-border hover:border-muted-foreground/40'}`}
                                            onClick={() => setCommentActive(true)}
                                        >
                                            <RichLexicalEditor
                                                key={commentEditorKey}
                                                content={comment || null}
                                                onChange={(html) => {
                                                    setComment(html);
                                                    if (!commentActive) setCommentActive(true);
                                                }}
                                                placeholder="Add a comment…"
                                                editable={true}
                                                showToolbar={commentActive}
                                                minHeight="60px"
                                                maxHeight="240px"
                                                contentKey={`comment-${commentEditorKey}`}
                                            />
                                        </div>
                                        {commentActive && (
                                            <div className="flex justify-end mt-2 gap-2">
                                                <Button variant="outline" size="sm" onClick={() => {
                                                    setComment('');
                                                    setCommentEditorKey(k => k + 1);
                                                    setCommentActive(false);
                                                }}>Cancel</Button>
                                                <Button size="sm" onClick={() => handlePostComment()}>Save</Button>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Existing comments */}
                                {localComments.length > 0 && (
                                    <div className="space-y-5">
                                        {localComments.map((c) => (
                                            <CommentItem
                                                key={c.id}
                                                comment={c}
                                                depth={0}
                                                replyingTo={replyingTo}
                                                replyText={replyText}
                                                setReplyText={setReplyText}
                                                handleReply={handleReply}
                                                handlePostComment={handlePostComment}
                                                cancelReply={cancelReply}
                                                replyEditorKey={replyEditorKey}
                                            />
                                        ))}
                                    </div>
                                )}
                            </section>

                        </div>
                    </div>{/* /main column */}

                    {/* ── Sidebar ──────────────────────────────────────── */}
                    <div className="w-[300px] shrink-0 overflow-y-auto border-l bg-background">
                        <div className="px-5 py-5">
                            <h2 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">Details</h2>

                            <div className="space-y-0 divide-y divide-border/50">
                                {/* Status */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Status</span>
                                    <div className="flex-1">
                                        <Select value={columnId} onValueChange={handleMoveToColumn}>
                                            <SelectTrigger className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 transition-colors focus:ring-0 text-sm px-2 -ml-2 rounded-md">
                                                <SelectValue placeholder="None" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {columns.map((col) => (
                                                    <SelectItem key={col.id} value={col.id.toString()}>
                                                        <div className="flex items-center gap-2">
                                                            <div className="w-2 h-2 rounded-full" style={{ backgroundColor: col.color || '#94a3b8' }} />
                                                            {col.name}
                                                        </div>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                {/* Assignee */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Assignee</span>
                                    <div className="flex-1">
                                        <Select value={assignedTo || 'unassigned'} onValueChange={(v) => { const val = v === 'unassigned' ? '' : v; setAssignedTo(val); saveProp({ assigned_to: val || null }); }}>
                                            <SelectTrigger className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 transition-colors focus:ring-0 text-sm px-2 -ml-2 rounded-md">
                                                <SelectValue placeholder="Unassigned" />
                                            </SelectTrigger>
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
                                </div>

                                {/* Type */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Type</span>
                                    <div className="flex-1">
                                        <Select value={itemTypeId} onValueChange={(v) => { setItemTypeId(v); saveProp({ item_type_id: v }); }}>
                                            <SelectTrigger className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 transition-colors focus:ring-0 text-sm px-2 -ml-2 rounded-md">
                                                <SelectValue placeholder="None" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {itemTypes.map((type) => (
                                                    <SelectItem key={type.id} value={type.id.toString()}>
                                                        <div className="flex items-center gap-2"><ItemTypeIcon slug={type.slug} />{type.name}</div>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                {/* Parent task */}
                                {(() => {
                                    const rootItemsForEdit = columns.flatMap((c) => (c as BoardColumn & { items?: Item[] }).items || []).filter((i) => !i.parent_id && i.id !== item?.id);
                                    const isSubtaskType = itemTypes.some((t) => t.slug === 'subtask' && t.id.toString() === itemTypeId);
                                    return (
                                        <div className="flex items-center min-h-[40px] py-1">
                                            <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Parent</span>
                                            <div className="flex-1">
                                                <Select
                                                    value={parentId || 'none'}
                                                    onValueChange={(v) => {
                                                        const val = v === 'none' ? '' : v;
                                                        setParentId(val);
                                                        saveProp({ parent_id: val ? parseInt(val, 10) : null });
                                                    }}
                                                >
                                                    <SelectTrigger className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 transition-colors focus:ring-0 text-sm px-2 -ml-2 rounded-md">
                                                        <SelectValue placeholder="None" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="none" disabled={isSubtaskType}>None</SelectItem>
                                                        {rootItemsForEdit.map((r) => (
                                                            <SelectItem key={r.id} value={r.id.toString()}>
                                                                <span className="font-mono text-xs">{r.key}</span> {r.title}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    );
                                })()}

                                {/* Priority */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Priority</span>
                                    <div className="flex-1">
                                        <Select value={priorityId} onValueChange={(v) => { setPriorityId(v); saveProp({ priority_id: v }); }}>
                                            <SelectTrigger className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 transition-colors focus:ring-0 text-sm px-2 -ml-2 rounded-md">
                                                <SelectValue placeholder="None" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {priorities.map((priority) => (
                                                    <SelectItem key={priority.id} value={priority.id.toString()}>
                                                        <div className="flex items-center gap-2"><PriorityIcon slug={priority.slug} />{priority.name}</div>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                {/* Story Points */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Points</span>
                                    <div className="flex-1 px-2 -ml-2">
                                        <Input
                                            type="number" min="0"
                                            value={storyPoints}
                                            onChange={(e) => setStoryPoints(e.target.value)}
                                            onBlur={() => saveProp({ story_points: storyPoints || null })}
                                            className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md"
                                            placeholder="None"
                                        />
                                    </div>
                                </div>

                                {/* Due Date */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Due Date</span>
                                    <div className="flex-1 px-2 -ml-2">
                                        <Input
                                            type="date"
                                            value={dueDate}
                                            onChange={(e) => { setDueDate(e.target.value); saveProp({ due_date: e.target.value || null }); }}
                                            className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md"
                                        />
                                    </div>
                                </div>

                                {/* Reporter */}
                                {item.reporter && (
                                    <div className="flex items-center min-h-[40px] py-1">
                                        <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Reporter</span>
                                        <div className="flex-1 flex items-center gap-2 px-2">
                                            <Avatar className="size-5 shrink-0"><AvatarFallback className="text-[10px]">{item.reporter.name.charAt(0)}</AvatarFallback></Avatar>
                                            <span className="text-sm truncate">{item.reporter.name}</span>
                                        </div>
                                    </div>
                                )}

                                {/* Created */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Created</span>
                                    <span className="flex-1 text-sm px-2">{new Date(item.created_at).toLocaleDateString()}</span>
                                </div>

                                {/* Updated */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Updated</span>
                                    <span className="flex-1 text-sm px-2">{new Date(item.updated_at).toLocaleDateString()}</span>
                                </div>

                                {/* Labels */}
                                <div className="flex items-start min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground pt-2">Labels</span>
                                    <div className="flex-1">
                                        <Popover open={labelPopoverOpen} onOpenChange={(o) => { setLabelPopoverOpen(o); if (!o) setLabelSearch(''); }}>
                                            <PopoverTrigger asChild>
                                                <button className="w-full text-left rounded-md px-2 py-1 -ml-2 hover:bg-muted/80 transition-colors min-h-[32px] flex flex-wrap gap-1 items-center">
                                                    {selectedLabels.length === 0 ? (
                                                        <span className="text-sm text-muted-foreground">None</span>
                                                    ) : (
                                                        selectedLabels.map((id) => {
                                                            const lbl = projectLabels.find(l => l.id === id);
                                                            if (!lbl) return null;
                                                            return (
                                                                <span key={id} className="inline-flex items-center gap-1 text-[11px] font-medium px-1.5 py-0.5 rounded-full"
                                                                    style={{ backgroundColor: lbl.color + '22', color: lbl.color, border: `1px solid ${lbl.color}55` }}>
                                                                    {lbl.name}
                                                                </span>
                                                            );
                                                        })
                                                    )}
                                                </button>
                                            </PopoverTrigger>
                                            <PopoverContent className="p-0 w-56" align="start">
                                                <div className="p-2 border-b">
                                                    <Input autoFocus placeholder="Search labels…" value={labelSearch} onChange={(e) => setLabelSearch(e.target.value)}
                                                        className="h-7 text-xs border-0 shadow-none focus-visible:ring-0 px-1" />
                                                </div>
                                                <div className="max-h-52 overflow-y-auto p-1">
                                                    {filteredLabels.map((label) => {
                                                        const active = selectedLabels.includes(label.id);
                                                        return (
                                                            <button key={label.id} onClick={() => toggleLabel(label.id)}
                                                                className="flex items-center gap-2 w-full px-2 py-1.5 rounded-md text-sm hover:bg-muted/60 transition-colors text-left">
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
                                                        <Input placeholder="New label name…" value={newLabelName} onChange={(e) => setNewLabelName(e.target.value)}
                                                            className="h-7 text-xs flex-1"
                                                            onKeyDown={(e) => { if (e.key === 'Enter' && newLabelName.trim()) { e.preventDefault(); handleCreateLabel(); } }} />
                                                        <Button size="sm" className="h-7 px-2" onClick={handleCreateLabel} disabled={!newLabelName.trim()}>
                                                            <Plus className="size-3" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            </PopoverContent>
                                        </Popover>
                                    </div>
                                </div>
                            </div>{/* /divide-y */}
                        </div>
                    </div>{/* /sidebar */}
                </div>{/* /two-panel */}
            </DialogContent>
        </Dialog>

        {/* File preview modal */}
        <Dialog open={!!filePreview} onOpenChange={(open) => !open && setFilePreview(null)}>
            <DialogContent className="max-w-4xl max-h-[90vh] flex flex-col p-0 overflow-hidden">
                {filePreview && (
                    <>
                        <DialogHeader className="px-6 py-3 border-b shrink-0">
                            <DialogTitle className="truncate pr-8">{filePreview.title}</DialogTitle>
                        </DialogHeader>
                        <div className="flex-1 min-h-0 overflow-auto p-6 bg-muted/30">
                            {filePreview.previewType === 'pdf' && (
                                <iframe src={filePreview.url} title={filePreview.title} className="w-full h-[70vh] rounded border bg-background" />
                            )}
                            {filePreview.previewType === 'image' && (
                                <img src={filePreview.url} alt={filePreview.title} className="max-w-full max-h-[70vh] object-contain mx-auto rounded" />
                            )}
                            {filePreview.previewType === 'video' && (
                                <video src={filePreview.url} controls className="w-full max-h-[70vh] rounded" title={filePreview.title} />
                            )}
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
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
                                        <ItemTypeIcon slug={item.item_type?.slug} className="size-4" />
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
                                        <PriorityIcon slug={item.priority?.slug} />
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

// Gantt/Timeline View Component
function GanttView({
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

    // Calculate date range for the timeline (next 30 days)
    const today = new Date();
    const startDate = new Date(today);
    startDate.setDate(startDate.getDate() - 7);
    const endDate = new Date(today);
    endDate.setDate(endDate.getDate() + 30);

    const days: Date[] = [];
    const current = new Date(startDate);
    while (current <= endDate) {
        days.push(new Date(current));
        current.setDate(current.getDate() + 1);
    }

    const getItemPosition = (item: Item) => {
        if (!item.start_date && !item.due_date) return null;

        const itemStart = item.start_date ? new Date(item.start_date) : new Date(item.due_date!);
        const itemEnd = item.due_date ? new Date(item.due_date) : new Date(item.start_date!);

        const dayWidth = 40; // pixels per day
        const startOffset = Math.floor((itemStart.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24));
        const duration = Math.max(1, Math.ceil((itemEnd.getTime() - itemStart.getTime()) / (1000 * 60 * 60 * 24)) + 1);

        return {
            left: startOffset * dayWidth,
            width: duration * dayWidth,
        };
    };

    return (
        <div className="flex-1 overflow-auto bg-background">
            <div className="flex">
                {/* Left sidebar - Item list */}
                <div className="w-72 flex-shrink-0 border-r bg-muted/30">
                    <div className="h-12 border-b px-4 flex items-center font-medium text-sm">
                        Items
                    </div>
                    {allItems.map((item) => (
                        <div
                            key={item.id}
                            className="h-10 border-b px-4 flex items-center gap-2 hover:bg-muted/50 cursor-pointer"
                            onClick={() => onEditItem(item)}
                        >
                            <ItemTypeIcon slug={item.item_type?.slug} className="size-4" />
                            <span className="font-mono text-xs text-primary">{item.key}</span>
                            <span className="text-sm truncate flex-1">{item.title}</span>
                        </div>
                    ))}
                </div>

                {/* Timeline grid */}
                <div className="flex-1 overflow-x-auto">
                    {/* Header with dates */}
                    <div className="flex h-12 border-b sticky top-0 bg-background z-10">
                        {days.map((day, i) => {
                            const isToday = day.toDateString() === today.toDateString();
                            const isWeekend = day.getDay() === 0 || day.getDay() === 6;
                            return (
                                <div
                                    key={i}
                                    className={`w-10 flex-shrink-0 border-r flex flex-col items-center justify-center text-xs ${
                                        isToday ? 'bg-primary/10 text-primary font-bold' : isWeekend ? 'bg-muted/50 text-muted-foreground' : ''
                                    }`}
                                >
                                    <span>{day.getDate()}</span>
                                    <span className="text-[10px]">{day.toLocaleDateString('en', { weekday: 'short' }).charAt(0)}</span>
                                </div>
                            );
                        })}
                    </div>

                    {/* Items rows */}
                    {allItems.map((item) => {
                        const position = getItemPosition(item);
                        return (
                            <div key={item.id} className="h-10 border-b relative flex">
                                {days.map((day, i) => {
                                    const isWeekend = day.getDay() === 0 || day.getDay() === 6;
                                    const isToday = day.toDateString() === today.toDateString();
                                    return (
                                        <div
                                            key={i}
                                            className={`w-10 flex-shrink-0 border-r ${
                                                isToday ? 'bg-primary/5' : isWeekend ? 'bg-muted/30' : ''
                                            }`}
                                        />
                                    );
                                })}
                                {position && (
                                    <div
                                        className="absolute top-1 h-8 rounded-md bg-primary/80 text-primary-foreground text-xs flex items-center px-2 cursor-pointer hover:bg-primary transition-colors shadow-sm"
                                        style={{
                                            left: `${Math.max(0, position.left)}px`,
                                            width: `${position.width}px`,
                                        }}
                                        onClick={() => onEditItem(item)}
                                    >
                                        <span className="truncate">{item.key}</span>
                                    </div>
                                )}
                            </div>
                        );
                    })}

                    {allItems.length === 0 && (
                        <div className="py-12 text-center text-muted-foreground">
                            No items found
                        </div>
                    )}
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
                                className={`min-h-[120px] border-b border-r p-2 ${
                                    !date ? 'bg-muted/30' : ''
                                } ${isToday ? 'bg-primary/5' : ''}`}
                            >
                                {date && (
                                    <>
                                        <div className={`text-sm font-medium mb-2 ${
                                            isToday ? 'text-primary' : 'text-foreground'
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
    itemTypes,
    priorities,
    projectUsers,
    projectLabels,
}: Props) {
    const [editingItem, setEditingItem] = useState<Item | null>(null);
    const [draggedItem, setDraggedItem] = useState<Item | null>(null);
    const [dragOverColumn, setDragOverColumn] = useState<number | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [currentView, setCurrentView] = useState<'kanban' | 'list' | 'gantt' | 'calendar'>('kanban');
    const [calendarDate, setCalendarDate] = useState(new Date());

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

    // Filter items based on search
    const filteredColumns = board.columns.map((col) => ({
        ...col,
        items: col.items?.filter((item) =>
            searchQuery
                ? item.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
                  item.key.toLowerCase().includes(searchQuery.toLowerCase())
                : true
        ) || [],
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
                {/* Board Header */}
                <div className="flex items-center justify-between px-6 py-3 border-b bg-background/95 backdrop-blur">
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-3">
                            <ProjectIcon project={project} size="md" className="shadow-sm" />
                            <div>
                                <h1 className="text-lg font-semibold tracking-tight">{board.name}</h1>
                                <p className="text-xs text-muted-foreground">{project.name}</p>
                            </div>
                        </div>

                        {/* View Tabs */}
                        <div className="flex items-center gap-1 ml-4 p-1 bg-muted rounded-lg">
                            <button
                                onClick={() => setCurrentView('kanban')}
                                className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                                    currentView === 'kanban'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <Kanban className="size-4" />
                                Board
                            </button>
                            <button
                                onClick={() => setCurrentView('list')}
                                className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                                    currentView === 'list'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <List className="size-4" />
                                List
                            </button>
                            <button
                                onClick={() => setCurrentView('gantt')}
                                className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                                    currentView === 'gantt'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <GanttChart className="size-4" />
                                Timeline
                            </button>
                            <button
                                onClick={() => setCurrentView('calendar')}
                                className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                                    currentView === 'calendar'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <Calendar className="size-4" />
                                Calendar
                            </button>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        {/* Search */}
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                            <Input
                                type="search"
                                placeholder="Search board"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="pl-9 h-9 w-64 bg-muted/50 border-0"
                            />
                        </div>

                        {/* Filter */}
                        <Button variant="outline" size="sm" className="gap-2 h-9">
                            <Filter className="size-4" />
                            Filter
                        </Button>

                        {/* Board Settings */}
                        <Link href={route('boards.edit', [workspace.slug, project.slug, board.slug])}>
                            <Button variant="outline" size="icon" className="size-9">
                                <Settings className="size-4" />
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* View Content */}
                {currentView === 'kanban' && (
                    <div className="flex-1 overflow-x-auto overflow-y-hidden bg-muted/50 dark:bg-muted/30">
                        <div className="flex gap-4 p-6 h-full">
                            {filteredColumns.map((column) => (
                                <BoardColumnComponent
                                    key={column.id}
                                    column={column}
                                    workspace={workspace}
                                    project={project}
                                    board={board}
                                    itemTypes={itemTypes}
                                    rootItems={rootItems}
                                    onEditItem={setEditingItem}
                                    onDragStart={handleDragStart}
                                    onDragEnd={handleDragEnd}
                                    onDragOver={handleDragOver}
                                    onDrop={handleDrop}
                                    isDragOver={dragOverColumn === column.id}
                                />
                            ))}

                            {/* Add Column Button */}
                            <div className="flex-shrink-0 w-[280px]">
                                <Link href={route('boards.edit', [workspace.slug, project.slug, board.slug])}>
                                    <Button
                                        variant="ghost"
                                        className="w-full h-12 border-2 border-dashed border-muted-foreground/20 hover:border-muted-foreground/40 text-muted-foreground gap-2"
                                    >
                                        <Plus className="size-4" />
                                        Add Column
                                    </Button>
                                </Link>
                            </div>
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
                columns={board.columns}
                open={!!editingItem}
                onClose={() => setEditingItem(null)}
            />
        </AppLayout>
    );
}
