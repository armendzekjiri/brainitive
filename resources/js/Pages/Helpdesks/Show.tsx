import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { PageProps, Workspace, Project, Helpdesk, HelpdeskColumn, Ticket, TicketReply, ItemPriority, User, HelpdeskField } from '@/types';
import { cn } from '@/lib/utils';
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
    Sheet,
    SheetContent,
    SheetTrigger,
} from '@/components/ui/sheet';
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
    MessageSquare,
    Paperclip,
    X,
    ChevronUp,
    ChevronDown,
    Equal,
    Eye,
    ExternalLink,
    Maximize2,
    Check,
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
    CalendarDays,
    Mail,
    Phone,
    Tag,
    Clock,
    AlertCircle,
    CheckCircle,
    Pencil,
    MailPlus,
    UserPlus,
    SlidersHorizontal,
} from 'lucide-react';
import { useState, useRef, useEffect, DragEvent, FormEvent, useCallback, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { safeHtmlProp } from '@/lib/sanitize';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk & { columns: (HelpdeskColumn & { tickets: Ticket[] })[]; fields: HelpdeskField[] };
    priorities: ItemPriority[];
    projectUsers: User[];
}

// Priority icon component (reuse from boards)
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

// File-type helpers
type PreviewType = 'pdf' | 'image' | 'video' | 'other';

function getFileIconByName(filename: string): { Icon: React.ComponentType<{ className?: string }>; colorClass: string } {
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
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'].includes(ext)) return 'image';
    if (['mp4', 'webm', 'ogg', 'mov', 'avi'].includes(ext)) return 'video';
    return 'other';
}

// Ticket Card Component
function TicketCard({
    ticket,
    workspace,
    project,
    helpdesk,
    onEdit,
    onDragStart,
    onDragEnd,
}: {
    ticket: Ticket;
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk;
    onEdit: (ticket: Ticket) => void;
    onDragStart: (e: DragEvent, ticket: Ticket) => void;
    onDragEnd: () => void;
}) {
    return (
        <Card
            draggable
            onDragStart={(e) => onDragStart(e, ticket)}
            onDragEnd={onDragEnd}
            onClick={() => onEdit(ticket)}
            className="cursor-pointer hover:shadow-md hover:border-primary/30 transition-all group bg-background border border-border/50"
        >
            <CardContent className="p-3">
                {/* Ticket Key and Contact */}
                <div className="flex items-center justify-between mb-2">
                    <div className="flex items-center gap-2">
                        <span className="text-xs font-medium text-primary">{ticket.key}</span>
                        {ticket.contact && (
                            <Badge variant="outline" className="text-[10px] px-1.5 py-0">
                                <Mail className="size-3 mr-1" />
                                {ticket.contact.name || ticket.contact.email}
                            </Badge>
                        )}
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
                            <DropdownMenuItem onClick={(e) => { e.stopPropagation(); onEdit(ticket); }}>
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onClick={(e) => {
                                    e.stopPropagation();
                                    router.delete(route('tickets.destroy', [workspace.slug, project.slug, helpdesk.slug, ticket.id]));
                                }}
                                className="text-destructive focus:text-destructive"
                            >
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                {/* Subject */}
                <p className="text-sm font-medium mb-3 line-clamp-2 leading-snug">{ticket.subject}</p>

                {/* Message/description preview (content from embed or modal) */}
                {ticket.description && (
                    <div
                        className="text-xs text-muted-foreground line-clamp-2 mb-3 break-words"
                        title={ticket.description.replace(/<[^>]*>/g, '').trim().slice(0, 200)}
                    >
                        {ticket.description.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120)}
                        {(ticket.description.replace(/<[^>]*>/g, '').trim().length > 120) ? '…' : ''}
                    </div>
                )}

                {/* Custom Fields (visible on card) */}
                {ticket.field_values && ticket.field_values.length > 0 && (
                    <div className="flex flex-wrap gap-1 mb-3">
                        {ticket.field_values.slice(0, 3).map((fv) => {
                            const field = fv.field;
                            if (!field?.is_visible_on_card) return null;
                            const raw = fv.value ?? '';
                            const preview = field.type === 'rich_text'
                                ? raw.replace(/<[^>]*>/g, '').trim().slice(0, 20)
                                : raw.substring(0, 20);
                            return (
                                <Badge
                                    key={fv.id}
                                    variant="outline"
                                    className="text-[10px] px-1.5 py-0"
                                >
                                    {field.label}: {preview || '—'}
                                </Badge>
                            );
                        })}
                    </div>
                )}

                {/* Footer */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <PriorityIcon slug={ticket.priority?.slug} />
                        {(ticket.replies?.length ?? 0) > 0 && (
                            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                <MessageSquare className="size-3" />
                                <span>{ticket.replies?.length}</span>
                            </div>
                        )}
                        {(ticket.attachments?.length ?? 0) > 0 && (
                            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                <Paperclip className="size-3" />
                                <span>{ticket.attachments?.length}</span>
                            </div>
                        )}
                    </div>
                    {ticket.assignee ? (
                        <Avatar className="size-6 ring-2 ring-background">
                            {ticket.assignee.avatar_display && (
                                <AvatarImage src={ticket.assignee.avatar_display} alt={ticket.assignee.name} />
                            )}
                            <AvatarFallback className="text-[10px] bg-gradient-to-br from-brand-blue to-brand-slate text-white font-semibold">
                                {ticket.assignee.name.charAt(0).toUpperCase()}
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

// Create Ticket Inline
function CreateTicketInline({
    column,
    workspace,
    project,
    helpdesk,
    fields,
}: {
    column: HelpdeskColumn;
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk;
    fields: HelpdeskField[];
}) {
    const [isCreating, setIsCreating] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, reset } = useForm({
        subject: '',
        column_id: column.id,
        priority_id: '',
        assigned_to: '',
        description: '',
    });

    const requiredFields = fields.filter(f => f.is_required);
    const canSubmit = data.subject.trim() && requiredFields.every(f => 
        data[f.name as keyof typeof data] !== undefined && data[f.name as keyof typeof data] !== ''
    );

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!canSubmit) return;
        const payload = {
            subject: data.subject,
            column_id: data.column_id,
            priority_id: data.priority_id || null,
            assigned_to: data.assigned_to || null,
            description: data.description || null,
        };
        router.post(route('tickets.store', [workspace.slug, project.slug, helpdesk.slug]), payload, {
            onSuccess: () => {
                reset('subject', 'priority_id', 'assigned_to', 'description');
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
                New Ticket
            </Button>
        );
    }

    return (
        <form onSubmit={handleSubmit} className="space-y-2">
            <Card className="bg-background shadow-sm">
                <CardContent className="p-3 space-y-2">
                    <Input
                        ref={inputRef}
                        value={data.subject}
                        onChange={(e) => setData('subject', e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder="Ticket subject"
                        className="border-0 p-0 h-auto text-sm focus-visible:ring-0 placeholder:text-muted-foreground/60"
                        autoFocus
                    />
                    {fields.filter(f => f.is_visible_on_card).map((field) => (
                        <div key={field.id} className="flex flex-col gap-1">
                            <Label className="text-[10px] text-muted-foreground">{field.label}</Label>
                            {field.type === 'select' ? (
                                <Select 
                                    value={data[field.name as keyof typeof data] as string || ''} 
                                    onValueChange={(v) => setData(field.name as keyof typeof data, v)}
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder={`Select ${field.label}`} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {field.options?.map((opt, idx) => (
                                            <SelectItem key={idx} value={opt}>{opt}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <Input
                                    type={field.type === 'number' ? 'number' : 'text'}
                                    value={data[field.name as keyof typeof data] as string || ''}
                                    onChange={(e) => setData(field.name as keyof typeof data, e.target.value)}
                                    className="h-8 text-xs"
                                    placeholder={field.label}
                                />
                            )}
                        </div>
                    ))}
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
function HelpdeskColumnComponent({
    column,
    workspace,
    project,
    helpdesk,
    fields,
    onEditTicket,
    onDragStart,
    onDragEnd,
    onDragOver,
    onDrop,
    isDragOver,
}: {
    column: HelpdeskColumn & { tickets: Ticket[] };
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk;
    fields: HelpdeskField[];
    onEditTicket: (ticket: Ticket) => void;
    onDragStart: (e: DragEvent, ticket: Ticket) => void;
    onDragEnd: () => void;
    onDragOver: (e: DragEvent, column: HelpdeskColumn) => void;
    onDrop: (e: DragEvent, column: HelpdeskColumn) => void;
    isDragOver: boolean;
}) {
    const columnColor = column.color || '#94a3b8';

    return (
        <div
            className={`flex-shrink-0 w-[240px] sm:w-[280px] flex flex-col max-h-full rounded-xl transition-colors ${isDragOver ? 'ring-2 ring-primary/40' : ''
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
                        {column.tickets?.length || 0}
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

            {/* Tickets */}
            <ScrollArea className="flex-1 px-2 pt-2">
                <div className="space-y-2 pb-2 pr-1">
                    {column.tickets?.map((ticket) => (
                        <TicketCard
                            key={ticket.id}
                            ticket={ticket}
                            workspace={workspace}
                            project={project}
                            helpdesk={helpdesk}
                            onEdit={onEditTicket}
                            onDragStart={onDragStart}
                            onDragEnd={onDragEnd}
                        />
                    ))}
                </div>
            </ScrollArea>

            {/* Create Ticket */}
            <div className="p-2 pt-0">
                <CreateTicketInline
                    column={column}
                    workspace={workspace}
                    project={project}
                    helpdesk={helpdesk}
                    fields={fields}
                />
            </div>
        </div>
    );
}

// Ticket Edit Modal (simplified)
function TicketEditModal({
    ticket,
    workspace,
    project,
    helpdesk,
    priorities,
    projectUsers,
    columns,
    fields,
    open,
    onClose,
}: {
    ticket: Ticket | null;
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk;
    priorities: ItemPriority[];
    projectUsers: User[];
    columns: HelpdeskColumn[];
    fields: HelpdeskField[];
    open: boolean;
    onClose: () => void;
}) {
    const [subjectValue, setSubjectValue] = useState(ticket?.subject || '');
    const [isEditingSubject, setIsEditingSubject] = useState(false);
    const [subjectDraft, setSubjectDraft] = useState(ticket?.subject || '');
    const titleInputRef = useRef<HTMLInputElement>(null);

    const [description, setDescription] = useState(ticket?.description || '');
    const [comment, setComment] = useState('');
    const [commentEditorKey, setCommentEditorKey] = useState(0);
    const [commentActive, setCommentActive] = useState(false);
    const [localReplies, setLocalReplies] = useState(ticket?.replies || []);
    const [localAttachments, setLocalAttachments] = useState(ticket?.attachments || []);
    const [isUploading, setIsUploading] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Sidebar state
    const [columnId, setColumnId] = useState(ticket?.column_id?.toString() || '');
    const [assignedTo, setAssignedTo] = useState(ticket?.assigned_to?.toString() || '');
    const [priorityId, setPriorityId] = useState(ticket?.priority_id?.toString() || '');
    const [fieldValues, setFieldValues] = useState<Record<string, string>>({});
    const [detailsSheetOpen, setDetailsSheetOpen] = useState(false);

    // Update when ticket changes
    useEffect(() => {
        if (ticket) {
            setSubjectValue(ticket.subject || '');
            setSubjectDraft(ticket.subject || '');
            setColumnId(ticket.column_id?.toString() || '');
            setAssignedTo(ticket.assigned_to?.toString() || '');
            setPriorityId(ticket.priority_id?.toString() || '');
            setDescription(ticket.description || '');
            setLocalReplies(ticket.replies || []);
            setLocalAttachments(ticket.attachments || []);
        }
    }, [ticket?.id]);

    // Title handlers
    const startEditTitle = () => {
        setSubjectDraft(subjectValue);
        setIsEditingSubject(true);
        setTimeout(() => titleInputRef.current?.focus(), 0);
    };
    const saveTitle = () => {
        if (!subjectDraft.trim()) return;
        setSubjectValue(subjectDraft);
        setIsEditingSubject(false);
        saveProp({ subject: subjectDraft });
    };
    const cancelTitle = () => {
        setSubjectDraft(subjectValue);
        setIsEditingSubject(false);
    };

    // Generic save helper
    const saveProp = useCallback((fields: Record<string, any>) => {
        if (!ticket) return;
        router.patch(
            route('tickets.update', [workspace.slug, project.slug, helpdesk.slug, ticket.id]),
            fields,
            { preserveScroll: true },
        );
    }, [workspace.slug, project.slug, helpdesk.slug, ticket?.id]);

    // Column change
    const handleMoveToColumn = (newColId: string) => {
        setColumnId(newColId);
        if (!ticket) return;
        router.patch(route('tickets.move', [workspace.slug, project.slug, helpdesk.slug, ticket.id]), {
            column_id: parseInt(newColId),
            position: 0,
        }, { preserveScroll: true });
    };

    // Comment handlers
    const handlePostComment = () => {
        const textBody = comment.replace(/<[^>]*>/g, '').trim();
        if (!textBody || !ticket) return;

        const optimisticReply: TicketReply = {
            id: Date.now(),
            ticket_id: ticket.id,
            user_id: 0,
            ticket_contact_id: undefined,
            body: comment,
            is_internal_note: false,
            user: { id: 0, name: 'You', email: '' },
            contact: undefined,
            attachments: undefined,
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
        };

        setLocalReplies(prev => [optimisticReply, ...prev]);
        setComment('');
        setCommentEditorKey(k => k + 1);
        setCommentActive(false);

        router.post(route('tickets.replies.store', [workspace.slug, project.slug, helpdesk.slug, ticket.id]), {
            body: comment,
            is_internal_note: false,
        }, { preserveScroll: true });
    };

    // Attachment handlers
    const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = e.target.files;
        if (!files || files.length === 0 || !ticket) return;
        setIsUploading(true);
        const formData = new FormData();
        formData.append('file', files[0]);
        router.post(route('tickets.attachments.store', [workspace.slug, project.slug, helpdesk.slug, ticket.id]), formData, {
            preserveScroll: true,
            onFinish: () => {
                setIsUploading(false);
                if (fileInputRef.current) fileInputRef.current.value = '';
            },
        });
    };

    if (!ticket) return null;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-6xl max-h-[92vh] p-0 gap-0 overflow-hidden [&>button]:hidden" aria-describedby={undefined}>
                <DialogTitle className="sr-only">Edit {ticket.key}: {ticket.subject}</DialogTitle>

                {/* Header */}
                <div className="flex items-center justify-between px-4 sm:px-6 py-3 border-b bg-background shrink-0">
                    <div className="flex items-center gap-3">
                        <span className="font-mono text-xs font-semibold text-primary tracking-wider">{ticket.key}</span>
                        {ticket.column && (
                            <Badge variant="secondary" className="text-xs">
                                <span className="w-1.5 h-1.5 rounded-full mr-1.5 inline-block" style={{ backgroundColor: (ticket.column as any).color || '#94a3b8' }} />
                                {(ticket.column as any).name}
                            </Badge>
                        )}
                    </div>
                    <div className="flex items-center gap-1">
                        <Sheet open={detailsSheetOpen} onOpenChange={setDetailsSheetOpen}>
                            <SheetTrigger asChild>
                                <Button variant="ghost" size="icon" className="size-8 lg:hidden">
                                    <SlidersHorizontal className="size-4" />
                                </Button>
                            </SheetTrigger>
                            <SheetContent side="right" className="w-[300px] sm:w-[360px] p-0">
                                <div className="px-5 py-5 h-full overflow-y-auto">
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
                                        {/* Contact */}
                                        {ticket.contact && (
                                            <div className="flex items-center min-h-[40px] py-1">
                                                <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Contact</span>
                                                <div className="flex-1 flex items-center gap-2 px-2">
                                                    <Avatar className="size-5 shrink-0">
                                                        <AvatarFallback className="text-[10px]">
                                                            {ticket.contact.name?.charAt(0) || ticket.contact.email.charAt(0)}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    <div>
                                                        <p className="text-sm truncate">{ticket.contact.name || ticket.contact.email}</p>
                                                        {ticket.contact.name && (
                                                            <p className="text-xs text-muted-foreground truncate">{ticket.contact.email}</p>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                        {/* Created */}
                                        <div className="flex items-center min-h-[40px] py-1">
                                            <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Created</span>
                                            <span className="flex-1 text-sm px-2">{new Date(ticket.created_at).toLocaleDateString()}</span>
                                        </div>
                                        {/* Updated */}
                                        <div className="flex items-center min-h-[40px] py-1">
                                            <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Updated</span>
                                            <span className="flex-1 text-sm px-2">{new Date(ticket.updated_at).toLocaleDateString()}</span>
                                        </div>
                                        {/* Custom Fields */}
                                        {fields.map((field) => (
                                            <div key={field.id} className="flex items-center min-h-[40px] py-1">
                                                <span className="w-[40%] shrink-0 text-xs text-muted-foreground">{field.label}</span>
                                                <div className="flex-1 px-2 -ml-2">
                                                    <Input
                                                        value={fieldValues[field.name] || ''}
                                                        onChange={(e) => {
                                                            const newValues = { ...fieldValues, [field.name]: e.target.value };
                                                            setFieldValues(newValues);
                                                        }}
                                                        onBlur={() => {
                                                            // Save field value via API
                                                        }}
                                                        className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md"
                                                        placeholder={field.is_required ? 'Required' : 'Optional'}
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </SheetContent>
                        </Sheet>
                        <Button variant="ghost" size="icon" className="size-8 text-muted-foreground" onClick={onClose}>
                            <X className="size-4" />
                        </Button>
                    </div>
                </div>

                {/* Two-panel body */}
                <div className="flex overflow-hidden" style={{ maxHeight: 'calc(92vh - 57px)' }}>

                    {/* Main column */}
                    <div className="flex-1 overflow-y-auto min-w-0">
                        <div className="px-4 sm:px-8 py-6 space-y-0">

                            {/* Subject */}
                            <div className="mb-0">
                                {isEditingSubject ? (
                                    <div className="flex items-center gap-2">
                                        <Input
                                            ref={titleInputRef}
                                            value={subjectDraft}
                                            onChange={(e) => setSubjectDraft(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') saveTitle();
                                                if (e.key === 'Escape') cancelTitle();
                                            }}
                                            className="text-xl font-semibold border-0 border-b-2 border-primary rounded-none px-0 py-1 h-auto focus-visible:ring-0 bg-transparent flex-1 leading-snug"
                                            placeholder="Ticket subject"
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
                                            {subjectValue || <span className="text-muted-foreground font-normal">Ticket subject</span>}
                                        </h1>
                                        <Pencil className="size-3.5 text-muted-foreground/0 group-hover:text-muted-foreground/60 transition-colors shrink-0" />
                                    </div>
                                )}
                            </div>

                            {/* Description: customer message (read-only). Show when Message is enabled in settings OR ticket has content. */}
                            {(!((helpdesk.settings as Record<string, unknown>)?.['default_field_description'] as { hidden?: boolean } | undefined)?.hidden || (ticket?.description ?? '').trim()) && (
                            <section className="pt-3 pb-4 border-t border-border/60">
                                <h2 className="text-sm font-semibold mb-3">Message from customer</h2>
                                {description?.trim() ? (
                                    <div
                                        className={cn(
                                            'rounded-lg border bg-muted/30 p-4 text-sm',
                                            'prose prose-sm max-w-none dark:prose-invert prose-p:my-2 prose-ul:my-2 prose-ol:my-2 [&_a]:text-primary'
                                        )}
                                        dangerouslySetInnerHTML={safeHtmlProp(description)}
                                    />
                                ) : (
                                    <p className="text-sm text-muted-foreground italic">No message from customer.</p>
                                )}
                            </section>
                            )}

                            {/* Custom fields (form data from customer) */}
                            {fields && fields.length > 0 && (
                            <section className="pt-3 pb-4 border-t border-border/60">
                                <h2 className="text-sm font-semibold mb-3">Form data</h2>
                                <dl className="space-y-3">
                                    {fields.map((field) => {
                                        const fv = ticket?.field_values?.find((v) => v.helpdesk_field_id === field.id || v.field?.id === field.id);
                                        let displayValue: string = (fv?.value ?? '').trim() || '—';
                                        if (displayValue !== '—' && field.type === 'checkboxes') {
                                            try {
                                                const arr = JSON.parse(displayValue);
                                                displayValue = Array.isArray(arr) ? arr.join(', ') : displayValue;
                                            } catch {
                                                // keep as-is
                                            }
                                        }
                                        if (displayValue !== '—' && field.type === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(displayValue)) {
                                            displayValue = new Date(displayValue + 'T12:00:00').toLocaleDateString(undefined, { dateStyle: 'medium' });
                                        }
                                        if (displayValue !== '—' && field.type === 'checkbox') {
                                            displayValue = displayValue === '1' || displayValue.toLowerCase() === 'yes' ? 'Yes' : 'No';
                                        }
                                        const isRichText = field.type === 'rich_text';
                                        return (
                                            <div key={field.id} className="flex flex-col gap-0.5 sm:flex-row sm:items-baseline sm:gap-2">
                                                <dt className="text-sm font-medium text-muted-foreground shrink-0 sm:w-32">{field.label}</dt>
                                                <dd className={cn('text-sm text-foreground break-words', isRichText && 'prose prose-sm max-w-none [&_a]:text-primary')}>
                                                    {isRichText && displayValue !== '—' ? (
                                                        <span dangerouslySetInnerHTML={safeHtmlProp(displayValue)} />
                                                    ) : (
                                                        displayValue
                                                    )}
                                                </dd>
                                            </div>
                                        );
                                    })}
                                </dl>
                            </section>
                            )}

                            {/* Attachments (only when Attachments section is enabled in helpdesk settings) */}
                            {!((helpdesk.settings as Record<string, unknown>)?.['default_field_attachments'] as { hidden?: boolean } | undefined)?.hidden && (
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
                                                            {attachment.formatted_size || `${Math.round(attachment.size / 1024)} KB`}
                                                        </p>
                                                    </div>
                                                    <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                                        {canPreview && (
                                                            <Button variant="ghost" size="icon" className="size-7" title="Preview"
                                                                onClick={() => {}}>
                                                                <Eye className="size-3.5" />
                                                            </Button>
                                                        )}
                                                        <a href={attachment.url} target="_blank" rel="noopener noreferrer" download={attachment.original_filename}>
                                                            <Button variant="ghost" size="icon" className="size-7" title="Download"><Download className="size-3.5" /></Button>
                                                        </a>
                                                        <Button variant="ghost" size="icon" className="size-7 text-destructive hover:text-destructive">
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
                            )}

                            {/* Activity / Replies */}
                            <section className="py-5 border-t border-border/60">
                                <h2 className="text-sm font-semibold flex items-center gap-2 mb-5">
                                    <MessageSquare className="size-4 text-muted-foreground" />
                                    Activity
                                    {localReplies.length > 0 && <span className="text-xs font-normal text-muted-foreground">({localReplies.length})</span>}
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
                                                placeholder="Add a reply…"
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
                                                <Button size="sm" onClick={() => handlePostComment()}>Reply</Button>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Existing replies */}
                                {localReplies.length > 0 && (
                                    <div className="space-y-5">
                                        {localReplies.map((reply) => (
                                            <div key={reply.id} className="flex gap-3">
                                                <Avatar className="size-8 shrink-0">
                                                    <AvatarFallback className="text-xs">
                                                        {reply.user?.name?.charAt(0).toUpperCase() || (reply.contact?.name?.charAt(0).toUpperCase() || 'C')}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        <span className="text-sm font-medium">
                                                            {reply.user?.name || reply.contact?.name || (reply.contact?.email ? `Contact (${reply.contact.email})` : 'Unknown')}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {new Date(reply.created_at).toLocaleDateString()} at {new Date(reply.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                        </span>
                                                        {reply.is_internal_note && (
                                                            <Badge variant="outline" className="text-xs">Internal Note</Badge>
                                                        )}
                                                    </div>
                                                    <div
                                                        className="text-sm mt-1 prose prose-sm max-w-none"
                                                        dangerouslySetInnerHTML={safeHtmlProp(reply.body)}
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>

                        </div>
                    </div>

                    {/* Sidebar */}
                    <div className="hidden lg:block w-[300px] shrink-0 overflow-y-auto border-l bg-background">
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

                                {/* Contact */}
                                {ticket.contact && (
                                    <div className="flex items-center min-h-[40px] py-1">
                                        <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Contact</span>
                                        <div className="flex-1 flex items-center gap-2 px-2">
                                            <Avatar className="size-5 shrink-0">
                                                <AvatarFallback className="text-[10px]">
                                                    {ticket.contact.name?.charAt(0) || ticket.contact.email.charAt(0)}
                                                </AvatarFallback>
                                            </Avatar>
                                            <div>
                                                <p className="text-sm truncate">{ticket.contact.name || ticket.contact.email}</p>
                                                {ticket.contact.name && (
                                                    <p className="text-xs text-muted-foreground truncate">{ticket.contact.email}</p>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Created */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Created</span>
                                    <span className="flex-1 text-sm px-2">{new Date(ticket.created_at).toLocaleDateString()}</span>
                                </div>

                                {/* Updated */}
                                <div className="flex items-center min-h-[40px] py-1">
                                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground">Updated</span>
                                    <span className="flex-1 text-sm px-2">{new Date(ticket.updated_at).toLocaleDateString()}</span>
                                </div>

                                {/* Custom Fields */}
                                {fields.map((field) => (
                                    <div key={field.id} className="flex items-center min-h-[40px] py-1">
                                        <span className="w-[40%] shrink-0 text-xs text-muted-foreground">{field.label}</span>
                                        <div className="flex-1 px-2 -ml-2">
                                            <Input
                                                value={fieldValues[field.name] || ''}
                                                onChange={(e) => {
                                                    const newValues = { ...fieldValues, [field.name]: e.target.value };
                                                    setFieldValues(newValues);
                                                    // Save on blur
                                                }}
                                                onBlur={() => {
                                                    // Save field value via API
                                                }}
                                                className="h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md"
                                                placeholder={field.is_required ? 'Required' : 'Optional'}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

// Main Helpdesk Component
export default function HelpdesksShow({
    auth,
    workspace,
    project,
    helpdesk,
    priorities,
    projectUsers,
}: Props) {
    const [editingTicket, setEditingTicket] = useState<Ticket | null>(null);
    const [draggedTicket, setDraggedTicket] = useState<Ticket | null>(null);
    const [dragOverColumn, setDragOverColumn] = useState<number | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [currentView, setCurrentView] = useState<'kanban' | 'list'>('kanban');
    const [filterOpen, setFilterOpen] = useState(false);
    const [assigneeFilter, setAssigneeFilter] = useState<string>('any');
    const [statusFilter, setStatusFilter] = useState<number[]>([]);

    const currentUserId = auth.user?.id;

    const activeFiltersCount = useMemo(
        () =>
            (assigneeFilter !== 'any' ? 1 : 0) +
            (statusFilter.length ? 1 : 0),
        [assigneeFilter, statusFilter.length],
    );

    // Persist view preference per helpdesk
    useEffect(() => {
        if (typeof window === 'undefined') return;
        const key = `helpdesk-view-${project.id}-${helpdesk.id}`;
        const stored = window.localStorage.getItem(key) as 'kanban' | 'list' | null;
        if (stored && ['kanban', 'list'].includes(stored)) {
            setCurrentView(stored);
        }
    }, [project.id, helpdesk.id]);

    useEffect(() => {
        if (typeof window === 'undefined') return;
        const key = `helpdesk-view-${project.id}-${helpdesk.id}`;
        window.localStorage.setItem(key, currentView);
    }, [currentView, project.id, helpdesk.id]);

    const handleDragStart = (e: DragEvent, ticket: Ticket) => {
        setDraggedTicket(ticket);
        e.dataTransfer.effectAllowed = 'move';
        if (e.currentTarget instanceof HTMLElement) {
            e.currentTarget.style.opacity = '0.5';
        }
    };

    const handleDragEnd = () => {
        setDraggedTicket(null);
        setDragOverColumn(null);
    };

    const handleDragOver = (e: DragEvent, column: HelpdeskColumn) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        setDragOverColumn(column.id);
    };

    const handleDrop = (e: DragEvent, column: HelpdeskColumn) => {
        e.preventDefault();
        if (draggedTicket && draggedTicket.column_id !== column.id) {
            router.patch(
                route('tickets.move', [workspace.slug, project.slug, helpdesk.slug, draggedTicket.id]),
                {
                    column_id: column.id,
                    position: 0,
                },
                { preserveScroll: true }
            );
        }
        setDraggedTicket(null);
        setDragOverColumn(null);
    };

    // Filter tickets based on search + filter controls
    const filteredColumns = helpdesk.columns.map((col) => ({
        ...col,
        tickets:
            col.tickets?.filter((ticket) => {
                const matchesSearch = searchQuery
                    ? ticket.subject.toLowerCase().includes(searchQuery.toLowerCase()) ||
                    ticket.key.toLowerCase().includes(searchQuery.toLowerCase())
                    : true;
                if (!matchesSearch) return false;

                // Assignee filter
                if (assigneeFilter === 'me' && currentUserId) {
                    if (ticket.assigned_to !== currentUserId) return false;
                } else if (assigneeFilter === 'unassigned') {
                    if (ticket.assigned_to) return false;
                } else if (assigneeFilter.startsWith('user-')) {
                    const id = Number(assigneeFilter.split('-')[1] || '');
                    if (!id || ticket.assigned_to !== id) return false;
                }

                // Status filter (columns)
                if (statusFilter.length && !statusFilter.includes(col.id)) {
                    return false;
                }

                return true;
            }) || [],
    }));

    return (
        <AppLayout>
            <Head title={`${helpdesk.name} - ${project.name}`} />

            <div className="flex flex-col h-full overflow-hidden">
                {/* Helpdesk Header */}
                <div className="flex items-center justify-between px-6 py-3 border-b bg-background/95 backdrop-blur">
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-3">
                            <ProjectIcon project={project} size="md" className="shadow-sm" />
                            <div>
                                <h1 className="text-lg font-semibold tracking-tight">{helpdesk.name}</h1>
                                <p className="text-xs text-muted-foreground">{project.name} • Helpdesk</p>
                            </div>
                        </div>

                        {/* View Tabs */}
                        <div className="flex items-center gap-1 ml-4 p-1 bg-muted rounded-lg">
                            <button
                                onClick={() => setCurrentView('kanban')}
                                className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${currentView === 'kanban'
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                                    }`}
                            >
                                <Kanban className="size-4" />
                                Board
                            </button>
                            <button
                                onClick={() => setCurrentView('list')}
                                className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${currentView === 'list'
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                                    }`}
                            >
                                <List className="size-4" />
                                List
                            </button>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        {/* Search */}
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                            <Input
                                type="search"
                                placeholder="Search tickets"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="pl-9 h-9 w-64 bg-muted/50 border-0"
                            />
                        </div>

                        {/* Filter */}
                        <Popover open={filterOpen} onOpenChange={setFilterOpen}>
                            <PopoverTrigger asChild>
                                <Button variant="outline" size="sm" className="gap-2 h-9">
                                    <Filter className="size-4" />
                                    {activeFiltersCount ? `Filter (${activeFiltersCount})` : 'Filter'}
                                </Button>
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
                                                setStatusFilter([]);
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
                                    <Select value={assigneeFilter} onValueChange={setAssigneeFilter}>
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Any user" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="any">Any user</SelectItem>
                                            {currentUserId && (
                                                <SelectItem value="me">Me</SelectItem>
                                            )}
                                            <SelectItem value="unassigned">Unassigned</SelectItem>
                                            {projectUsers.map((user) => (
                                                <SelectItem key={user.id} value={`user-${user.id}`}>
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

                                {/* Status / Columns */}
                                {helpdesk.columns.length > 0 && (
                                    <div className="space-y-2">
                                        <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">
                                            Status
                                        </p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {helpdesk.columns.map((col) => {
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
                            </PopoverContent>
                        </Popover>

                        {/* Helpdesk Settings */}
                        <Link href={route('helpdesks.edit', [workspace.slug, project.slug, helpdesk.slug])}>
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
                                <HelpdeskColumnComponent
                                    key={column.id}
                                    column={column}
                                    workspace={workspace}
                                    project={project}
                                    helpdesk={helpdesk}
                                    fields={helpdesk.fields}
                                    onEditTicket={setEditingTicket}
                                    onDragStart={handleDragStart}
                                    onDragEnd={handleDragEnd}
                                    onDragOver={handleDragOver}
                                    onDrop={handleDrop}
                                    isDragOver={dragOverColumn === column.id}
                                />
                            ))}

                            {/* Add Column Button */}
                            <div className="flex-shrink-0 w-[240px] sm:w-[280px]">
                                <Link href={route('helpdesks.edit', [workspace.slug, project.slug, helpdesk.slug])}>
                                    <Button
                                        variant="ghost"
                                        className="w-full h-12 border-2 border-dashed border-muted-foreground/20 hover:border-muted-foreground/40 text-muted-foreground gap-2"
                                    >
                                        <Plus className="size-4" />
                                        <span className="hidden sm:inline">Add Column</span>
                                    </Button>
                                </Link>
                            </div>
                        </div>
                    </div>
                )}

                {currentView === 'list' && (
                    <div className="flex-1 overflow-auto bg-background">
                        <div className="p-6">
                            <table className="w-full">
                                <thead>
                                    <tr className="border-b text-left text-sm text-muted-foreground">
                                        <th className="pb-3 pl-4 font-medium">Key</th>
                                        <th className="pb-3 font-medium">Subject</th>
                                        <th className="pb-3 font-medium">Contact</th>
                                        <th className="pb-3 font-medium">Status</th>
                                        <th className="pb-3 font-medium">Priority</th>
                                        <th className="pb-3 font-medium">Assignee</th>
                                        <th className="pb-3 font-medium">Created</th>
                                        <th className="pb-3 pr-4 font-medium">Replies</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredColumns.flatMap(col => col.tickets).map((ticket) => (
                                        <tr
                                            key={ticket.id}
                                            className="border-b hover:bg-muted/50 cursor-pointer transition-colors"
                                            onClick={() => setEditingTicket(ticket)}
                                        >
                                            <td className="py-3 pl-4">
                                                <span className="font-mono text-sm text-primary font-medium">{ticket.key}</span>
                                            </td>
                                            <td className="py-3">
                                                <span className="font-medium">{ticket.subject}</span>
                                            </td>
                                            <td className="py-3">
                                                {ticket.contact ? (
                                                    <div className="flex items-center gap-2">
                                                        <Avatar className="size-5">
                                                            <AvatarFallback className="text-xs">
                                                                {ticket.contact.name?.charAt(0) || ticket.contact.email.charAt(0)}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <div>
                                                            <p className="text-sm">{ticket.contact.name || ticket.contact.email}</p>
                                                            {ticket.contact.name && (
                                                                <p className="text-xs text-muted-foreground">{ticket.contact.email}</p>
                                                            )}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">—</span>
                                                )}
                                            </td>
                                            <td className="py-3">
                                                <Badge variant="secondary" className="font-normal">
                                                    {ticket.column?.name || 'Unknown'}
                                                </Badge>
                                            </td>
                                            <td className="py-3">
                                                <div className="flex items-center gap-2">
                                                    <PriorityIcon slug={ticket.priority?.slug} />
                                                    <span className="text-sm">{ticket.priority?.name || 'None'}</span>
                                                </div>
                                            </td>
                                            <td className="py-3">
                                                {ticket.assignee ? (
                                                    <div className="flex items-center gap-2">
                                                        <Avatar className="size-5">
                                                            {ticket.assignee.avatar_display && <AvatarImage src={ticket.assignee.avatar_display} alt={ticket.assignee.name} />}
                                                            <AvatarFallback className="text-xs">{ticket.assignee.name.charAt(0).toUpperCase()}</AvatarFallback>
                                                        </Avatar>
                                                        <span className="text-sm">{ticket.assignee.name}</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">Unassigned</span>
                                                )}
                                            </td>
                                            <td className="py-3">
                                                <span className="text-sm">{new Date(ticket.created_at).toLocaleDateString()}</span>
                                            </td>
                                            <td className="py-3 pr-4">
                                                <div className="flex items-center gap-1 text-sm">
                                                    <MessageSquare className="size-3.5 text-muted-foreground" />
                                                    <span>{ticket.replies?.length || 0}</span>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {filteredColumns.flatMap(col => col.tickets).length === 0 && (
                                        <tr>
                                            <td colSpan={8} className="py-12 text-center text-muted-foreground">
                                                No tickets found
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>

            {/* Ticket Edit Modal */}
            <TicketEditModal
                key={editingTicket?.id}
                ticket={editingTicket}
                workspace={workspace}
                project={project}
                helpdesk={helpdesk}
                priorities={priorities}
                projectUsers={projectUsers}
                columns={helpdesk.columns}
                fields={helpdesk.fields}
                open={!!editingTicket}
                onClose={() => setEditingTicket(null)}
            />
        </AppLayout>
    );
}