import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm, Link } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, BoardColumn, Item, ItemType, ItemPriority, User, Label as LabelType, ItemLink } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Separator } from '@/components/ui/separator';
import { Badge } from '@/components/ui/badge';
import { ProjectIcon } from '@/components/ProjectIcon';
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
    Save,
    Link2,
    Plus,
    X,
    MoveRight,
} from 'lucide-react';
import { useState, useEffect, useCallback } from 'react';
import { EditorJSComponent } from '@/components/editor/EditorJS';
import { OutputData } from '@editorjs/editorjs';

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
    // Parse description - it can be either JSON (EditorJS) or plain text (legacy)
    const parseDescription = useCallback((desc: string | null | undefined): OutputData | null => {
        if (!desc) return null;
        try {
            const parsed = JSON.parse(desc);
            if (parsed && typeof parsed === 'object' && Array.isArray(parsed.blocks)) {
                return parsed as OutputData;
            }
            // If it's valid JSON but not EditorJS format, treat as plain text
            return null;
        } catch {
            // Not JSON, treat as plain text - convert to EditorJS format
            if (desc.trim()) {
                return {
                    time: Date.now(),
                    blocks: [{ type: 'paragraph', data: { text: desc } }],
                    version: '2.31.0',
                };
            }
            return null;
        }
    }, []);

    const [editorContent, setEditorContent] = useState<OutputData | null>(() => parseDescription(item.description));

    const { data, setData, patch, processing, isDirty } = useForm({
        title: item.title || '',
        description: item.description || '',
        item_type_id: item.item_type_id?.toString() || '',
        priority_id: item.priority_id?.toString() || '',
        assigned_to: item.assigned_to?.toString() || '',
        column_id: item.column_id?.toString() || '',
        story_points: item.story_points?.toString() || '',
        due_date: item.due_date || '',
    });

    const handleEditorChange = useCallback((content: OutputData) => {
        setEditorContent(content);
        setData('description', JSON.stringify(content));
    }, [setData]);

    const handleSave = () => {
        patch(route('boards.items.update', [workspace.slug, project.slug, board.slug, item.id]));
    };

    const handleMoveToColumn = (columnId: string) => {
        router.patch(route('boards.items.move', [workspace.slug, project.slug, board.slug, item.id]), {
            column_id: parseInt(columnId),
            position: 0,
        }, { preserveScroll: true });
        setData('column_id', columnId);
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
    const [linkDialogOpen, setLinkDialogOpen] = useState(false);
    const [linkType, setLinkType] = useState<string>('relates_to');
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

    return (
        <AppLayout>
            <Head title={`${item.key} - ${item.title}`} />

            <div className="min-h-full bg-background">
                {/* Header */}
                <div className="border-b bg-background/95 backdrop-blur sticky top-0 z-10">
                    <div className="max-w-6xl mx-auto px-6 py-4">
                        <div className="flex items-center justify-between">
                            {/* Breadcrumbs and Back */}
                            <div className="flex items-center gap-4">
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

                            {/* Actions */}
                            <div className="flex items-center gap-2">
                                {isDirty && (
                                    <Button onClick={handleSave} disabled={processing} className="gap-2">
                                        <Save className="size-4" />
                                        {processing ? 'Saving...' : 'Save Changes'}
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Content */}
                <div className="max-w-6xl mx-auto px-6 py-8">
                    <div className="flex gap-8">
                        {/* Main Content */}
                        <div className="flex-1 space-y-8">
                            {/* Key and Title */}
                            <div className="space-y-4">
                                <div className="flex items-center gap-3">
                                    <ItemTypeIcon slug={item.item_type?.slug} className="size-6" />
                                    <span className="font-mono text-lg font-semibold text-primary">{item.key}</span>
                                </div>
                                <Input
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    className="text-2xl font-bold border-0 p-0 h-auto focus-visible:ring-0 bg-transparent"
                                    placeholder="Issue title"
                                />
                            </div>

                            {/* Description */}
                            <div className="space-y-3">
                                <Label className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">
                                    Description
                                </Label>
                                <div className="border rounded-lg min-h-[200px] bg-background">
                                    <EditorJSComponent
                                        content={editorContent}
                                        onChange={handleEditorChange}
                                        placeholder="Add a description to help others understand this item..."
                                        editable={true}
                                    />
                                </div>
                            </div>

                            {/* Linked Issues Section */}
                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <Link2 className="size-5 text-muted-foreground" />
                                        <h3 className="font-semibold text-lg">Linked Issues</h3>
                                    </div>
                                    <Dialog open={linkDialogOpen} onOpenChange={setLinkDialogOpen}>
                                        <DialogTrigger asChild>
                                            <Button variant="outline" size="sm" className="gap-1">
                                                <Plus className="size-4" />
                                                Link Issue
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>Link Issue</DialogTitle>
                                                <DialogDescription>
                                                    Create a link between this issue and another.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <div className="space-y-4 pt-4">
                                                <div className="space-y-2">
                                                    <Label>Link Type</Label>
                                                    <Select value={linkType} onValueChange={setLinkType}>
                                                        <SelectTrigger>
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {linkTypeOptions.map((opt) => (
                                                                <SelectItem key={opt.value} value={opt.value}>
                                                                    This issue {opt.label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Target Issue</Label>
                                                    <Select value={targetItemId} onValueChange={setTargetItemId}>
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select an issue..." />
                                                        </SelectTrigger>
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
                                                    <Button variant="outline" onClick={() => setLinkDialogOpen(false)}>
                                                        Cancel
                                                    </Button>
                                                    <Button onClick={handleCreateLink} disabled={!targetItemId}>
                                                        Link Issue
                                                    </Button>
                                                </div>
                                            </div>
                                        </DialogContent>
                                    </Dialog>
                                </div>

                                <Separator />

                                {/* Display existing links */}
                                <div className="space-y-2">
                                    {(item.outgoing_links?.length || 0) + (item.incoming_links?.length || 0) === 0 ? (
                                        <p className="text-sm text-muted-foreground text-center py-4">
                                            No linked issues yet
                                        </p>
                                    ) : (
                                        <>
                                            {item.outgoing_links?.map((link) => (
                                                <div
                                                    key={`out-${link.id}`}
                                                    className="flex items-center justify-between p-3 rounded-lg border bg-muted/20 hover:bg-muted/40 transition-colors"
                                                >
                                                    <div className="flex items-center gap-2 min-w-0">
                                                        <Badge variant="outline" className="shrink-0 text-xs">
                                                            {linkTypeOptions.find(o => o.value === link.link_type)?.label || link.link_type}
                                                        </Badge>
                                                        <Link
                                                            href={route('boards.items.show', [
                                                                workspace.slug,
                                                                project.slug,
                                                                link.target_item?.board?.slug,
                                                                link.target_item_id,
                                                            ])}
                                                            className="text-sm font-medium text-primary hover:underline truncate"
                                                        >
                                                            {link.target_item?.key} - {link.target_item?.title}
                                                        </Link>
                                                    </div>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-7 shrink-0"
                                                        onClick={() => handleDeleteLink(link.id)}
                                                    >
                                                        <X className="size-4" />
                                                    </Button>
                                                </div>
                                            ))}
                                            {item.incoming_links?.map((link) => (
                                                <div
                                                    key={`in-${link.id}`}
                                                    className="flex items-center justify-between p-3 rounded-lg border bg-muted/20 hover:bg-muted/40 transition-colors"
                                                >
                                                    <div className="flex items-center gap-2 min-w-0">
                                                        <Badge variant="secondary" className="shrink-0 text-xs">
                                                            {linkTypeOptions.find(o => o.value === (
                                                                link.link_type === 'blocks' ? 'is_blocked_by' :
                                                                link.link_type === 'is_blocked_by' ? 'blocks' :
                                                                link.link_type === 'duplicates' ? 'is_duplicated_by' :
                                                                link.link_type === 'is_duplicated_by' ? 'duplicates' :
                                                                link.link_type === 'clones' ? 'is_cloned_by' :
                                                                link.link_type === 'is_cloned_by' ? 'clones' :
                                                                link.link_type
                                                            ))?.label || link.link_type}
                                                        </Badge>
                                                        <Link
                                                            href={route('boards.items.show', [
                                                                workspace.slug,
                                                                project.slug,
                                                                link.source_item?.board?.slug,
                                                                link.source_item_id,
                                                            ])}
                                                            className="text-sm font-medium text-primary hover:underline truncate"
                                                        >
                                                            {link.source_item?.key} - {link.source_item?.title}
                                                        </Link>
                                                    </div>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-7 shrink-0"
                                                        onClick={() => handleDeleteLink(link.id)}
                                                    >
                                                        <X className="size-4" />
                                                    </Button>
                                                </div>
                                            ))}
                                        </>
                                    )}
                                </div>
                            </div>

                            {/* Activity Section */}
                            <div className="space-y-4">
                                <div className="flex items-center gap-2">
                                    <Clock className="size-5 text-muted-foreground" />
                                    <h3 className="font-semibold text-lg">Activity</h3>
                                </div>

                                <Separator />

                                {/* Comments */}
                                <div className="space-y-4">
                                    <div className="flex items-center gap-2 text-muted-foreground">
                                        <MessageSquare className="size-4" />
                                        <span className="text-sm font-medium">Comments</span>
                                    </div>

                                    <div className="flex gap-4">
                                        <Avatar className="size-10">
                                            <AvatarFallback className="bg-primary text-primary-foreground">
                                                U
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="flex-1 space-y-3">
                                            <Textarea
                                                placeholder="Add a comment..."
                                                className="min-h-[100px] resize-none"
                                            />
                                            <div className="flex justify-end">
                                                <Button disabled>
                                                    Post Comment
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Sidebar */}
                        <div className="w-80 space-y-6">
                            <div className="bg-muted/30 rounded-xl p-6 space-y-5 border">
                                {/* Status */}
                                <div className="space-y-2">
                                    <Label className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                                        Status
                                    </Label>
                                    <Select value={data.column_id} onValueChange={handleMoveToColumn}>
                                        <SelectTrigger className="h-10">
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
                                    <Label className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                                        Assignee
                                    </Label>
                                    <Select value={data.assigned_to || 'unassigned'} onValueChange={(v) => setData('assigned_to', v === 'unassigned' ? '' : v)}>
                                        <SelectTrigger className="h-10">
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
                                    <Label className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                                        Type
                                    </Label>
                                    <Select value={data.item_type_id} onValueChange={(v) => setData('item_type_id', v)}>
                                        <SelectTrigger className="h-10">
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
                                    <Label className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                                        Priority
                                    </Label>
                                    <Select value={data.priority_id} onValueChange={(v) => setData('priority_id', v)}>
                                        <SelectTrigger className="h-10">
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
                                    <Label className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                                        Labels
                                    </Label>
                                    <div className="flex flex-wrap gap-1.5">
                                        {projectLabels.length === 0 ? (
                                            <p className="text-xs text-muted-foreground">No labels available</p>
                                        ) : (
                                            projectLabels.map((label) => (
                                                <Badge
                                                    key={label.id}
                                                    variant={selectedLabels.includes(label.id) ? "default" : "outline"}
                                                    className="cursor-pointer text-xs transition-all"
                                                    style={{
                                                        backgroundColor: selectedLabels.includes(label.id) ? label.color : 'transparent',
                                                        borderColor: label.color,
                                                        color: selectedLabels.includes(label.id) ? '#fff' : label.color,
                                                    }}
                                                    onClick={() => toggleLabel(label.id)}
                                                >
                                                    {label.name}
                                                </Badge>
                                            ))
                                        )}
                                    </div>
                                </div>

                                <Separator />

                                {/* Story Points */}
                                <div className="space-y-2">
                                    <Label className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                                        Story Points
                                    </Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        value={data.story_points}
                                        onChange={(e) => setData('story_points', e.target.value)}
                                        className="h-10"
                                        placeholder="0"
                                    />
                                </div>

                                {/* Due Date */}
                                <div className="space-y-2">
                                    <Label className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                                        Due Date
                                    </Label>
                                    <Input
                                        type="date"
                                        value={data.due_date}
                                        onChange={(e) => setData('due_date', e.target.value)}
                                        className="h-10"
                                    />
                                </div>
                            </div>

                            {/* Move to Another Board */}
                            <Dialog open={moveDialogOpen} onOpenChange={setMoveDialogOpen}>
                                <DialogTrigger asChild>
                                    <Button variant="outline" className="w-full gap-2">
                                        <MoveRight className="size-4" />
                                        Move to Another Board
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Move Issue</DialogTitle>
                                        <DialogDescription>
                                            Move this issue to a different board within the project.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="space-y-4 pt-4">
                                        <div className="space-y-2">
                                            <Label>Target Board</Label>
                                            <Select value={targetBoardId} onValueChange={(v) => { setTargetBoardId(v); setTargetColumnId(''); }}>
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select a board..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {projectBoards.filter(b => b.id !== board.id).map((b) => (
                                                        <SelectItem key={b.id} value={b.id.toString()}>
                                                            {b.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        {targetBoardId && targetBoardColumns.length > 0 && (
                                            <div className="space-y-2">
                                                <Label>Target Column</Label>
                                                <Select value={targetColumnId} onValueChange={setTargetColumnId}>
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="First column (default)" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {targetBoardColumns.map((col) => (
                                                            <SelectItem key={col.id} value={col.id.toString()}>
                                                                {col.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}
                                        <div className="flex justify-end gap-2">
                                            <Button variant="outline" onClick={() => setMoveDialogOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button onClick={handleMoveToBoard} disabled={!targetBoardId}>
                                                Move Issue
                                            </Button>
                                        </div>
                                    </div>
                                </DialogContent>
                            </Dialog>

                            {/* Meta info */}
                            <div className="text-sm text-muted-foreground space-y-2 px-2">
                                {item.reporter && (
                                    <div className="flex items-center justify-between">
                                        <span>Reporter</span>
                                        <span className="font-medium text-foreground">{item.reporter.name}</span>
                                    </div>
                                )}
                                <div className="flex items-center justify-between">
                                    <span>Created</span>
                                    <span>{new Date(item.created_at).toLocaleDateString()}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span>Updated</span>
                                    <span>{new Date(item.updated_at).toLocaleDateString()}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
