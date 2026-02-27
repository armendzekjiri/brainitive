import AppLayout from '@/Layouts/AppLayout';
import { Head, router, Link } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, BoardColumn, Item, ItemType, ItemPriority, User, Label as LabelType, ItemLink, Comment, Attachment } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Separator } from '@/components/ui/separator';
import { Badge } from '@/components/ui/badge';
import { ProjectIcon } from '@/components/ProjectIcon';
import { cn } from '@/lib/utils';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
    User as UserIcon,
    Calendar,
    MessageSquare,
    Clock,
    ChevronUp,
    ChevronDown,
    Equal,
    Bug,
    Bookmark,
    Layers,
    Square,
    CheckSquare,
    ArrowLeft,
    Pencil,
    Check,
    Link2,
    Plus,
    X,
    MoveRight,
    Paperclip,
    Upload,
    File,
    FileImage,
    FileText,
    FileSpreadsheet,
    FileArchive,
    FileCode,
    FileType,
    Trash2,
    Download,
    Eye,
    Reply,
    Bell,
    BellRing,
} from 'lucide-react';
import { useState, useEffect, useCallback, useRef } from 'react';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { usePage } from '@inertiajs/react';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    board: Board;
    item: Item;
    itemTypes: ItemType[];
    priorities: ItemPriority[];
    projectUsers: User[];
    columns: BoardColumn[];
    projectLabels: LabelType[];
    projectItems: Array<{ id: number; key: string; title: string; board?: { id: number; name: string; slug: string } }>;
    projectBoards: Array<{ id: number; name: string; slug: string; columns: BoardColumn[] }>;
}

// ── File-type helpers (shared with Bookmarks) ────────────────────────────────
type PreviewType = 'pdf' | 'image' | 'video' | 'other';

function getFileIcon(filename: string): { Icon: React.ComponentType<{ className?: string }>; colorClass: string } {
    const ext = filename?.split('.').pop()?.toLowerCase() ?? '';
    const map: Record<string, { Icon: React.ComponentType<{ className?: string }>; colorClass: string }> = {
        pdf: { Icon: FileText, colorClass: 'text-red-600' },
        doc: { Icon: FileText, colorClass: 'text-blue-600' },
        docx: { Icon: FileText, colorClass: 'text-blue-600' },
        rtf: { Icon: FileText, colorClass: 'text-blue-500' },
        txt: { Icon: FileType, colorClass: 'text-muted-foreground' },
        md: { Icon: FileType, colorClass: 'text-muted-foreground' },
        xls: { Icon: FileSpreadsheet, colorClass: 'text-green-600' },
        xlsx: { Icon: FileSpreadsheet, colorClass: 'text-green-600' },
        csv: { Icon: FileSpreadsheet, colorClass: 'text-green-500' },
        ppt: { Icon: FileText, colorClass: 'text-orange-600' },
        pptx: { Icon: FileText, colorClass: 'text-orange-600' },
        jpg: { Icon: FileImage, colorClass: 'text-purple-500' },
        jpeg: { Icon: FileImage, colorClass: 'text-purple-500' },
        png: { Icon: FileImage, colorClass: 'text-sky-500' },
        gif: { Icon: FileImage, colorClass: 'text-pink-500' },
        webp: { Icon: FileImage, colorClass: 'text-teal-500' },
        svg: { Icon: FileImage, colorClass: 'text-amber-500' },
        bmp: { Icon: FileImage, colorClass: 'text-muted-foreground' },
        ico: { Icon: FileImage, colorClass: 'text-amber-500' },
        heic: { Icon: FileImage, colorClass: 'text-muted-foreground' },
        avif: { Icon: FileImage, colorClass: 'text-muted-foreground' },
        zip: { Icon: FileArchive, colorClass: 'text-amber-600' },
        rar: { Icon: FileArchive, colorClass: 'text-amber-600' },
        '7z': { Icon: FileArchive, colorClass: 'text-amber-600' },
        json: { Icon: FileCode, colorClass: 'text-amber-500' },
        xml: { Icon: FileCode, colorClass: 'text-orange-500' },
    };
    return map[ext] ?? { Icon: File, colorClass: 'text-muted-foreground' };
}

function getFilePreviewType(filename: string): PreviewType {
    const ext = filename?.split('.').pop()?.toLowerCase() ?? '';
    if (['pdf'].includes(ext)) return 'pdf';
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'heic', 'avif'].includes(ext)) return 'image';
    if (['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'].includes(ext)) return 'video';
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

// ─── CommentItem (with recursive replies) ─────────────────────────────────────

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

export default function ItemShow({
    workspace,
    project,
    board,
    item,
    itemTypes,
    priorities,
    projectUsers,
    columns,
    projectLabels,
    projectItems,
    projectBoards,
}: Props) {
    const [selectedLabels, setSelectedLabels] = useState<number[]>(
        item.labels?.map(l => l.id) || []
    );

    // ── Comments & Attachments ────────────────────────────────────────────────
    const [localComments, setLocalComments] = useState<Comment[]>(item.comments || []);
    const [localAttachments, setLocalAttachments] = useState<Attachment[]>(item.attachments || []);
    const [comment, setComment] = useState('');
    const [commentEditorKey, setCommentEditorKey] = useState(0);
    const [commentActive, setCommentActive] = useState(false);
    const [labelsOpen, setLabelsOpen] = useState(false);
    const [labelSearch, setLabelSearch] = useState('');
    const [newLabelName, setNewLabelName] = useState('');
    const [replyEditorKey, setReplyEditorKey] = useState(0);
    const [replyingTo, setReplyingTo] = useState<{ id: number; parentId?: number; userName: string } | null>(null);
    const [replyText, setReplyText] = useState('');
    const [isUploading, setIsUploading] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (item.comments) setLocalComments(item.comments);
        if (item.attachments) setLocalAttachments(item.attachments);
    }, [item.comments, item.attachments]);
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

    // ── Per-field state ────────────────────────────────────────────────────
    const [editorContent, setEditorContent] = useState<string>(() => parseDescription(item.description));
    const [savedDescription, setSavedDescription] = useState<string>(() => parseDescription(item.description));
    const [editorKey, setEditorKey] = useState(0);
    const [isEditingDescription, setIsEditingDescription] = useState(false);

    // Title inline edit
    const [titleValue, setTitleValue] = useState(item.title || '');
    const [isEditingTitle, setIsEditingTitle] = useState(false);
    const [titleDraft, setTitleDraft] = useState(item.title || '');
    const titleInputRef = useRef<HTMLInputElement>(null);

    // Sidebar property state
    const [columnId, setColumnId] = useState(item.column_id?.toString() || '');
    const [assignedTo, setAssignedTo] = useState(item.assigned_to?.toString() || '');
    const [itemTypeId, setItemTypeId] = useState(item.item_type_id?.toString() || '');
    const [priorityId, setPriorityId] = useState(item.priority_id?.toString() || '');
    const [storyPoints, setStoryPoints] = useState(item.story_points?.toString() || '');
    const [startDate, setStartDate] = useState(item.start_date || '');
    const [dueDate, setDueDate] = useState(item.due_date || '');

    // Generic patch helper
    const saveProp = useCallback((fields: Record<string, any>) => {
        router.patch(
            route('boards.items.update', [workspace.slug, project.slug, board.slug, item.id]),
            fields,
            { preserveScroll: true },
        );
    }, [workspace.slug, project.slug, board.slug, item.id]);

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

    // Column / move handlers
    const handleMoveToColumn = (newColumnId: string) => {
        setColumnId(newColumnId);
        router.patch(route('boards.items.move', [workspace.slug, project.slug, board.slug, item.id]), {
            column_id: parseInt(newColumnId),
            position: 0,
        }, { preserveScroll: true });
    };

    const toggleLabel = (labelId: number) => {
        const newLabels = selectedLabels.includes(labelId)
            ? selectedLabels.filter(id => id !== labelId)
            : [...selectedLabels, labelId];
        setSelectedLabels(newLabels);
        router.patch(route('boards.items.labels', [workspace.slug, project.slug, board.slug, item.id]), {
            labels: newLabels,
        }, { preserveScroll: true });
    };

    // Link management
    const handleCreateLabel = () => {
        if (!newLabelName.trim()) return;
        router.post(route('projects.labels.store', [workspace.slug, project.slug]), {
            name: newLabelName.trim(),
            color: '#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0'),
        }, {
            preserveScroll: true,
            onSuccess: () => { setNewLabelName(''); },
        });
    };

    const [linkDialogOpen, setLinkDialogOpen] = useState(false); const [linkType, setLinkType] = useState<string>('relates_to');
    const [targetItemId, setTargetItemId] = useState<string>('');

    const linkTypeOptions = [
        { value: 'blocks', label: 'blocks' },
        { value: 'is_blocked_by', label: 'is blocked by' },
        { value: 'duplicates', label: 'duplicates' },
        { value: 'is_duplicated_by', label: 'is duplicated by' },
        { value: 'relates_to', label: 'relates to' },
        { value: 'clones', label: 'clones' },
        { value: 'is_cloned_by', label: 'is cloned by' },
    ];

    // Watchers management
    const currentUser = usePage<PageProps>().props.auth.user;
    const isWatching = item.watchers?.some(w => w.id === currentUser.id) ?? false;

    const toggleWatch = () => {
        router.post(route('boards.items.watch', [workspace.slug, project.slug, board.slug, item.id]), {
            user_id: currentUser.id,
            watch: !isWatching,
        }, { preserveScroll: true });
    };

    const handleCreateLink = () => {
        if (!targetItemId) return;
        router.post(route('boards.items.links.store', [workspace.slug, project.slug, board.slug, item.id]), {
            target_item_id: parseInt(targetItemId),
            link_type: linkType,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setLinkDialogOpen(false);
                setTargetItemId('');
            },
        });
    };

    const handleDeleteLink = (linkId: number) => {
        router.delete(route('boards.items.links.destroy', [workspace.slug, project.slug, board.slug, item.id, linkId]), {
            preserveScroll: true,
        });
    };

    // Move to another board
    const [moveDialogOpen, setMoveDialogOpen] = useState(false);
    const [targetBoardId, setTargetBoardId] = useState<string>('');
    const [targetColumnId, setTargetColumnId] = useState<string>('');

    const handleMoveToBoard = () => {
        if (!targetBoardId) return;
        router.patch(route('boards.items.move-to-board', [workspace.slug, project.slug, board.slug, item.id]), {
            target_board_id: parseInt(targetBoardId),
            target_column_id: targetColumnId ? parseInt(targetColumnId) : null,
        }, {
            onSuccess: () => {
                setMoveDialogOpen(false);
            },
        });
    };

    const targetBoardColumns = projectBoards.find(b => b.id.toString() === targetBoardId)?.columns || [];

    // ── Comment handlers ──────────────────────────────────────────────────────
    const addReplyToComments = (comments: Comment[], parentId: number, newReply: Comment): Comment[] =>
        comments.map(c => {
            if (c.id === parentId) return { ...c, replies: [...(c.replies || []), newReply] };
            if (c.replies?.length) return { ...c, replies: addReplyToComments(c.replies, parentId, newReply) };
            return c;
        });

    const handlePostComment = (parentId?: number) => {
        const rawBody = replyingTo ? replyText : comment;
        const textBody = rawBody.replace(/<[^>]*>/g, '').trim();
        if (!textBody) return;

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

    // ── Attachment handlers ───────────────────────────────────────────────────
    const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = e.target.files;
        if (!files || files.length === 0) return;
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
        setLocalAttachments(prev => prev.filter(a => a.id !== attachmentId));
        router.delete(route('boards.items.attachments.destroy', [workspace.slug, project.slug, board.slug, item.id, attachmentId]), {
            preserveScroll: true,
        });
    };

    // Toggle a checkbox in view mode without opening the editor
    const handleCheckboxToggle = useCallback((e: React.MouseEvent<HTMLDivElement>) => {
        const target = e.target as HTMLElement;
        const li = target.closest('li[role="checkbox"]') as HTMLElement | null;
        if (!li) return;
        // Stop so we don't trigger startEditDescription via double-click race
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

    const [filePreview, setFilePreview] = useState<{ url: string; title: string; previewType: PreviewType } | null>(null);

    return (
        <AppLayout>
            <Head title={`${item.key} - ${item.title}`} />

            <div className="h-full flex flex-col bg-background">
                {/* Header – fixed height, never scrolls */}
                <div className="border-b bg-background shadow-sm shrink-0">
                    <div className="px-6 py-3 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <Link href={route('boards.show', [workspace.slug, project.slug, board.slug])}>
                                <Button variant="ghost" size="icon" className="size-8">
                                    <ArrowLeft className="size-4" />
                                </Button>
                            </Link>
                            <Breadcrumb>
                                <BreadcrumbList>
                                    <BreadcrumbItem>
                                        <BreadcrumbLink asChild>
                                            <Link href={route('projects.show', [workspace.slug, project.slug])} className="flex items-center gap-2">
                                                <ProjectIcon project={project} size="xs" />
                                                {project.name}
                                            </Link>
                                        </BreadcrumbLink>
                                    </BreadcrumbItem>
                                    <BreadcrumbSeparator />
                                    <BreadcrumbItem>
                                        <BreadcrumbLink asChild>
                                            <Link href={route('boards.show', [workspace.slug, project.slug, board.slug])}>
                                                {board.name}
                                            </Link>
                                        </BreadcrumbLink>
                                    </BreadcrumbItem>
                                    <BreadcrumbSeparator />
                                    <BreadcrumbItem>
                                        <BreadcrumbPage className="flex items-center gap-2">
                                            <ItemTypeIcon slug={item.item_type?.slug} />
                                            {item.key}
                                        </BreadcrumbPage>
                                    </BreadcrumbItem>
                                </BreadcrumbList>
                            </Breadcrumb>
                        </div>

                    </div>
                </div>

                {/* Two-panel body – each column scrolls independently */}
                <div className="flex-1 overflow-hidden flex">

                    {/* ── Main column (scrollable) ─────────────────────── */}
                    <div className="flex-1 overflow-y-auto min-w-0">
                        <div className="px-10 py-8 space-y-0">

                            {/* ─── Title ─────────────────────────────────── */}
                            <div className="mb-0">
                                <div className="flex items-center gap-2 mb-2">
                                    <ItemTypeIcon slug={item.item_type?.slug} className="size-4" />
                                    <span className="font-mono text-xs font-semibold text-primary tracking-wider">{item.key}</span>
                                    {item.column && (
                                        <Badge variant="secondary" className="text-xs">
                                            <span className="w-1.5 h-1.5 rounded-full mr-1.5 inline-block" style={{ backgroundColor: (item.column as any).color || '#94a3b8' }} />
                                            {(item.column as any).name}
                                        </Badge>
                                    )}
                                </div>

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
                                            className="text-2xl font-semibold border-0 border-b-2 border-primary rounded-none px-0 py-1 h-auto focus-visible:ring-0 bg-transparent flex-1 leading-snug"
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
                                        <h1 className="text-2xl font-semibold text-foreground leading-snug flex-1">
                                            {titleValue || <span className="text-muted-foreground font-normal">Issue title</span>}
                                        </h1>
                                        <Pencil className="size-3.5 text-muted-foreground/0 group-hover:text-muted-foreground/60 transition-colors shrink-0" />
                                    </div>
                                )}
                            </div>

                            {/* ─── Description ───────────────────────────── */}
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
                                            maxHeight="520px"
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
                                                onChange={() => { }}
                                                editable={false}
                                                showToolbar={false}
                                                minHeight="40px"
                                                maxHeight="520px"
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

                            {/* ─── Linked Issues ──────────────────────────── */}
                            <section className="py-5 border-t border-border/60">
                                <div className="flex items-center justify-between mb-3">
                                    <h2 className="text-sm font-semibold flex items-center gap-2">
                                        <Link2 className="size-4 text-muted-foreground" />
                                        Linked Issues
                                    </h2>
                                    <Dialog open={linkDialogOpen} onOpenChange={setLinkDialogOpen}>
                                        <DialogTrigger asChild>
                                            <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground hover:text-foreground gap-1.5">
                                                <Plus className="size-3.5" />
                                                Link
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>Link Issue</DialogTitle>
                                                <DialogDescription>Create a link between this issue and another.</DialogDescription>
                                            </DialogHeader>
                                            <div className="space-y-4 pt-4">
                                                <div className="space-y-2">
                                                    <Label>Link Type</Label>
                                                    <Select value={linkType} onValueChange={setLinkType}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            {linkTypeOptions.map((opt) => (
                                                                <SelectItem key={opt.value} value={opt.value}>This issue {opt.label}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Target Issue</Label>
                                                    <Select value={targetItemId} onValueChange={setTargetItemId}>
                                                        <SelectTrigger><SelectValue placeholder="Select an issue…" /></SelectTrigger>
                                                        <SelectContent>
                                                            {projectItems.map((pi) => (
                                                                <SelectItem key={pi.id} value={pi.id.toString()}>
                                                                    <span className="font-mono text-xs text-primary mr-2">{pi.key}</span>
                                                                    <span className="truncate">{pi.title}</span>
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="flex justify-end gap-2">
                                                    <Button variant="outline" onClick={() => setLinkDialogOpen(false)}>Cancel</Button>
                                                    <Button onClick={handleCreateLink} disabled={!targetItemId}>Link Issue</Button>
                                                </div>
                                            </div>
                                        </DialogContent>
                                    </Dialog>
                                </div>

                                {(item.outgoing_links?.length || 0) + (item.incoming_links?.length || 0) === 0 ? (
                                    <p className="text-sm text-muted-foreground">No linked issues yet</p>
                                ) : (
                                    <div className="space-y-1">
                                        {item.outgoing_links?.map((link) => (
                                            <div key={`out-${link.id}`} className="flex items-center justify-between py-1.5 px-2 rounded-md -mx-2 group hover:bg-muted/40 transition-colors">
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <span className="text-sm text-muted-foreground shrink-0">
                                                        {linkTypeOptions.find(o => o.value === link.link_type)?.label || link.link_type}
                                                    </span>
                                                    <Link href={route('boards.items.show', [workspace.slug, project.slug, link.target_item?.board?.slug, link.target_item_id])} className="text-sm text-primary hover:underline truncate">
                                                        <span className="font-mono text-sm mr-1">{link.target_item?.key}</span>{link.target_item?.title}
                                                    </Link>
                                                </div>
                                                <Button variant="ghost" size="icon" className="size-6 opacity-0 group-hover:opacity-100 shrink-0" onClick={() => handleDeleteLink(link.id)}>
                                                    <X className="size-3" />
                                                </Button>
                                            </div>
                                        ))}
                                        {item.incoming_links?.map((link) => (
                                            <div key={`in-${link.id}`} className="flex items-center justify-between py-1.5 px-2 rounded-md -mx-2 group hover:bg-muted/40 transition-colors">
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <span className="text-sm text-muted-foreground shrink-0">
                                                        {linkTypeOptions.find(o => o.value === (
                                                            link.link_type === 'blocks' ? 'is_blocked_by' :
                                                                link.link_type === 'is_blocked_by' ? 'blocks' :
                                                                    link.link_type === 'duplicates' ? 'is_duplicated_by' :
                                                                        link.link_type === 'is_duplicated_by' ? 'duplicates' :
                                                                            link.link_type === 'clones' ? 'is_cloned_by' :
                                                                                link.link_type === 'is_cloned_by' ? 'clones' : link.link_type
                                                        ))?.label || link.link_type}
                                                    </span>
                                                    <Link href={route('boards.items.show', [workspace.slug, project.slug, link.source_item?.board?.slug, link.source_item_id])} className="text-sm text-primary hover:underline truncate">
                                                        <span className="font-mono text-sm mr-1">{link.source_item?.key}</span>{link.source_item?.title}
                                                    </Link>
                                                </div>
                                                <Button variant="ghost" size="icon" className="size-6 opacity-0 group-hover:opacity-100 shrink-0" onClick={() => handleDeleteLink(link.id)}>
                                                    <X className="size-3" />
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>

                            {/* ─── Attachments ───────────────────────────── */}
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
                                            const { Icon, colorClass } = getFileIcon(attachment.original_filename || attachment.filename || '');
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
                                    <button
                                        onClick={() => fileInputRef.current?.click()}
                                        className="w-full text-left text-sm text-muted-foreground hover:text-foreground transition-colors py-1"
                                    >
                                        + Add attachment
                                    </button>
                                )}
                            </section>

                            {/* ─── LINKED ISSUES MOVED ABOVE (duplicate removed) ─── */}
                            {false && <section className="py-5 border-t border-border/60">
                                <div className="flex items-center justify-between mb-3">
                                    <h2 className="text-sm font-semibold flex items-center gap-2">
                                        <Link2 className="size-4 text-muted-foreground" />
                                        Linked Issues
                                    </h2>
                                    <Dialog open={linkDialogOpen} onOpenChange={setLinkDialogOpen}>
                                        <DialogTrigger asChild>
                                            <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground hover:text-foreground gap-1.5">
                                                <Plus className="size-3.5" />
                                                Link
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>Link Issue</DialogTitle>
                                                <DialogDescription>Create a link between this issue and another.</DialogDescription>
                                            </DialogHeader>
                                            <div className="space-y-4 pt-4">
                                                <div className="space-y-2">
                                                    <Label>Link Type</Label>
                                                    <Select value={linkType} onValueChange={setLinkType}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            {linkTypeOptions.map((opt) => (
                                                                <SelectItem key={opt.value} value={opt.value}>This issue {opt.label}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Target Issue</Label>
                                                    <Select value={targetItemId} onValueChange={setTargetItemId}>
                                                        <SelectTrigger><SelectValue placeholder="Select an issue…" /></SelectTrigger>
                                                        <SelectContent>
                                                            {projectItems.map((pi) => (
                                                                <SelectItem key={pi.id} value={pi.id.toString()}>
                                                                    <span className="font-mono text-xs text-primary mr-2">{pi.key}</span>
                                                                    <span className="truncate">{pi.title}</span>
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="flex justify-end gap-2">
                                                    <Button variant="outline" onClick={() => setLinkDialogOpen(false)}>Cancel</Button>
                                                    <Button onClick={handleCreateLink} disabled={!targetItemId}>Link Issue</Button>
                                                </div>
                                            </div>
                                        </DialogContent>
                                    </Dialog>
                                </div>

                                {(item.outgoing_links?.length || 0) + (item.incoming_links?.length || 0) === 0 ? (
                                    <p className="text-sm text-muted-foreground">No linked issues yet</p>
                                ) : (
                                    <div className="space-y-1">
                                        {item.outgoing_links?.map((link) => (
                                            <div key={`out-${link.id}`} className="flex items-center justify-between py-1.5 px-2 rounded-md -mx-2 group hover:bg-muted/40 transition-colors">
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <span className="text-xs text-muted-foreground shrink-0">
                                                        {linkTypeOptions.find(o => o.value === link.link_type)?.label || link.link_type}
                                                    </span>
                                                    <Link href={route('boards.items.show', [workspace.slug, project.slug, link.target_item?.board?.slug, link.target_item_id])} className="text-sm text-primary hover:underline truncate">
                                                        <span className="font-mono text-xs mr-1">{link.target_item?.key}</span>{link.target_item?.title}
                                                    </Link>
                                                </div>
                                                <Button variant="ghost" size="icon" className="size-6 opacity-0 group-hover:opacity-100 shrink-0" onClick={() => handleDeleteLink(link.id)}>
                                                    <X className="size-3" />
                                                </Button>
                                            </div>
                                        ))}
                                        {item.incoming_links?.map((link) => (
                                            <div key={`in-${link.id}`} className="flex items-center justify-between py-1.5 px-2 rounded-md -mx-2 group hover:bg-muted/40 transition-colors">
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <span className="text-xs text-muted-foreground shrink-0">
                                                        {linkTypeOptions.find(o => o.value === (
                                                            link.link_type === 'blocks' ? 'is_blocked_by' :
                                                                link.link_type === 'is_blocked_by' ? 'blocks' :
                                                                    link.link_type === 'duplicates' ? 'is_duplicated_by' :
                                                                        link.link_type === 'is_duplicated_by' ? 'duplicates' :
                                                                            link.link_type === 'clones' ? 'is_cloned_by' :
                                                                                link.link_type === 'is_cloned_by' ? 'clones' : link.link_type
                                                        ))?.label || link.link_type}
                                                    </span>
                                                    <Link href={route('boards.items.show', [workspace.slug, project.slug, link.source_item?.board?.slug, link.source_item_id])} className="text-sm text-primary hover:underline truncate">
                                                        <span className="font-mono text-xs mr-1">{link.source_item?.key}</span>{link.source_item?.title}
                                                    </Link>
                                                </div>
                                                <Button variant="ghost" size="icon" className="size-6 opacity-0 group-hover:opacity-100 shrink-0" onClick={() => handleDeleteLink(link.id)}>
                                                    <X className="size-3" />
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>}

                            {/* ─── Comments ───────────────────────────────── */}
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
                                                maxHeight="300px"
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

                        </div>{/* /inner max-w */}
                    </div>{/* /main column */}

                    {/* ── Sidebar (scrollable, independent) ───────────── */}
                    <div className="w-[360px] shrink-0 overflow-y-auto border-l bg-background">
                        <div className="px-6 py-5">

                            {/* ─── Details section ──────────────────────────── */}
                            <h2 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">Details</h2>

                            <div className="space-y-0 divide-y divide-border/50">
                                {/* Each row: label left 40%, control right 60% */}

                                {/* Status */}
                                <div className="flex items-center min-h-[40px] py-1 group">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground">Status</span>
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
                                <div className="flex items-center min-h-[40px] py-1 group">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground">Assignee</span>
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
                                                            <Avatar className="size-5"><AvatarFallback className="text-[10px]">{user.name.charAt(0).toUpperCase()}</AvatarFallback></Avatar>
                                                            {user.name}
                                                        </div>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                {/* Type */}
                                <div className="flex items-center min-h-[40px] py-1 group">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground">Type</span>
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

                                {/* Priority */}
                                <div className="flex items-center min-h-[40px] py-1 group">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground">Priority</span>
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
                                <div className="flex items-center min-h-[40px] py-1 group">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground">Story Points</span>
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

                                {/* Start Date */}
                                <div className="flex items-center min-h-[40px] py-1 group">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground">Start Date</span>
                                    <div className="flex-1 px-2 -ml-2">
                                        <Input
                                            type="date"
                                            value={startDate}
                                            onChange={(e) => { setStartDate(e.target.value); saveProp({ start_date: e.target.value || null }); }}
                                            className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md"
                                        />
                                    </div>
                                </div>

                                {/* Due Date */}
                                <div className="flex items-center min-h-[40px] py-1 group">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground">Due Date</span>
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
                                        <span className="w-[38%] shrink-0 text-xs text-muted-foreground">Reporter</span>
                                        <div className="flex-1 flex items-center gap-2 px-2">
                                            <Avatar className="size-5 shrink-0"><AvatarFallback className="text-[10px]">{item.reporter.name.charAt(0)}</AvatarFallback></Avatar>
                                            <span className="text-sm truncate">{item.reporter.name}</span>
                                        </div>
                                    </div>
                                )}

                                {/* Created */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground">Created</span>
                                    <span className="flex-1 text-sm px-2">{new Date(item.created_at).toLocaleDateString()}</span>
                                </div>

                                {/* Updated */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground">Updated</span>
                                    <span className="flex-1 text-sm px-2">{new Date(item.updated_at).toLocaleDateString()}</span>
                                </div>

                                {/* Watchers */}
                                <div className="flex items-start min-h-[40px] py-2 border-t mt-2">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground pt-1.5">Watchers</span>
                                    <div className="flex-1 px-2 -ml-2 space-y-2">
                                        <div className="flex items-center justify-between">
                                            <div className="flex -space-x-1.5 overflow-hidden">
                                                {(item.watchers || []).slice(0, 5).map(watcher => (
                                                    <Avatar key={watcher.id} className="size-6 border-2 border-background" title={watcher.name}>
                                                        <AvatarFallback className="text-[10px] bg-muted">{watcher.name.charAt(0)}</AvatarFallback>
                                                    </Avatar>
                                                ))}
                                                {(item.watchers?.length || 0) > 5 && (
                                                    <div className="size-6 rounded-full border-2 border-background bg-muted flex items-center justify-center text-[10px] font-medium text-muted-foreground z-10 shrink-0">
                                                        +{(item.watchers?.length || 0) - 5}
                                                    </div>
                                                )}
                                                {(!item.watchers || item.watchers.length === 0) && (
                                                    <span className="text-sm text-muted-foreground mt-0.5">None</span>
                                                )}
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className={cn("h-7 px-2.5 text-xs gap-1.5 ml-2 cursor-pointer", isWatching && "bg-primary/5 text-primary border-primary/20")}
                                                onClick={toggleWatch}
                                            >
                                                {isWatching ? (
                                                    <>
                                                        <BellRing className="size-3.5" />
                                                        Watching
                                                    </>
                                                ) : (
                                                    <>
                                                        <Bell className="size-3.5" />
                                                        Watch
                                                    </>
                                                )}
                                            </Button>
                                        </div>
                                    </div>
                                </div>

                                {/* ─── Labels row ─────────────────────────── */}
                                <div className="flex items-start min-h-[40px] py-1 group">
                                    <span className="w-[38%] shrink-0 text-xs text-muted-foreground pt-2">Labels</span>
                                    <div className="flex-1">
                                        <Popover open={labelsOpen} onOpenChange={(o) => { setLabelsOpen(o); if (!o) setLabelSearch(''); }}>
                                            <PopoverTrigger asChild>
                                                <button className="w-full text-left rounded-md px-2 py-1 -ml-2 hover:bg-muted/80 transition-colors min-h-[32px] flex flex-wrap gap-1 items-center">
                                                    {selectedLabels.length === 0 ? (
                                                        <span className="text-sm text-muted-foreground">None</span>
                                                    ) : (
                                                        selectedLabels.map((id) => {
                                                            const lbl = projectLabels.find(l => l.id === id);
                                                            if (!lbl) return null;
                                                            return (
                                                                <span
                                                                    key={id}
                                                                    className="inline-flex items-center gap-1 text-[11px] font-medium px-1.5 py-0.5 rounded-full"
                                                                    style={{ backgroundColor: lbl.color + '22', color: lbl.color, border: `1px solid ${lbl.color}55` }}
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
                                                    {projectLabels
                                                        .filter(l => l.name.toLowerCase().includes(labelSearch.toLowerCase()))
                                                        .map((label) => {
                                                            const active = selectedLabels.includes(label.id);
                                                            return (
                                                                <button
                                                                    key={label.id}
                                                                    onClick={() => toggleLabel(label.id)}
                                                                    className="flex items-center gap-2 w-full px-2 py-1.5 rounded-md text-sm hover:bg-muted/60 transition-colors text-left"
                                                                >
                                                                    <span
                                                                        className="size-3 rounded-full shrink-0 border"
                                                                        style={{ backgroundColor: label.color, borderColor: label.color }}
                                                                    />
                                                                    <span className="flex-1 truncate text-xs">{label.name}</span>
                                                                    {active && <Check className="size-3 shrink-0 text-primary" />}
                                                                </button>
                                                            );
                                                        })}
                                                    {projectLabels.filter(l => l.name.toLowerCase().includes(labelSearch.toLowerCase())).length === 0 && (
                                                        <p className="text-xs text-muted-foreground px-2 py-3 text-center">No labels found</p>
                                                    )}
                                                </div>
                                                <div className="p-2 border-t">
                                                    <div className="flex gap-1.5">
                                                        <Input
                                                            placeholder="New label name…"
                                                            value={newLabelName}
                                                            onChange={(e) => setNewLabelName(e.target.value)}
                                                            className="h-7 text-xs flex-1"
                                                            onKeyDown={(e) => {
                                                                if (e.key === 'Enter' && newLabelName.trim()) {
                                                                    e.preventDefault();
                                                                    handleCreateLabel();
                                                                }
                                                            }}
                                                        />
                                                        <Button
                                                            size="sm"
                                                            className="h-7 px-2"
                                                            onClick={handleCreateLabel}
                                                            disabled={!newLabelName.trim()}
                                                        >
                                                            <Plus className="size-3" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            </PopoverContent>
                                        </Popover>
                                    </div>
                                </div>
                            </div>{/* /divide-y */}

                            {/* ─── Move board ───────────────────────────────── */}
                            <div className="mt-5 pt-4 border-t border-border/60">
                                <Dialog open={moveDialogOpen} onOpenChange={setMoveDialogOpen}>
                                    <DialogTrigger asChild>
                                        <Button variant="outline" className="w-full gap-2 h-9 text-sm">
                                            <MoveRight className="size-4" />
                                            Move to Another Board
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Move Issue</DialogTitle>
                                            <DialogDescription>Move this issue to a different board within the project.</DialogDescription>
                                        </DialogHeader>
                                        <div className="space-y-4 pt-4">
                                            <div className="space-y-2">
                                                <Label>Target Board</Label>
                                                <Select value={targetBoardId} onValueChange={(v) => { setTargetBoardId(v); setTargetColumnId(''); }}>
                                                    <SelectTrigger><SelectValue placeholder="Select a board…" /></SelectTrigger>
                                                    <SelectContent>
                                                        {projectBoards.filter(b => b.id !== board.id).map((b) => (
                                                            <SelectItem key={b.id} value={b.id.toString()}>{b.name}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            {targetBoardId && targetBoardColumns.length > 0 && (
                                                <div className="space-y-2">
                                                    <Label>Target Column</Label>
                                                    <Select value={targetColumnId} onValueChange={setTargetColumnId}>
                                                        <SelectTrigger><SelectValue placeholder="First column (default)" /></SelectTrigger>
                                                        <SelectContent>
                                                            {targetBoardColumns.map((col) => (
                                                                <SelectItem key={col.id} value={col.id.toString()}>{col.name}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            )}
                                            <div className="flex justify-end gap-2">
                                                <Button variant="outline" onClick={() => setMoveDialogOpen(false)}>Cancel</Button>
                                                <Button onClick={handleMoveToBoard} disabled={!targetBoardId}>Move Issue</Button>
                                            </div>
                                        </div>
                                    </DialogContent>
                                </Dialog>
                            </div>

                        </div>{/* /sidebar inner */}
                    </div>{/* /sidebar */}
                </div>{/* /two-panel body */}
            </div>{/* /page root */}
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
        </AppLayout>
    );
}
