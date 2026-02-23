import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, BoardColumn, Item, ItemType, ItemPriority, User, Label as LabelType } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Separator } from '@/components/ui/separator';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
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
    ChevronsUpDown,
    CalendarDays,
    Upload,
    File,
    FileImage,
    FileText,
    Trash2,
    Reply,
    Download,
    Kanban,
    List,
    GanttChart,
    ChevronLeft,
    ChevronRight,
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
            return <ChevronDown className={`${baseClass} text-blue-500`} />;
        case 'lowest':
            return <ChevronDown className={`${baseClass} text-slate-400`} />;
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
            return <Bookmark className={`${baseClass} text-green-500`} />;
        case 'epic':
            return <Layers className={`${baseClass} text-purple-500`} />;
        case 'subtask':
            return <Square className={`${baseClass} text-blue-400`} />;
        case 'task':
        default:
            return <CheckSquare className={`${baseClass} text-blue-500`} />;
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
}: CommentItemProps) {
    const maxDepth = 5; // Limit visual nesting depth
    const paddingLeft = Math.min(depth, maxDepth) * 16;
    const avatarSize = depth === 0 ? 'size-8' : 'size-6';
    const avatarTextSize = depth === 0 ? 'text-xs' : 'text-[10px]';
    const nameTextSize = depth === 0 ? 'text-sm' : 'text-xs';
    const timeTextSize = depth === 0 ? 'text-xs' : 'text-[10px]';

    // Find the root parent ID for posting the reply
    const getRootParentId = (c: Comment): number => {
        // For nested structure, we always reply to the top-level parent
        // since the backend nests all replies under the root comment
        return c.id;
    };

    return (
        <div className={`${depth > 0 ? 'border-l-2 border-muted pl-4' : ''}`} style={{ marginLeft: depth > 0 ? 0 : 0 }}>
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

                    {/* Reply input for this specific comment */}
                    {replyingTo?.id === comment.id && (
                        <div className="mt-3">
                            <div className="flex gap-2">
                                <Avatar className="size-6 shrink-0">
                                    <AvatarFallback className="text-[10px] bg-primary text-primary-foreground">
                                        U
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex-1">
                                    <Input
                                        value={replyText}
                                        onChange={(e) => setReplyText(e.target.value)}
                                        placeholder={`Reply to ${replyingTo.userName}...`}
                                        className="h-8 text-sm"
                                        autoFocus
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' && !e.shiftKey && replyText.trim()) {
                                                e.preventDefault();
                                                handlePostComment(comment.id);
                                            }
                                            if (e.key === 'Escape') {
                                                cancelReply();
                                            }
                                        }}
                                    />
                                    <div className="flex justify-end gap-2 mt-2">
                                        <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={cancelReply}>
                                            Cancel
                                        </Button>
                                        <Button size="sm" className="h-7 text-xs" onClick={() => handlePostComment(comment.id)} disabled={!replyText.trim()}>
                                            Reply
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Render nested replies recursively */}
                    {comment.replies && comment.replies.length > 0 && (
                        <div className="mt-3 space-y-3">
                            {comment.replies.map((reply) => (
                                <CommentItem
                                    key={reply.id}
                                    comment={reply}
                                    depth={depth + 1}
                                    replyingTo={replyingTo}
                                    replyText={replyText}
                                    setReplyText={setReplyText}
                                    handleReply={handleReply}
                                    handlePostComment={handlePostComment}
                                    cancelReply={cancelReply}
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
                            <AvatarFallback className="text-[10px] bg-gradient-to-br from-blue-500 to-purple-600 text-white font-semibold">
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
}: {
    column: BoardColumn;
    workspace: Workspace;
    project: Project;
    board: Board;
    itemTypes: ItemType[];
}) {
    const [isCreating, setIsCreating] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, reset } = useForm({
        title: '',
        column_id: column.id,
        item_type_id: itemTypes.find((t) => t.slug === 'task')?.id?.toString() || '',
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!data.title.trim()) return;
        post(route('boards.items.store', [workspace.slug, project.slug, board.slug]), {
            onSuccess: () => {
                reset('title');
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
                <CardContent className="p-3">
                    <Input
                        ref={inputRef}
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder="What needs to be done?"
                        className="border-0 p-0 h-auto text-sm focus-visible:ring-0 placeholder:text-muted-foreground/60"
                        autoFocus
                    />
                </CardContent>
            </Card>
            <div className="flex items-center gap-2">
                <Button type="submit" size="sm" disabled={processing || !data.title.trim()}>
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
            } bg-slate-200/80 dark:bg-slate-800/60`}
            onDragOver={(e) => onDragOver(e, column)}
            onDrop={(e) => onDrop(e, column)}
        >
            {/* Column Header */}
            <div className="px-3 py-3 flex items-center justify-between bg-slate-300/60 dark:bg-slate-700/50 rounded-t-xl border-b border-slate-300 dark:border-slate-700">
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
    const [replyingTo, setReplyingTo] = useState<{ id: number; parentId?: number; userName: string } | null>(null);
    const [replyText, setReplyText] = useState('');
    const [localComments, setLocalComments] = useState<Comment[]>(item?.comments || []);
    const [localAttachments, setLocalAttachments] = useState<Attachment[]>(item?.attachments || []);
    const [isUploading, setIsUploading] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Update local data when item changes
    useEffect(() => {
        if (item?.comments) {
            setLocalComments(item.comments);
        }
        if (item?.attachments) {
            setLocalAttachments(item.attachments);
        }
    }, [item?.comments, item?.attachments]);

    // Parse description - now just returns HTML string
    const parseDescription = useCallback((desc: string | null | undefined): string => {
        if (!desc) return '';
        try {
            // Check if it's EditorJS format
            const parsed = JSON.parse(desc);
            if (parsed && typeof parsed === 'object' && Array.isArray(parsed.blocks)) {
                // Convert EditorJS blocks to HTML
                return parsed.blocks.map((block: { type: string; data: { text?: string } }) => {
                    if (block.type === 'paragraph') {
                        return `<p>${block.data?.text || ''}</p>`;
                    }
                    return block.data?.text || '';
                }).join('');
            }
            return desc;
        } catch {
            return desc || '';
        }
    }, []);

    const [editorContent, setEditorContent] = useState<string>(() => parseDescription(item?.description));

    // Format date for input (YYYY-MM-DD)
    const formatDateForInput = (dateStr: string | undefined | null): string => {
        if (!dateStr) return '';
        try {
            const date = new Date(dateStr);
            return date.toISOString().split('T')[0];
        } catch {
            return '';
        }
    };

    const { data, setData, patch, processing, reset, isDirty } = useForm({
        title: item?.title || '',
        description: item?.description || '',
        item_type_id: item?.item_type_id?.toString() || '',
        priority_id: item?.priority_id?.toString() || '',
        assigned_to: item?.assigned_to?.toString() || '',
        column_id: item?.column_id?.toString() || '',
        story_points: item?.story_points?.toString() || '',
        start_date: formatDateForInput(item?.start_date as string | undefined),
        due_date: formatDateForInput(item?.due_date as string | undefined),
    });

    // Update form when item changes
    useEffect(() => {
        if (item) {
            setData({
                title: item.title || '',
                description: item.description || '',
                item_type_id: item.item_type_id?.toString() || '',
                priority_id: item.priority_id?.toString() || '',
                assigned_to: item.assigned_to?.toString() || '',
                column_id: item.column_id?.toString() || '',
                story_points: item.story_points?.toString() || '',
                start_date: formatDateForInput(item.start_date as string | undefined),
                due_date: formatDateForInput(item.due_date as string | undefined),
            });
            setSelectedLabels(item.labels?.map(l => l.id) || []);
            setEditorContent(parseDescription(item.description));
        }
    }, [item]);

    const handleEditorChange = useCallback((content: string) => {
        setEditorContent(content);
        setData('description', content);
    }, [setData]);

    const handleSave = () => {
        if (!item) return;
        patch(route('boards.items.update', [workspace.slug, project.slug, board.slug, item.id]), {
            onSuccess: () => onClose(),
        });
    };

    const handleMoveToColumn = (columnId: string) => {
        if (!item) return;
        router.patch(route('boards.items.move', [workspace.slug, project.slug, board.slug, item.id]), {
            column_id: parseInt(columnId),
            position: 0,
        });
        setData('column_id', columnId);
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
            color: '#' + Math.floor(Math.random()*16777215).toString(16).padStart(6, '0'),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setNewLabelName('');
            },
        });
    };

    // Helper function to add reply to nested comment structure
    const addReplyToComments = (comments: Comment[], parentId: number, newReply: Comment): Comment[] => {
        return comments.map(c => {
            if (c.id === parentId) {
                return { ...c, replies: [...(c.replies || []), newReply] };
            }
            if (c.replies && c.replies.length > 0) {
                return { ...c, replies: addReplyToComments(c.replies, parentId, newReply) };
            }
            return c;
        });
    };

    const handlePostComment = (parentId?: number) => {
        const body = replyingTo ? replyText.trim() : comment.trim();
        if (!body || !item) return;

        // Create optimistic comment for immediate display
        const optimisticComment: Comment = {
            id: Date.now(), // Temporary ID
            item_id: item.id,
            user_id: 0,
            parent_id: parentId || undefined,
            body: body,
            user: { id: 0, name: 'You', email: '' },
            replies: [],
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
        };

        // Add to local state immediately
        if (parentId) {
            // Add as reply (works for nested replies too)
            setLocalComments(prev => addReplyToComments(prev, parentId, optimisticComment));
            setReplyText('');
            setReplyingTo(null);
        } else {
            // Add as top-level comment
            setLocalComments(prev => [optimisticComment, ...prev]);
            setComment('');
        }

        router.post(route('boards.items.comments.store', [workspace.slug, project.slug, board.slug, item.id]), {
            body: body,
            parent_id: parentId || null,
        }, {
            preserveScroll: true,
        });
    };

    const handleReply = (commentId: number, userName: string, parentId?: number) => {
        setReplyingTo({ id: commentId, parentId, userName });
        setReplyText('');
    };

    const cancelReply = () => {
        setReplyingTo(null);
        setReplyText('');
    };

    const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = e.target.files;
        if (!files || files.length === 0 || !item) return;

        setIsUploading(true);
        const formData = new FormData();
        formData.append('file', files[0]);

        router.post(route('boards.items.attachments.store', [workspace.slug, project.slug, board.slug, item.id]), formData, {
            preserveScroll: true,
            onFinish: () => {
                setIsUploading(false);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    const handleDeleteAttachment = (attachmentId: number) => {
        if (!item) return;

        // Optimistic removal
        setLocalAttachments(prev => prev.filter(a => a.id !== attachmentId));

        router.delete(route('boards.items.attachments.destroy', [workspace.slug, project.slug, board.slug, item.id, attachmentId]), {
            preserveScroll: true,
        });
    };

    const getFileIcon = (mimeType: string) => {
        if (mimeType.startsWith('image/')) return <FileImage className="size-4" />;
        if (mimeType.includes('pdf') || mimeType.includes('document')) return <FileText className="size-4" />;
        return <File className="size-4" />;
    };

    const filteredLabels = projectLabels.filter(label =>
        label.name.toLowerCase().includes(labelSearch.toLowerCase())
    );

    if (!item) return null;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-6xl max-h-[90vh] p-0 gap-0 overflow-hidden [&>button]:hidden" aria-describedby={undefined}>
                <DialogTitle className="sr-only">
                    Edit {item.key}: {item.title}
                </DialogTitle>
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b bg-muted/30">
                    <div className="flex items-center gap-3">
                        <ItemTypeIcon slug={item.item_type?.slug} className="size-5" />
                        <span className="font-mono text-sm font-medium text-primary">{item.key}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('boards.items.show', [workspace.slug, project.slug, board.slug, item.id])}
                            className="inline-flex"
                        >
                            <Button variant="ghost" size="sm" className="gap-2 text-muted-foreground hover:text-foreground">
                                <Maximize2 className="size-4" />
                                <span className="hidden sm:inline">Open full page</span>
                            </Button>
                        </Link>
                        <Button variant="ghost" size="icon" className="size-8" onClick={onClose}>
                            <X className="size-4" />
                        </Button>
                    </div>
                </div>

                {/* Content */}
                <div className="flex max-h-[calc(90vh-140px)]">
                    {/* Main Content */}
                    <div className="flex-1 overflow-y-auto p-6 space-y-6">
                        {/* Title */}
                        <div>
                            <Input
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                className="text-xl font-semibold border-0 p-0 h-auto focus-visible:ring-0"
                                placeholder="Issue title"
                            />
                        </div>

                        {/* Description with Lexical */}
                        <div className="space-y-2">
                            <Label className="text-sm font-medium text-muted-foreground">Description</Label>
                            <RichLexicalEditor
                                key={item?.id}
                                content={editorContent}
                                onChange={handleEditorChange}
                                placeholder="Start typing, or press / for commands..."
                                editable={true}
                                minHeight="150px"
                                contentKey={item?.id?.toString()}
                            />
                        </div>

                        {/* Attachments Section */}
                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Paperclip className="size-4 text-muted-foreground" />
                                    <h3 className="font-medium">Attachments {localAttachments.length > 0 && `(${localAttachments.length})`}</h3>
                                </div>
                                <div>
                                    <input
                                        type="file"
                                        ref={fileInputRef}
                                        onChange={handleFileUpload}
                                        className="hidden"
                                        id="attachment-upload"
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => fileInputRef.current?.click()}
                                        disabled={isUploading}
                                    >
                                        <Upload className="size-4 mr-2" />
                                        {isUploading ? 'Uploading...' : 'Add Attachment'}
                                    </Button>
                                </div>
                            </div>

                            {localAttachments.length > 0 && (
                                <div className="space-y-2">
                                    {localAttachments.map((attachment) => (
                                        <div
                                            key={attachment.id}
                                            className="flex items-center gap-3 p-3 rounded-lg border bg-muted/30 group hover:bg-muted/50 transition-colors"
                                        >
                                            <div className="p-2 rounded bg-background border">
                                                {getFileIcon(attachment.mime_type)}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-medium truncate">{attachment.original_filename}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {attachment.formatted_size || `${Math.round(attachment.size / 1024)} KB`} •
                                                    Uploaded by {attachment.user?.name || 'Unknown'}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <a
                                                    href={attachment.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    download
                                                >
                                                    <Button variant="ghost" size="icon" className="size-8">
                                                        <Download className="size-4" />
                                                    </Button>
                                                </a>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 text-destructive hover:text-destructive"
                                                    onClick={() => handleDeleteAttachment(attachment.id)}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {localAttachments.length === 0 && (
                                <div className="text-center py-6 border border-dashed rounded-lg bg-muted/20">
                                    <Paperclip className="size-8 mx-auto text-muted-foreground/50 mb-2" />
                                    <p className="text-sm text-muted-foreground">No attachments yet</p>
                                    <p className="text-xs text-muted-foreground mt-1">Click the button above to add files</p>
                                </div>
                            )}
                        </div>

                        {/* Comments Section */}
                        <div className="space-y-4">
                            <div className="flex items-center gap-2">
                                <MessageSquare className="size-4 text-muted-foreground" />
                                <h3 className="font-medium">Comments {localComments.length > 0 && `(${localComments.length})`}</h3>
                            </div>

                            {/* Existing comments list - shown FIRST (on top) */}
                            {localComments.length > 0 && (
                                <div className="space-y-4">
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
                                        />
                                    ))}
                                </div>
                            )}

                            {/* New comment input - at BOTTOM */}
                            <div className="flex gap-3 pt-4 border-t">
                                <Avatar className="size-8 shrink-0">
                                    <AvatarFallback className="text-xs bg-primary text-primary-foreground">
                                        U
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex-1">
                                    <Input
                                        value={comment}
                                        onChange={(e) => setComment(e.target.value)}
                                        placeholder="Write a comment..."
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' && !e.shiftKey && comment.trim()) {
                                                e.preventDefault();
                                                handlePostComment();
                                            }
                                        }}
                                    />
                                    <div className="flex justify-end mt-2">
                                        <Button size="sm" onClick={() => handlePostComment()} disabled={!comment.trim()}>
                                            Comment
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Sidebar */}
                    <div className="w-64 border-l bg-muted/20 p-4 overflow-y-auto space-y-4">
                        {/* Status */}
                        <div className="space-y-2">
                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                Status
                            </Label>
                            <Select value={data.column_id} onValueChange={handleMoveToColumn}>
                                <SelectTrigger className="h-9">
                                    <SelectValue placeholder="Select status" />
                                </SelectTrigger>
                                <SelectContent>
                                    {columns.map((col) => (
                                        <SelectItem key={col.id} value={col.id.toString()}>
                                            <div className="flex items-center gap-2">
                                                <div
                                                    className="w-2 h-2 rounded-full"
                                                    style={{ backgroundColor: col.color || '#94a3b8' }}
                                                />
                                                {col.name}
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Assignee */}
                        <div className="space-y-2">
                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                Assignee
                            </Label>
                            <Select value={data.assigned_to || 'unassigned'} onValueChange={(v) => setData('assigned_to', v === 'unassigned' ? '' : v)}>
                                <SelectTrigger className="h-9">
                                    <SelectValue placeholder="Unassigned" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="unassigned">Unassigned</SelectItem>
                                    {projectUsers.map((user) => (
                                        <SelectItem key={user.id} value={user.id.toString()}>
                                            <div className="flex items-center gap-2">
                                                <Avatar className="size-5">
                                                    <AvatarFallback className="text-[10px]">
                                                        {user.name.charAt(0).toUpperCase()}
                                                    </AvatarFallback>
                                                </Avatar>
                                                {user.name}
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Type */}
                        <div className="space-y-2">
                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                Type
                            </Label>
                            <Select value={data.item_type_id} onValueChange={(v) => setData('item_type_id', v)}>
                                <SelectTrigger className="h-9">
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {itemTypes.map((type) => (
                                        <SelectItem key={type.id} value={type.id.toString()}>
                                            <div className="flex items-center gap-2">
                                                <ItemTypeIcon slug={type.slug} />
                                                {type.name}
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Priority */}
                        <div className="space-y-2">
                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                Priority
                            </Label>
                            <Select value={data.priority_id} onValueChange={(v) => setData('priority_id', v)}>
                                <SelectTrigger className="h-9">
                                    <SelectValue placeholder="Select priority" />
                                </SelectTrigger>
                                <SelectContent>
                                    {priorities.map((priority) => (
                                        <SelectItem key={priority.id} value={priority.id.toString()}>
                                            <div className="flex items-center gap-2">
                                                <PriorityIcon slug={priority.slug} />
                                                {priority.name}
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Labels */}
                        <div className="space-y-2">
                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                Labels
                            </Label>
                            <Popover open={labelPopoverOpen} onOpenChange={setLabelPopoverOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={labelPopoverOpen}
                                        className="w-full justify-between h-auto min-h-9 py-1.5"
                                    >
                                        <div className="flex flex-wrap gap-1">
                                            {selectedLabels.length === 0 ? (
                                                <span className="text-muted-foreground">Select labels...</span>
                                            ) : (
                                                selectedLabels.map((labelId) => {
                                                    const label = projectLabels.find(l => l.id === labelId);
                                                    return label ? (
                                                        <Badge
                                                            key={label.id}
                                                            variant="default"
                                                            className="text-xs"
                                                            style={{
                                                                backgroundColor: label.color,
                                                                color: '#fff',
                                                            }}
                                                        >
                                                            {label.name}
                                                        </Badge>
                                                    ) : null;
                                                })
                                            )}
                                        </div>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-[250px] p-0" align="start">
                                    <div className="p-2 border-b">
                                        <Input
                                            placeholder="Search labels..."
                                            value={labelSearch}
                                            onChange={(e) => setLabelSearch(e.target.value)}
                                            className="h-8"
                                        />
                                    </div>
                                    <div className="max-h-[200px] overflow-y-auto p-1">
                                        {filteredLabels.length === 0 && !labelSearch.trim() ? (
                                            <p className="text-xs text-muted-foreground p-2">No labels available</p>
                                        ) : (
                                            <>
                                                {filteredLabels.map((label) => (
                                                    <div
                                                        key={label.id}
                                                        className="flex items-center gap-2 px-2 py-1.5 cursor-pointer hover:bg-muted rounded-sm"
                                                        onClick={() => toggleLabel(label.id)}
                                                    >
                                                        <div
                                                            className="w-3 h-3 rounded-full"
                                                            style={{ backgroundColor: label.color }}
                                                        />
                                                        <span className="flex-1 text-sm">{label.name}</span>
                                                        {selectedLabels.includes(label.id) && (
                                                            <Check className="h-4 w-4 text-primary" />
                                                        )}
                                                    </div>
                                                ))}
                                                {labelSearch.trim() && filteredLabels.length === 0 && (
                                                    <p className="text-xs text-muted-foreground p-2">No matching labels</p>
                                                )}
                                            </>
                                        )}
                                    </div>
                                    <div className="p-2 border-t">
                                        <div className="flex gap-2">
                                            <Input
                                                placeholder="New label name..."
                                                value={newLabelName}
                                                onChange={(e) => setNewLabelName(e.target.value)}
                                                className="h-8 flex-1"
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter' && newLabelName.trim()) {
                                                        e.preventDefault();
                                                        handleCreateLabel();
                                                    }
                                                }}
                                            />
                                            <Button
                                                size="sm"
                                                onClick={handleCreateLabel}
                                                disabled={!newLabelName.trim()}
                                                className="h-8"
                                            >
                                                <Plus className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>
                                </PopoverContent>
                            </Popover>
                        </div>

                        {/* Story Points */}
                        <div className="space-y-2">
                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                Story Points
                            </Label>
                            <Input
                                type="number"
                                min="0"
                                value={data.story_points}
                                onChange={(e) => setData('story_points', e.target.value)}
                                className="h-9"
                                placeholder="0"
                            />
                        </div>

                        {/* Start Date */}
                        <div className="space-y-2">
                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                Start Date
                            </Label>
                            <Input
                                type="date"
                                value={data.start_date}
                                onChange={(e) => setData('start_date', e.target.value)}
                                className="h-9"
                            />
                        </div>

                        {/* Due Date */}
                        <div className="space-y-2">
                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                Due Date
                            </Label>
                            <Input
                                type="date"
                                value={data.due_date}
                                onChange={(e) => setData('due_date', e.target.value)}
                                className="h-9"
                            />
                        </div>

                        <Separator className="my-4" />

                        {/* Created info */}
                        <div className="space-y-2 text-xs text-muted-foreground">
                            <p>Created {new Date(item.created_at).toLocaleDateString()}</p>
                            <p>Updated {new Date(item.updated_at).toLocaleDateString()}</p>
                        </div>
                    </div>
                </div>

                {/* Footer */}
                <div className="flex items-center justify-end gap-3 px-6 py-4 border-t bg-muted/30">
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={handleSave} disabled={processing || !isDirty}>
                        {processing ? 'Saving...' : 'Save Changes'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
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
                                <td colSpan={7} className="py-12 text-center text-muted-foreground">
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

    return (
        <AppLayout>
            <Head title={`${board.name} - ${project.name}`} />

            <div className="flex flex-col h-full overflow-hidden">
                {/* Board Header */}
                <div className="flex items-center justify-between px-6 py-3 border-b bg-background/95 backdrop-blur">
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-3">
                            <div
                                className="size-9 rounded-lg flex items-center justify-center text-white font-bold text-sm shadow-sm"
                                style={{ backgroundColor: project.color || '#6366f1' }}
                            >
                                {project.key}
                            </div>
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
                    <div className="flex-1 overflow-x-auto overflow-y-hidden bg-slate-100 dark:bg-slate-900/50">
                        <div className="flex gap-4 p-6 h-full">
                            {filteredColumns.map((column) => (
                                <BoardColumnComponent
                                    key={column.id}
                                    column={column}
                                    workspace={workspace}
                                    project={project}
                                    board={board}
                                    itemTypes={itemTypes}
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
