import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { router, Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
    CheckSquare, Link2, Plus, X, Paperclip, Upload, Eye, Download, Trash2,
    MessageSquare, Reply, Pencil, Check, File, FileImage, FileText, FileSpreadsheet,
    FileArchive, FileCode, FileType, History, Clock, Loader2, ChevronDown, ListChecks,
} from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { formatDuration } from '@/lib/duration';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { ItemTypeIcon } from '@/components/ItemTypeIcon';
import { LabelChip, PriorityIndicator, AssigneeAvatar, itemAccentColor, statusCategory, STATUS_CATEGORY_CLASSES } from '@/components/board/ItemPrimitives';
import {
    Workspace, Project, Board, BoardColumn, Item, ItemType, ItemPriority, User,
    Label as LabelType, Comment, Attachment, ItemHistoryEntry,
} from '@/types';
import { safeHtmlProp } from '@/lib/sanitize';

// ── Minimal item shape used by the "Link issue" picker ───────────────────────
export type ProjectItemLite = {
    id: number;
    key: string;
    title: string;
    board_id: number;
    board?: { id: number; name: string; slug: string };
};

// ── File-type helpers ────────────────────────────────────────────────────────
export type PreviewType = 'pdf' | 'image' | 'video' | 'other';

export function getFileIconByName(filename: string): { Icon: React.ComponentType<{ className?: string }>; colorClass: string } {
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

export function getFilePreviewType(filename: string): PreviewType {
    const ext = filename?.split('.').pop()?.toLowerCase() ?? '';
    if (['pdf'].includes(ext)) return 'pdf';
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'heic', 'avif'].includes(ext)) return 'image';
    if (['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'].includes(ext)) return 'video';
    return 'other';
}

// ── Recursive comment thread ─────────────────────────────────────────────────
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
    projectId: number;
}

export function CommentItem({
    comment, depth, replyingTo, replyText, setReplyText,
    handleReply, handlePostComment, cancelReply, replyEditorKey, projectId,
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
                        dangerouslySetInnerHTML={safeHtmlProp(comment.body)}
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
                                            projectId={projectId}
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
                                    projectId={projectId}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ── Hook: single source of truth for all item-detail state + handlers ────────
export interface UseItemDetailArgs {
    item: Item | null;
    workspace: Workspace;
    project: Project;
    board: Board;
    itemTypes: ItemType[];
    priorities: ItemPriority[];
    projectUsers: User[];
    projectLabels: LabelType[];
    projectItems: ProjectItemLite[];
    columns: BoardColumn[];
    currentUserId: number;
    /** Open another item (popup: switch modal item; full page: navigate). */
    onOpenItem: (item: Item) => void;
}

export function useItemDetail(args: UseItemDetailArgs) {
    const {
        item, workspace, project, board, itemTypes, priorities,
        projectUsers, projectLabels, projectItems, columns, currentUserId, onOpenItem,
    } = args;

    const [selectedLabels, setSelectedLabels] = useState<number[]>(item?.labels?.map(l => l.id) || []);
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
    const [originalEstimate, setOriginalEstimate] = useState(item?.original_estimate ? formatDuration(item.original_estimate) : '');
    const [startDate, setStartDate] = useState(item?.start_date?.toString().split('T')[0] || '');
    const [startTime, setStartTime] = useState(item?.start_time?.substring(0, 5) || '');
    const [dueDate, setDueDate] = useState(item?.due_date?.toString().split('T')[0] || '');
    const [dueTime, setDueTime] = useState(item?.due_time?.substring(0, 5) || '');

    // Linked issues
    const [linkDialogOpen, setLinkDialogOpen] = useState(false);
    const [linkType, setLinkType] = useState('relates_to');
    const [targetItemId, setTargetItemId] = useState('');

    // Subtask quick-create
    const [newSubtaskTitle, setNewSubtaskTitle] = useState('');

    // File preview
    const [filePreview, setFilePreview] = useState<{ url: string; title: string; previewType: PreviewType } | null>(null);

    // The full item page sends comments/attachments for the first paint; the board popup
    // doesn't (they're fetched lazily) — never show the previous item's while loading.
    useEffect(() => {
        setLocalComments(item?.comments ?? []);
        setLocalAttachments(item?.attachments ?? []);
    }, [item?.id, item?.comments, item?.attachments]);

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
            setOriginalEstimate(item.original_estimate ? formatDuration(item.original_estimate) : '');
            setStartDate(item.start_date?.toString().split('T')[0] || '');
            setStartTime(item.start_time?.substring(0, 5) || '');
            setDueDate(item.due_date?.toString().split('T')[0] || '');
            setDueTime(item.due_time?.substring(0, 5) || '');
            const parsed = parseDescription(item.description);
            setEditorContent(parsed);
            setSavedDescription(parsed);
        }
        setNewSubtaskTitle('');
        setTargetItemId('');
        setLinkType('relates_to');
    }, [item?.id]);

    const saveProp = useCallback((fields: Record<string, any>) => {
        if (!item) return;
        router.patch(
            route('boards.items.update', [workspace.slug, project.slug, board.slug, item.id]),
            fields,
            { preserveScroll: true },
        );
    }, [workspace.slug, project.slug, board.slug, item?.id]);

    // ── Derived board data ─────────────────────────────────────────────────
    const allBoardItems = useMemo(
        () => columns.flatMap((c) => (c as BoardColumn & { items?: Item[] }).items ?? []),
        [columns],
    );
    // `live` = freshest copy from the reactive columns prop (links/watchers refresh
    // after mutation even though `item` may be a state snapshot in the popup).
    const live = allBoardItems.find((i) => i.id === item?.id) ?? item;
    const subtasks = useMemo(
        () => allBoardItems.filter((i) => i.parent_id === item?.id && i.item_type?.slug === 'subtask'),
        [allBoardItems, item?.id],
    );
    const doneSubtasks = subtasks.filter((s) => !!s.completed_at).length;
    const rootItemsForParent = useMemo(
        () => allBoardItems.filter((i) => !i.parent_id && i.id !== item?.id),
        [allBoardItems, item?.id],
    );

    // ── Lazy details: comments, attachments and history come from their own endpoint ──
    const [history, setHistory] = useState<ItemHistoryEntry[]>([]);
    const [detailsLoading, setDetailsLoading] = useState(false);
    const [timeSpent, setTimeSpent] = useState(item?.time_spent ?? 0);
    const currentItemIdRef = useRef(item?.id);
    currentItemIdRef.current = item?.id;

    const refreshDetails = useCallback(() => {
        if (!item) return;
        const itemId = item.id;
        setDetailsLoading(true);
        window.axios
            .get(route('boards.items.details', [workspace.slug, project.slug, board.slug, itemId]))
            .then(({ data }: { data: { comments: Comment[]; attachments: Attachment[]; history: ItemHistoryEntry[]; time_spent: number } }) => {
                // Ignore answers for an item the user already navigated away from
                if (currentItemIdRef.current !== itemId) return;
                setLocalComments(data.comments);
                setLocalAttachments(data.attachments);
                setHistory(data.history);
                setTimeSpent(data.time_spent ?? 0);
            })
            .catch(() => { /* keep what we have; the board itself still works */ })
            .finally(() => setDetailsLoading(false));
    }, [workspace.slug, project.slug, board.slug, item?.id]);

    // Re-fetch whenever the board reload shows this item changed (comment, upload, edit, …)
    const detailsKey = [
        live?.id, live?.updated_at, live?.comments_count, live?.attachments_count,
        (live?.labels ?? []).map((l) => l.id).join(','),
        (live?.outgoing_links ?? []).length, (live?.incoming_links ?? []).length,
    ].join('|');
    useEffect(() => {
        if (!item) {
            setHistory([]);
            return;
        }
        refreshDetails();
    }, [detailsKey]);

    // ── Time tracking ─────────────────────────────────────────────────────
    const [logWorkOpen, setLogWorkOpen] = useState(false);
    const [logWorkTime, setLogWorkTime] = useState('');
    const [logWorkNote, setLogWorkNote] = useState('');
    const [logWorkError, setLogWorkError] = useState<string | null>(null);
    const [isLoggingWork, setIsLoggingWork] = useState(false);

    const handleLogWork = () => {
        if (!item || !logWorkTime.trim()) return;
        setIsLoggingWork(true);
        setLogWorkError(null);
        router.post(route('boards.items.work-logs.store', [workspace.slug, project.slug, board.slug, item.id]), {
            time: logWorkTime.trim(),
            note: logWorkNote.trim() || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setLogWorkOpen(false);
                setLogWorkTime('');
                setLogWorkNote('');
            },
            onError: (errors) => setLogWorkError(errors.time ?? 'Could not log work.'),
            onFinish: () => setIsLoggingWork(false),
        });
    };
    const subtaskTypeId = itemTypes.find((t) => t.slug === 'subtask')?.id;

    // ── Linked issues ──────────────────────────────────────────────────────
    const linkTypeOptions = [
        { value: 'blocks', label: 'blocks' },
        { value: 'is_blocked_by', label: 'is blocked by' },
        { value: 'duplicates', label: 'duplicates' },
        { value: 'is_duplicated_by', label: 'is duplicated by' },
        { value: 'relates_to', label: 'relates to' },
        { value: 'clones', label: 'clones' },
        { value: 'is_cloned_by', label: 'is cloned by' },
    ];
    const inverseLinkType = (t: string) =>
        t === 'blocks' ? 'is_blocked_by'
        : t === 'is_blocked_by' ? 'blocks'
        : t === 'duplicates' ? 'is_duplicated_by'
        : t === 'is_duplicated_by' ? 'duplicates'
        : t === 'clones' ? 'is_cloned_by'
        : t === 'is_cloned_by' ? 'clones'
        : t;
    const linkableItems = useMemo(
        () => projectItems.filter((pi) => pi.id !== item?.id),
        [projectItems, item?.id],
    );
    const handleCreateLink = () => {
        if (!item || !targetItemId) return;
        router.post(route('boards.items.links.store', [workspace.slug, project.slug, board.slug, item.id]), {
            target_item_id: parseInt(targetItemId, 10),
            link_type: linkType,
        }, { preserveScroll: true, onSuccess: () => { setLinkDialogOpen(false); setTargetItemId(''); } });
    };
    const handleDeleteLink = (linkId: number) => {
        if (!item) return;
        router.delete(route('boards.items.links.destroy', [workspace.slug, project.slug, board.slug, item.id, linkId]), {
            preserveScroll: true,
        });
    };

    const handleCreateSubtask = () => {
        if (!item || !newSubtaskTitle.trim()) return;
        router.post(route('boards.items.store', [workspace.slug, project.slug, board.slug]), {
            title: newSubtaskTitle.trim(),
            column_id: item.column_id,
            item_type_id: subtaskTypeId,
            parent_id: item.id,
        }, { preserveScroll: true, onSuccess: () => setNewSubtaskTitle('') });
    };

    // ── Watchers ───────────────────────────────────────────────────────────
    const watcherIds = new Set((live?.watchers ?? []).map((w) => w.id));
    const toggleWatcher = (userId: number) => {
        if (!item) return;
        router.post(route('boards.items.watch', [workspace.slug, project.slug, board.slug, item.id]), {
            user_id: userId,
            watch: !watcherIds.has(userId),
        }, { preserveScroll: true });
    };

    // ── Title ──────────────────────────────────────────────────────────────
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
    const cancelTitle = () => { setTitleDraft(titleValue); setIsEditingTitle(false); };

    // ── Description ──────────────────────────────────────────────────────────
    const handleEditorChange = useCallback((content: string) => { setEditorContent(content); }, []);
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

    // ── Comments ─────────────────────────────────────────────────────────────
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

    // ── Attachments ──────────────────────────────────────────────────────────
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

    // Checkbox toggle in description view mode
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

    return {
        // context
        item, live, workspace, project, board, itemTypes, priorities, projectUsers,
        projectLabels, columns, currentUserId, onOpenItem,
        accent: item ? itemAccentColor(item) : null,
        saveProp,
        // title
        titleValue, isEditingTitle, titleDraft, setTitleDraft, titleInputRef,
        startEditTitle, saveTitle, cancelTitle,
        // description
        editorContent, savedDescription, editorKey, isEditingDescription,
        handleEditorChange, startEditDescription, saveDescription, cancelDescription, handleCheckboxToggle,
        // properties
        columnId, assignedTo, itemTypeId, setItemTypeId, priorityId, setPriorityId,
        parentId, setParentId, storyPoints, setStoryPoints, originalEstimate, setOriginalEstimate,
        startDate, setStartDate, startTime, setStartTime,
        dueDate, setDueDate, dueTime, setDueTime,
        setAssignedTo, handleMoveToColumn,
        // labels
        selectedLabels, labelSearch, setLabelSearch, labelPopoverOpen, setLabelPopoverOpen,
        newLabelName, setNewLabelName, filteredLabels, toggleLabel, handleCreateLabel,
        // comments
        comment, setComment, commentEditorKey, setCommentEditorKey, commentActive, setCommentActive,
        localComments, replyingTo, replyText, setReplyText, replyEditorKey,
        handlePostComment, handleReply, cancelReply,
        // attachments
        localAttachments, isUploading, fileInputRef, handleFileUpload, handleDeleteAttachment,
        // links
        linkDialogOpen, setLinkDialogOpen, linkType, setLinkType, targetItemId, setTargetItemId,
        linkTypeOptions, inverseLinkType, linkableItems, handleCreateLink, handleDeleteLink,
        // subtasks
        subtasks, doneSubtasks, subtaskTypeId, rootItemsForParent,
        newSubtaskTitle, setNewSubtaskTitle, handleCreateSubtask,
        // watchers
        watcherIds, toggleWatcher,
        // file preview
        filePreview, setFilePreview,
        // history + time tracking
        history, detailsLoading, timeSpent,
        logWorkOpen, setLogWorkOpen, logWorkTime, setLogWorkTime, logWorkNote, setLogWorkNote,
        logWorkError, isLoggingWork, handleLogWork,
    };
}

export type ItemDetail = ReturnType<typeof useItemDetail>;

// ── History ──────────────────────────────────────────────────────────────────
const HISTORY_FIELD_LABELS: Record<string, string> = {
    title: 'Title',
    description: 'Description',
    board_id: 'Board',
    column_id: 'Status',
    sprint_id: 'Sprint',
    assigned_to: 'Assignee',
    priority_id: 'Priority',
    item_type_id: 'Type',
    parent_id: 'Parent',
    story_points: 'Story points',
    original_estimate: 'Estimate',
    start_date: 'Start date',
    start_time: 'Start time',
    due_date: 'Due date',
    due_time: 'Due time',
};

const LINK_TYPE_LABELS: Record<string, string> = {
    blocks: 'blocks',
    is_blocked_by: 'is blocked by',
    duplicates: 'duplicates',
    is_duplicated_by: 'is duplicated by',
    relates_to: 'relates to',
    clones: 'clones',
    is_cloned_by: 'is cloned by',
};

function HistoryValue({ value }: { value: unknown }) {
    if (value === null || value === undefined || value === '') {
        return <span className="italic text-muted-foreground">None</span>;
    }
    return <span className="font-medium text-foreground">{String(value)}</span>;
}

/** What happened, in words — e.g. "changed Status: To Do → In Progress". */
function HistoryEntryBody({ entry }: { entry: ItemHistoryEntry }) {
    const meta = entry.metadata ?? {};
    switch (entry.action) {
        case 'created':
            return <span>created the issue</span>;
        case 'commented':
            return (
                <span>
                    {meta.reply ? 'replied' : 'commented'}
                    {meta.excerpt ? <>: <span className="text-foreground/80">“{String(meta.excerpt)}”</span></> : null}
                </span>
            );
        case 'linked':
            return <span>linked <HistoryValue value={meta.item} /> ({LINK_TYPE_LABELS[String(meta.link_type)] ?? String(meta.link_type)})</span>;
        case 'unlinked':
            return <span>removed the link to <HistoryValue value={meta.item} /></span>;
        case 'attachment_added':
            return <span>attached <HistoryValue value={meta.name} /></span>;
        case 'attachment_removed':
            return <span>removed the attachment <HistoryValue value={meta.name} /></span>;
        case 'work_logged':
            return (
                <span>
                    logged <HistoryValue value={formatDuration(Number(meta.minutes))} />
                    {meta.note ? <> — <span className="text-foreground/80">{String(meta.note)}</span></> : null}
                </span>
            );
        case 'labels_changed': {
            const labels = entry.changes?.labels ?? {};
            return (
                <span>
                    {labels.added?.length ? <>added label{labels.added.length > 1 ? 's' : ''} <HistoryValue value={labels.added.join(', ')} /></> : null}
                    {labels.added?.length && labels.removed?.length ? ' and ' : null}
                    {labels.removed?.length ? <>removed label{labels.removed.length > 1 ? 's' : ''} <HistoryValue value={labels.removed.join(', ')} /></> : null}
                </span>
            );
        }
        default: {
            const changes = Object.entries(entry.changes ?? {});
            return (
                <div className="space-y-0.5">
                    {changes.map(([field, change]) => (
                        <div key={field}>
                            {change.changed ? (
                                <>updated the <span className="font-medium text-foreground">{HISTORY_FIELD_LABELS[field] ?? field}</span></>
                            ) : (
                                <>
                                    changed <span className="font-medium text-foreground">{HISTORY_FIELD_LABELS[field] ?? field}</span>:{' '}
                                    <HistoryValue value={change.from} /> <span className="text-muted-foreground">→</span> <HistoryValue value={change.to} />
                                </>
                            )}
                        </div>
                    ))}
                </div>
            );
        }
    }
}

function ItemHistoryList({ history, loading, emptyText = 'No history yet.' }: { history: ItemHistoryEntry[]; loading: boolean; emptyText?: string }) {
    if (history.length === 0) {
        return (
            <p className="text-sm text-muted-foreground py-2 flex items-center gap-2">
                {loading ? <><Loader2 className="size-3.5 animate-spin" /> Loading history…</> : emptyText}
            </p>
        );
    }
    return (
        <ol className="space-y-4">
            {history.map((entry) => <HistoryRow key={entry.id} entry={entry} />)}
        </ol>
    );
}

/** "All": comments and history in one timeline, newest first. */
function ItemActivityAll({ comments, history, loading }: { comments: Comment[]; history: ItemHistoryEntry[]; loading: boolean }) {
    const events = [
        ...comments.map((comment) => ({ kind: 'comment' as const, at: comment.created_at, comment })),
        ...history.map((entry) => ({ kind: 'history' as const, at: entry.created_at, entry })),
    ].sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime());

    if (events.length === 0) {
        return (
            <p className="text-sm text-muted-foreground py-2 flex items-center gap-2">
                {loading ? <><Loader2 className="size-3.5 animate-spin" /> Loading…</> : 'No activity yet.'}
            </p>
        );
    }
    return (
        <ol className="space-y-4">
            {events.map((event) => event.kind === 'history'
                ? <HistoryRow key={`h-${event.entry.id}`} entry={event.entry} />
                : (
                    <li key={`c-${event.comment.id}`} className="flex gap-3 text-sm">
                        <Avatar className="size-7 shrink-0">
                            <AvatarFallback className="text-[10px] bg-muted">{event.comment.user?.name?.charAt(0) ?? '?'}</AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-baseline gap-x-1.5">
                                <span className="font-medium text-foreground">{event.comment.user?.name ?? 'Someone'}</span>
                                <span className="text-muted-foreground">commented</span>
                                <time className="text-xs text-muted-foreground/80">{formatDistanceToNow(new Date(event.comment.created_at), { addSuffix: true })}</time>
                            </div>
                            <div className="mt-1 rounded-md border border-border/70 bg-card px-3 py-2 prose prose-sm max-w-none dark:prose-invert text-foreground" dangerouslySetInnerHTML={safeHtmlProp(event.comment.body)} />
                        </div>
                    </li>
                ))}
        </ol>
    );
}

function HistoryRow({ entry }: { entry: ItemHistoryEntry }) {
    return (
                <li key={entry.id} className="flex gap-3 text-sm">
                    <Avatar className="size-7 shrink-0">
                        {entry.user?.avatar && <AvatarImage src={entry.user.avatar} />}
                        <AvatarFallback className="text-[10px] bg-muted">{entry.user?.name?.charAt(0) ?? '?'}</AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1 text-muted-foreground leading-relaxed">
                        <div className="flex flex-wrap items-baseline gap-x-1.5">
                            <span className="font-medium text-foreground">{entry.user?.name ?? 'System'}</span>
                            <HistoryEntryBody entry={entry} />
                        </div>
                        <time className="text-xs text-muted-foreground/80" title={new Date(entry.created_at).toLocaleString()}>
                            {formatDistanceToNow(new Date(entry.created_at), { addSuffix: true })}
                        </time>
                    </div>
                </li>
    );
}

// ── Main content column ──────────────────────────────────────────────────────
export function ItemDetailMain({ d, className }: { d: ItemDetail; className?: string }) {
    const {
        item, live, workspace, project, board,
        titleValue, isEditingTitle, titleDraft, setTitleDraft, titleInputRef, startEditTitle, saveTitle, cancelTitle,
        editorContent, savedDescription, editorKey, isEditingDescription,
        handleEditorChange, startEditDescription, saveDescription, cancelDescription, handleCheckboxToggle,
        subtasks, doneSubtasks, subtaskTypeId, newSubtaskTitle, setNewSubtaskTitle, handleCreateSubtask, onOpenItem,
        linkDialogOpen, setLinkDialogOpen, linkType, setLinkType, targetItemId, setTargetItemId,
        linkTypeOptions, inverseLinkType, linkableItems, handleCreateLink, handleDeleteLink,
        localAttachments, isUploading, fileInputRef, handleFileUpload, handleDeleteAttachment, setFilePreview,
        comment, setComment, commentEditorKey, setCommentEditorKey, commentActive, setCommentActive,
        localComments, replyingTo, replyText, setReplyText, replyEditorKey,
        handlePostComment, handleReply, cancelReply, history, detailsLoading,
    } = d;
    const [activityTab, setActivityTab] = useState<'all' | 'comments' | 'history' | 'worklog'>('comments');
    if (!item) return null;

    return (
        <div className={cn('px-8 py-6 space-y-0', className)}>
            {/* Title */}
            <div className="mb-0">
                {isEditingTitle ? (
                    <div className="flex items-center gap-2">
                        <Input
                            ref={titleInputRef}
                            value={titleDraft}
                            onChange={(e) => setTitleDraft(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') saveTitle(); if (e.key === 'Escape') cancelTitle(); }}
                            className="text-2xl font-semibold border-0 border-b-2 border-primary rounded-none px-0 py-1 h-auto focus-visible:ring-0 bg-transparent flex-1 leading-tight"
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
                    <div className="group flex items-center gap-2 cursor-text rounded-md -mx-2 px-2 py-1 hover:bg-muted/60 transition-colors" onClick={startEditTitle}>
                        <h1 className="text-2xl font-semibold tracking-[-0.01em] text-foreground leading-tight flex-1">
                            {titleValue || <span className="text-muted-foreground font-normal">Issue title</span>}
                        </h1>
                        <Pencil className="size-3.5 text-muted-foreground/0 group-hover:text-muted-foreground/60 transition-colors shrink-0" />
                    </div>
                )}
            </div>

            {/* Quick actions (Jira: Attach · Create subtask · Link issue) */}
            <div className="flex flex-wrap items-center gap-1.5 pt-2 pb-4">
                <Button type="button" variant="secondary" size="sm" className="h-8 gap-1.5 rounded-[4px] text-[13px]" onClick={() => fileInputRef.current?.click()}>
                    <Paperclip className="size-3.5" /> Attach
                </Button>
                {subtaskTypeId && !item.parent_id && (
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        className="h-8 gap-1.5 rounded-[4px] text-[13px]"
                        onClick={() => {
                            const input = document.getElementById(`subtask-input-${item.id}`) as HTMLInputElement | null;
                            input?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            input?.focus();
                        }}
                    >
                        <ListChecks className="size-3.5" /> Create subtask
                    </Button>
                )}
                <Button type="button" variant="secondary" size="sm" className="h-8 gap-1.5 rounded-[4px] text-[13px]" onClick={() => setLinkDialogOpen(true)}>
                    <Link2 className="size-3.5" /> Link issue
                </Button>
            </div>

            {/* Description */}
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

            {/* Subtasks */}
            {item.item_type?.slug !== 'subtask' && (
                <section className="py-5 border-t border-border/60">
                    <h2 className="text-sm font-semibold flex items-center gap-2 mb-3">
                        <CheckSquare className="size-4 text-muted-foreground" />
                        Subtasks
                        {subtasks.length > 0 && (
                            <span className="text-xs font-normal text-muted-foreground">{doneSubtasks}/{subtasks.length}</span>
                        )}
                    </h2>

                    {subtasks.length > 0 && (
                        <div className="mb-2 overflow-hidden rounded-lg border border-border/60 divide-y divide-border/50">
                            {subtasks.map((st) => {
                                const done = !!st.completed_at;
                                return (
                                    <button
                                        key={st.id}
                                        type="button"
                                        onClick={() => onOpenItem(st)}
                                        className="flex w-full items-center gap-2.5 px-3 py-2 text-left transition-colors hover:bg-muted/50"
                                    >
                                        <ItemTypeIcon type={st.item_type} className="size-4 shrink-0" />
                                        <span className={cn('flex-1 truncate text-sm', done && 'line-through text-muted-foreground')}>{st.title}</span>
                                        <span className="shrink-0 font-mono text-[10px] text-muted-foreground">{st.key}</span>
                                        <AssigneeAvatar user={st.assignee} size={20} />
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    <div className="flex items-center gap-2">
                        <Input
                            id={`subtask-input-${item.id}`}
                            value={newSubtaskTitle}
                            onChange={(e) => setNewSubtaskTitle(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter' && newSubtaskTitle.trim()) { e.preventDefault(); handleCreateSubtask(); } }}
                            placeholder="Add a subtask…"
                            className="h-8 text-sm"
                            disabled={!subtaskTypeId}
                        />
                        <Button size="sm" className="h-8 shrink-0 gap-1" onClick={handleCreateSubtask} disabled={!newSubtaskTitle.trim() || !subtaskTypeId}>
                            <Plus className="size-3.5" />Add
                        </Button>
                    </div>
                    {!subtaskTypeId && (
                        <p className="mt-1.5 text-[11px] text-muted-foreground">No “subtask” item type is configured for this project.</p>
                    )}
                </section>
            )}

            {/* Linked Issues */}
            <section className="py-5 border-t border-border/60">
                <div className="flex items-center justify-between mb-3">
                    <h2 className="text-sm font-semibold flex items-center gap-2">
                        <Link2 className="size-4 text-muted-foreground" />
                        Linked Issues
                    </h2>
                    <Dialog open={linkDialogOpen} onOpenChange={setLinkDialogOpen}>
                        <DialogTrigger asChild>
                            <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground hover:text-foreground gap-1.5">
                                <Plus className="size-3.5" />Link
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
                                            {linkableItems.map((pi) => (
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

                {(live?.outgoing_links?.length || 0) + (live?.incoming_links?.length || 0) === 0 ? (
                    <p className="text-sm text-muted-foreground">No linked issues yet</p>
                ) : (
                    <div className="space-y-1">
                        {live?.outgoing_links?.map((link) => (
                            <div key={`out-${link.id}`} className="flex items-center justify-between py-1.5 px-2 rounded-md -mx-2 group hover:bg-muted/40 transition-colors">
                                <div className="flex items-center gap-2 min-w-0">
                                    <span className="text-sm text-muted-foreground shrink-0">{linkTypeOptions.find(o => o.value === link.link_type)?.label || link.link_type}</span>
                                    <Link href={route('boards.items.show', [workspace.slug, project.slug, link.target_item?.board?.slug, link.target_item_id])} className="text-sm text-primary hover:underline truncate">
                                        <span className="font-mono text-sm mr-1">{link.target_item?.key}</span>{link.target_item?.title}
                                    </Link>
                                </div>
                                <Button variant="ghost" size="icon" className="size-6 opacity-0 group-hover:opacity-100 shrink-0" onClick={() => handleDeleteLink(link.id)}>
                                    <X className="size-3" />
                                </Button>
                            </div>
                        ))}
                        {live?.incoming_links?.map((link) => (
                            <div key={`in-${link.id}`} className="flex items-center justify-between py-1.5 px-2 rounded-md -mx-2 group hover:bg-muted/40 transition-colors">
                                <div className="flex items-center gap-2 min-w-0">
                                    <span className="text-sm text-muted-foreground shrink-0">{linkTypeOptions.find(o => o.value === inverseLinkType(link.link_type))?.label || link.link_type}</span>
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

            {/* Attachments */}
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

            {/* Activity: Comments | History (like Jira) */}
            <section className="py-5 border-t border-border/60">
                <div className="flex items-center gap-4 mb-5">
                    <h2 className="text-sm font-semibold">Activity</h2>
                    <div className="flex items-center gap-1 text-[13px]" role="tablist">
                        {([
                            ['all', 'All'],
                            ['comments', `Comments${localComments.length ? ` ${localComments.length}` : ''}`],
                            ['history', 'History'],
                            ['worklog', 'Work log'],
                        ] as const).map(([tab, label]) => (
                            <button
                                key={tab}
                                type="button"
                                role="tab"
                                aria-selected={activityTab === tab}
                                onClick={() => setActivityTab(tab)}
                                className={cn(
                                    'h-7 rounded-[4px] px-2.5 font-medium transition-colors',
                                    activityTab === tab ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                {activityTab === 'history' || activityTab === 'worklog' ? (
                    <ItemHistoryList
                        history={activityTab === 'worklog' ? history.filter((entry) => entry.action === 'work_logged') : history}
                        loading={detailsLoading}
                        emptyText={activityTab === 'worklog' ? 'No work logged yet.' : undefined}
                    />
                ) : activityTab === 'all' ? (
                    <ItemActivityAll
                        comments={localComments}
                        history={history.filter((entry) => entry.action !== 'commented')}
                        loading={detailsLoading}
                    />
                ) : (
                <>
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
                                onChange={(html) => { setComment(html); if (!commentActive) setCommentActive(true); }}
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
                                <Button variant="outline" size="sm" onClick={() => { setComment(''); setCommentEditorKey(k => k + 1); setCommentActive(false); }}>Cancel</Button>
                                <Button size="sm" onClick={() => handlePostComment()}>Save</Button>
                            </div>
                        )}
                    </div>
                </div>

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
                                projectId={project.id}
                            />
                        ))}
                    </div>
                )}
                </>
                )}
            </section>
        </div>
    );
}

// ── Properties sidebar ─────────────────────────────────────────────────────
export function ItemDetailSidebar({ d }: { d: ItemDetail }) {
    const {
        item, live, columns, itemTypes, priorities, projectUsers, projectLabels, currentUserId,
        columnId, handleMoveToColumn, assignedTo, setAssignedTo, saveProp,
        itemTypeId, setItemTypeId, parentId, setParentId, priorityId, setPriorityId,
        storyPoints, setStoryPoints, startDate, setStartDate, startTime, setStartTime, dueDate, setDueDate, dueTime, setDueTime,
        originalEstimate, setOriginalEstimate, timeSpent,
        logWorkOpen, setLogWorkOpen, logWorkTime, setLogWorkTime, logWorkNote, setLogWorkNote,
        logWorkError, isLoggingWork, handleLogWork,
        rootItemsForParent, watcherIds, toggleWatcher,
        selectedLabels, labelSearch, setLabelSearch, labelPopoverOpen, setLabelPopoverOpen,
        newLabelName, setNewLabelName, filteredLabels, toggleLabel, handleCreateLabel,
    } = d;
    const [detailsOpen, setDetailsOpen] = useState(true);
    if (!item) return null;

    const isSubtaskType = itemTypes.some((t) => t.slug === 'subtask' && t.id.toString() === itemTypeId);
    const currentColumn = columns.find((col) => col.id.toString() === columnId);
    const category = statusCategory(currentColumn, columns);

    return (
        <div className="space-y-4">
            {/* Status — the prominent button at the top, like Jira */}
            <Select value={columnId} onValueChange={handleMoveToColumn}>
                <SelectTrigger
                    className={cn(
                        'h-8 w-auto max-w-full gap-2 rounded-[4px] border px-3 text-[13px] font-semibold shadow-none transition-colors focus:ring-0 focus:ring-offset-0',
                        STATUS_CATEGORY_CLASSES[category],
                    )}
                    aria-label="Status"
                >
                    <SelectValue placeholder="Status">{currentColumn?.name ?? 'Status'}</SelectValue>
                </SelectTrigger>
                <SelectContent>
                    {columns.map((col) => (
                        <SelectItem key={col.id} value={col.id.toString()}>
                            <span className={cn('inline-flex items-center h-5 px-1.5 rounded-[3px] border text-[11px] font-bold uppercase tracking-[0.02em]', STATUS_CATEGORY_CLASSES[statusCategory(col, columns)])}>
                                {col.name}
                            </span>
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {/* Details box */}
            <div className="rounded-md border border-border">
                <button
                    type="button"
                    onClick={() => setDetailsOpen((open) => !open)}
                    className="flex w-full items-center justify-between px-3 py-2.5 text-[13px] font-semibold text-foreground hover:bg-muted/40 transition-colors rounded-t-md"
                    aria-expanded={detailsOpen}
                >
                    Details
                    <ChevronDown className={cn('size-4 text-muted-foreground transition-transform', !detailsOpen && '-rotate-90')} />
                </button>
                {detailsOpen && (
        <div className="space-y-0 divide-y divide-border/50 border-t border-border px-3">

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
                    {assignedTo !== String(currentUserId) && (
                        <button
                            type="button"
                            onClick={() => { setAssignedTo(String(currentUserId)); saveProp({ assigned_to: currentUserId }); }}
                            className="block -mt-0.5 mb-1 text-xs text-primary hover:underline"
                        >
                            Assign to me
                        </button>
                    )}
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
                                    <div className="flex items-center gap-2"><ItemTypeIcon type={type} />{type.name}</div>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            {/* Parent */}
            <div className="flex items-center min-h-[40px] py-1">
                <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Parent</span>
                <div className="flex-1">
                    <Select
                        value={parentId || 'none'}
                        onValueChange={(v) => { const val = v === 'none' ? '' : v; setParentId(val); saveProp({ parent_id: val ? parseInt(val, 10) : null }); }}
                    >
                        <SelectTrigger className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 transition-colors focus:ring-0 text-sm px-2 -ml-2 rounded-md">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none" disabled={isSubtaskType}>None</SelectItem>
                            {rootItemsForParent.map((r) => (
                                <SelectItem key={r.id} value={r.id.toString()}>
                                    <span className="font-mono text-xs">{r.key}</span> {r.title}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

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
                                    <div className="flex items-center gap-2"><PriorityIndicator slug={priority.slug} />{priority.name}</div>
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
                    <Input type="number" min="0" value={storyPoints} onChange={(e) => setStoryPoints(e.target.value)} onBlur={() => saveProp({ story_points: storyPoints || null })} className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md" placeholder="None" />
                </div>
            </div>

            {/* Original estimate (Jira-style duration: 2h, 1d 4h) */}
            <div className="flex items-center min-h-[40px] py-1">
                <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Estimate</span>
                <div className="flex-1 px-2 -ml-2">
                    <Input
                        value={originalEstimate}
                        onChange={(e) => setOriginalEstimate(e.target.value)}
                        onBlur={() => {
                            const current = live?.original_estimate ? formatDuration(live.original_estimate) : '';
                            if (originalEstimate.trim() !== current) saveProp({ original_estimate: originalEstimate.trim() || null });
                        }}
                        onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); }}
                        className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md"
                        placeholder="e.g. 2h, 1d 4h"
                    />
                </div>
            </div>

            {/* Time tracking: logged vs. estimate + Log work */}
            <div className="flex items-start min-h-[40px] py-2">
                <span className="w-[40%] shrink-0 text-xs text-muted-foreground pt-0.5">Time tracking</span>
                <div className="flex-1 min-w-0 space-y-1.5">
                    {(() => {
                        const estimate = live?.original_estimate ?? 0;
                        const spent = timeSpent;
                        const overrun = estimate > 0 && spent > estimate;
                        const percent = estimate > 0 ? Math.min(100, Math.round((spent / estimate) * 100)) : spent > 0 ? 100 : 0;
                        return (
                            <>
                                <div className="h-1.5 rounded-full bg-muted overflow-hidden" title={`${percent}%`}>
                                    <div className={cn('h-full rounded-full transition-all', overrun ? 'bg-amber-500' : 'bg-primary')} style={{ width: `${percent}%` }} />
                                </div>
                                <div className="flex flex-wrap gap-x-2 text-[11px] text-muted-foreground">
                                    <span className="whitespace-nowrap">{spent > 0 ? `${formatDuration(spent)} logged` : 'No time logged'}</span>
                                    {estimate > 0 && (
                                        <span className={cn('whitespace-nowrap', overrun && 'text-amber-600 dark:text-amber-400')}>
                                            · {overrun ? `${formatDuration(spent - estimate)} over` : `${formatDuration(estimate - spent)} left`}
                                        </span>
                                    )}
                                </div>
                            </>
                        );
                    })()}
                    <Popover open={logWorkOpen} onOpenChange={setLogWorkOpen}>
                        <PopoverTrigger asChild>
                            <button type="button" className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors">
                                <Clock className="size-3.5" /> Log work
                            </button>
                        </PopoverTrigger>
                        <PopoverContent className="w-72 p-3" align="start">
                            <form className="space-y-2.5" onSubmit={(e) => { e.preventDefault(); handleLogWork(); }}>
                                <div className="space-y-1">
                                    <Label htmlFor="log-work-time" className="text-xs">Time spent</Label>
                                    <Input id="log-work-time" value={logWorkTime} onChange={(e) => setLogWorkTime(e.target.value)} placeholder="e.g. 45m, 1h 30m, 1d" autoFocus />
                                    <p className="text-[11px] text-muted-foreground">1d = 8h · 1w = 5d</p>
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="log-work-note" className="text-xs">What did you work on? (optional)</Label>
                                    <Input id="log-work-note" value={logWorkNote} onChange={(e) => setLogWorkNote(e.target.value)} maxLength={255} />
                                </div>
                                {logWorkError && <p className="text-xs text-destructive">{logWorkError}</p>}
                                <div className="flex justify-end gap-2">
                                    <Button type="button" size="sm" variant="outline" onClick={() => setLogWorkOpen(false)}>Cancel</Button>
                                    <Button type="submit" size="sm" disabled={isLoggingWork || !logWorkTime.trim()}>
                                        {isLoggingWork && <Loader2 className="size-3.5 mr-1.5 animate-spin" />}
                                        Log
                                    </Button>
                                </div>
                            </form>
                        </PopoverContent>
                    </Popover>
                </div>
            </div>

            {/* Start Date */}
            <div className="flex items-center min-h-[40px] py-1">
                <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Start Date</span>
                <div className="flex-1 px-2 -ml-2">
                    <Input type="date" value={startDate} onChange={(e) => { setStartDate(e.target.value); saveProp({ start_date: e.target.value || null }); }} className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md" />
                </div>
            </div>
            {/* Start Time */}
            <div className="flex items-center min-h-[40px] py-1">
                <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Start Time</span>
                <div className="flex-1 px-2 -ml-2">
                    <Input type="time" value={startTime} onChange={(e) => { setStartTime(e.target.value); saveProp({ start_time: e.target.value || null }); }} className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md" />
                </div>
            </div>

            {/* Due Date */}
            <div className="flex items-center min-h-[40px] py-1">
                <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Due Date</span>
                <div className="flex-1 px-2 -ml-2">
                    <Input type="date" value={dueDate} onChange={(e) => { setDueDate(e.target.value); saveProp({ due_date: e.target.value || null }); }} className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md" />
                </div>
            </div>
            {/* Due Time */}
            <div className="flex items-center min-h-[40px] py-1">
                <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Due Time</span>
                <div className="flex-1 px-2 -ml-2">
                    <Input type="time" value={dueTime} onChange={(e) => { setDueTime(e.target.value); saveProp({ due_time: e.target.value || null }); }} className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md" />
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

            {/* Watchers */}
            <div className="flex items-start min-h-[40px] py-1">
                <span className="w-[40%] shrink-0 text-xs text-muted-foreground pt-2">Watchers</span>
                <div className="flex-1 flex flex-wrap items-center gap-1">
                    {(live?.watchers ?? []).map((w) => (
                        <span key={w.id} title={w.name}><AssigneeAvatar user={w} size={22} /></span>
                    ))}
                    <Popover>
                        <PopoverTrigger asChild>
                            <button className="size-[22px] grid place-items-center rounded-full border border-dashed border-muted-foreground/50 text-muted-foreground transition-colors hover:border-primary hover:text-primary">
                                <Plus className="size-3" />
                            </button>
                        </PopoverTrigger>
                        <PopoverContent align="start" className="p-1 w-56">
                            <div className="max-h-60 overflow-y-auto">
                                {projectUsers.map((u) => (
                                    <button key={u.id} onClick={() => toggleWatcher(u.id)} className="flex items-center gap-2 w-full px-2 py-1.5 rounded-md text-sm hover:bg-muted/60 transition-colors text-left">
                                        <AssigneeAvatar user={u} size={22} />
                                        <span className="flex-1 truncate">{u.name}{u.id === currentUserId && ' (you)'}</span>
                                        {watcherIds.has(u.id) && <Check className="size-3.5 shrink-0 text-primary" />}
                                    </button>
                                ))}
                            </div>
                        </PopoverContent>
                    </Popover>
                </div>
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
                                        return <LabelChip key={id} label={lbl} />;
                                    })
                                )}
                            </button>
                        </PopoverTrigger>
                        <PopoverContent className="p-0 w-56" align="start">
                            <div className="p-2 border-b">
                                <Input autoFocus placeholder="Search labels…" value={labelSearch} onChange={(e) => setLabelSearch(e.target.value)} className="h-7 text-xs border-0 shadow-none focus-visible:ring-0 px-1" />
                            </div>
                            <div className="max-h-52 overflow-y-auto p-1">
                                {filteredLabels.map((label) => {
                                    const active = selectedLabels.includes(label.id);
                                    return (
                                        <button key={label.id} onClick={() => toggleLabel(label.id)} className="flex items-center gap-2 w-full px-2 py-1.5 rounded-md text-sm hover:bg-muted/60 transition-colors text-left">
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
                                    <Input placeholder="New label name…" value={newLabelName} onChange={(e) => setNewLabelName(e.target.value)} className="h-7 text-xs flex-1" onKeyDown={(e) => { if (e.key === 'Enter' && newLabelName.trim()) { e.preventDefault(); handleCreateLabel(); } }} />
                                    <Button size="sm" className="h-7 px-2" onClick={handleCreateLabel} disabled={!newLabelName.trim()}>
                                        <Plus className="size-3" />
                                    </Button>
                                </div>
                            </div>
                        </PopoverContent>
                    </Popover>
                </div>
            </div>
        </div>
                )}
            </div>

            <p className="px-1 text-[11px] leading-relaxed text-muted-foreground">
                Created {formatDistanceToNow(new Date(item.created_at), { addSuffix: true })}
                <br />
                Updated {formatDistanceToNow(new Date((live ?? item).updated_at), { addSuffix: true })}
            </p>
        </div>
    );
}

// ── File-preview dialog (shared) ─────────────────────────────────────────────
export function ItemFilePreviewDialog({ d }: { d: ItemDetail }) {
    const { filePreview, setFilePreview } = d;
    return (
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
    );
}
