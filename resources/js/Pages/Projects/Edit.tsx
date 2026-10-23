import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { ProjectIcon } from '@/components/ProjectIcon';
import MoveProjectCard from '@/components/projects/MoveProjectCard';
import GithubIntegrationCard, { type GithubSettings } from '@/components/projects/GithubIntegrationCard';
import { PageProps, Workspace, Project, Label as LabelType, User, ItemType } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { EmojiMartIconPicker } from '@/components/EmojiMartIconPicker';
import { ItemTypeIcon } from '@/components/ItemTypeIcon';
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
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ArrowLeft, Save, Plus, Pencil, Trash2, Tag, Users, ListTodo, Power, PowerOff, Settings, Shield, Plug } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export type ProjectMemberRole = 'admin' | 'editor' | 'viewer';

interface ProjectMember extends User {
    pivot: { role: ProjectMemberRole };
}

type ProjectItemType = ItemType & { project_id?: number | null; description?: string | null; is_default?: boolean; is_active?: boolean };

interface Props extends PageProps {
    workspace: Workspace;
    project: Project & { labels?: LabelType[]; users?: ProjectMember[] };
    workspaceUsers: User[];
    canManageMembers: boolean;
    projectItemTypes?: ProjectItemType[];
    /** Workspaces the user also administers (empty when they can't move this project). */
    moveTargets?: { id: number; name: string; slug: string }[];
    github?: GithubSettings | null;
}

// Predefined colors for labels and project
const LABEL_COLORS = [
    '#ef4444', '#f97316', '#f59e0b', '#84cc16', '#22c55e', '#14b8a6',
    '#06b6d4', '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#ec4899', '#64748b',
];

const PROJECT_COLORS = [
    '#6366f1', '#3b82f6', '#06b6d4', '#14b8a6', '#22c55e', '#84cc16',
    '#f59e0b', '#f97316', '#ef4444', '#ec4899', '#a855f7', '#64748b',
];

function LabelForm({
    mode,
    label,
    workspace,
    project,
    onClose,
}: {
    mode: 'create' | 'edit';
    label?: LabelType;
    workspace: Workspace;
    project: Project;
    onClose: () => void;
}) {
    const { data, setData, post, put, processing, reset, errors } = useForm({
        name: label?.name || '',
        color: label?.color || LABEL_COLORS[0],
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (mode === 'create') {
            post(route('projects.labels.store', [workspace.slug, project.slug]), {
                onSuccess: () => {
                    reset();
                    onClose();
                },
            });
        } else if (label) {
            put(route('projects.labels.update', [workspace.slug, project.slug, label.id]), {
                onSuccess: () => onClose(),
            });
        }
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
                <Label htmlFor="label-name">Label Name</Label>
                <Input
                    id="label-name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="e.g., Bug, Feature, Urgent"
                    required
                />
                {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
            </div>
            <div className="space-y-2">
                <Label>Color</Label>
                <div className="flex flex-wrap gap-2">
                    {LABEL_COLORS.map((color) => (
                        <button
                            key={color}
                            type="button"
                            className={`w-8 h-8 rounded-full transition-all ${
                                data.color === color
                                    ? 'ring-2 ring-offset-2 ring-primary scale-110'
                                    : 'hover:scale-105'
                            }`}
                            style={{ backgroundColor: color }}
                            onClick={() => setData('color', color)}
                        />
                    ))}
                </div>
                {errors.color && <p className="text-sm text-destructive">{errors.color}</p>}
            </div>
            <div className="flex items-center gap-2 pt-2">
                <Badge
                    style={{ backgroundColor: data.color, color: '#fff' }}
                    className="px-3 py-1"
                >
                    {data.name || 'Preview'}
                </Badge>
            </div>
            <div className="flex justify-end gap-2 pt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing ? 'Saving...' : mode === 'create' ? 'Create Label' : 'Save Changes'}
                </Button>
            </div>
        </form>
    );
}

const PROJECT_ROLES: { value: ProjectMemberRole; label: string }[] = [
    { value: 'admin', label: 'Admin' },
    { value: 'editor', label: 'Editor' },
    { value: 'viewer', label: 'Viewer' },
];

function ItemTypeForm({
    mode,
    itemType,
    workspace,
    project,
    onClose,
}: {
    mode: 'create' | 'edit';
    itemType?: ProjectItemType;
    workspace: Workspace;
    project: Project;
    onClose: () => void;
}) {
    const { data, setData, post, put, processing, reset, errors } = useForm({
        name: itemType?.name || '',
        color: itemType?.color || LABEL_COLORS[0],
        icon: itemType?.icon || '',
        description: itemType?.description || '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (mode === 'create') {
            post(route('projects.item-types.store', [workspace.slug, project.slug]), {
                onSuccess: () => {
                    reset();
                    onClose();
                },
            });
        } else if (itemType) {
            put(route('projects.item-types.update', [workspace.slug, project.slug, itemType.id]), {
                onSuccess: () => onClose(),
            });
        }
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
                <Label htmlFor="itype-name">Type name</Label>
                <Input
                    id="itype-name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="e.g. Task, Bug, Story"
                    required
                />
                {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
            </div>
            <div className="space-y-2">
                <Label>Color</Label>
                <div className="flex flex-wrap gap-2">
                    {LABEL_COLORS.map((color) => (
                        <button
                            key={color}
                            type="button"
                            className={`w-8 h-8 rounded-full transition-all ${
                                data.color === color ? 'ring-2 ring-offset-2 ring-primary scale-110' : 'hover:scale-105'
                            }`}
                            style={{ backgroundColor: color }}
                            onClick={() => setData('color', color)}
                        />
                    ))}
                </div>
            </div>
            <div className="space-y-2">
                <Label>Icon (optional)</Label>
                <EmojiMartIconPicker
                    value={
                        data.icon?.includes('/') && !data.icon.startsWith('http') && !data.icon.startsWith('/')
                            ? `${typeof window !== 'undefined' ? window.location.origin : ''}/storage/${data.icon}`
                            : (data.icon || '')
                    }
                    onChange={(v: string) => setData('icon', v)}
                    placeholderEmoji="📋"
                    size="sm"
                    onImageUpload={async (file: File) => {
                        const formData = new FormData();
                        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
                        if (token) formData.append('_token', token);
                        formData.append('icon', file);
                        const res = await fetch(route('projects.item-types.upload-icon', [workspace.slug, project.slug]), {
                            method: 'POST',
                            body: formData,
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        const json = await res.json().catch(() => ({}));
                        if (!res.ok) throw new Error(json.message ?? 'Icon upload failed');
                        return json.icon;
                    }}
                />
            </div>
            <div className="space-y-2">
                <Label htmlFor="itype-desc">Description (optional)</Label>
                <Input
                    id="itype-desc"
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                    placeholder="Short description"
                />
            </div>
            <div className="flex justify-end gap-2 pt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing ? 'Saving...' : mode === 'create' ? 'Create type' : 'Save changes'}
                </Button>
            </div>
        </form>
    );
}

export default function ProjectsEdit({ auth, workspace, project, workspaceUsers, canManageMembers, projectItemTypes = [], moveTargets = [], github = null }: Props) {
    // Deep links like ?tab=integrations (from an issue's Development section).
    const initialTab = (typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tab')) || 'general';
    const [createLabelOpen, setCreateLabelOpen] = useState(false);
    const [editingLabel, setEditingLabel] = useState<LabelType | null>(null);
    const [createItemTypeOpen, setCreateItemTypeOpen] = useState(false);
    const [editingItemType, setEditingItemType] = useState<ProjectItemType | null>(null);
    const [addMemberUserId, setAddMemberUserId] = useState<string>('');
    const [addMemberRole, setAddMemberRole] = useState<ProjectMemberRole>('viewer');

    const { data, setData, put, processing, errors, isDirty } = useForm({
        name: project.name || '',
        description: project.description || '',
        key: project.key || '',
        icon: project.icon_display ?? project.icon ?? '',
        color: project.color ?? '',
    });

    const handleSaveProject = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('projects.update', [workspace.slug, project.slug]));
    };

    const handleProjectIconUpload = async (file: File): Promise<string> => {
        const formData = new FormData();
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (token) formData.append('_token', token);
        formData.append('icon', file);
        const res = await fetch(route('projects.icon.upload', [workspace.slug, project.slug]), {
            method: 'POST',
            body: formData,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            toast.error(data.message ?? 'Icon upload failed');
            throw new Error(data.message ?? 'Upload failed');
        }
        return data.icon_display;
    };

    const handleDeleteLabel = (labelId: number) => {
        router.delete(route('projects.labels.destroy', [workspace.slug, project.slug, labelId]));
    };

    const projectMemberIds = new Set((project.users ?? []).map((u) => u.id));
    const availableToAdd = workspaceUsers.filter((u) => !projectMemberIds.has(u.id));

    const handleAddMember = (e: React.FormEvent) => {
        e.preventDefault();
        if (!addMemberUserId) return;
        router.post(route('projects.members.store', [workspace.slug, project.slug]), {
            user_id: parseInt(addMemberUserId, 10),
            role: addMemberRole,
        }, { preserveScroll: true, onSuccess: () => { setAddMemberUserId(''); setAddMemberRole('viewer'); } });
    };

    const handleUpdateMemberRole = (userId: number, role: ProjectMemberRole) => {
        router.patch(route('projects.members.update', [workspace.slug, project.slug, userId]), { role }, { preserveScroll: true });
    };

    const handleRemoveMember = (userId: number) => {
        router.delete(route('projects.members.destroy', [workspace.slug, project.slug, userId]), { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={`Settings - ${project.name}`} />

            <div className="min-h-full bg-background">
                {/* Header */}
                <div className="border-b bg-background/95 backdrop-blur sticky top-0 z-10">
                    <div className="max-w-4xl mx-auto px-6 py-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-4">
                                <Link href={route('projects.show', [workspace.slug, project.slug])}>
                                    <Button variant="ghost" size="icon" className="size-8">
                                        <ArrowLeft className="size-4" />
                                    </Button>
                                </Link>
                                <div>
                                    <h1 className="text-xl font-semibold">Project Settings</h1>
                                    <p className="text-sm text-muted-foreground flex items-center gap-2">
                                        <ProjectIcon project={project} size="xs" />
                                        {project.name}
                                    </p>
                                </div>
                            </div>
                            {isDirty && (
                                <Button onClick={handleSaveProject} disabled={processing} className="gap-2">
                                    <Save className="size-4" />
                                    {processing ? 'Saving...' : 'Save Changes'}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                {/* Content */}
                <div className="max-w-4xl mx-auto px-6 py-8">
                    <Tabs defaultValue={initialTab} orientation="vertical" className="space-y-0">
                        <div className="flex flex-col gap-8 lg:flex-row lg:items-start">
                            <aside className="w-full lg:w-52 lg:shrink-0 lg:sticky lg:top-4">
                                <div className="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Settings</div>
                                <TabsList className="flex h-auto w-full flex-row gap-1 overflow-x-auto bg-transparent p-0 lg:flex-col">
                                    <TabsTrigger value="general" className="w-full justify-start gap-2 px-3 h-9 data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none">
                                        <Settings className="size-4" />General
                                    </TabsTrigger>
                                    <TabsTrigger value="labels" className="w-full justify-start gap-2 px-3 h-9 data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none">
                                        <Tag className="size-4" />Labels
                                    </TabsTrigger>
                                    <TabsTrigger value="item-types" className="w-full justify-start gap-2 px-3 h-9 data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none">
                                        <ListTodo className="size-4" />Item types
                                    </TabsTrigger>
                                    <TabsTrigger value="members" className="w-full justify-start gap-2 px-3 h-9 data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none">
                                        <Users className="size-4" />Members
                                    </TabsTrigger>
                                    <TabsTrigger value="integrations" className="w-full justify-start gap-2 px-3 h-9 data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none">
                                        <Plug className="size-4" />Integrations
                                    </TabsTrigger>
                                    <TabsTrigger value="danger" className="w-full justify-start gap-2 px-3 h-9 text-destructive data-[state=active]:bg-destructive/10 data-[state=active]:text-destructive data-[state=active]:shadow-none">
                                        <Shield className="size-4" />Danger zone
                                    </TabsTrigger>
                                </TabsList>
                            </aside>
                            <div className="min-w-0 flex-1 space-y-6">

                        <TabsContent value="general" className="space-y-8">
                    {/* General Settings */}
                    <Card>
                        <CardHeader>
                            <CardTitle>General</CardTitle>
                            <CardDescription>Basic project information, icon, and color</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSaveProject} className="space-y-5">
                                <div className="space-y-2">
                                    <div className="flex items-baseline gap-4">
                                        <Label className="w-10 shrink-0">Icon</Label>
                                        <Label htmlFor="name">Project name</Label>
                                    </div>
                                    <div className="flex items-center gap-4 flex-nowrap">
                                        <div className="w-10 shrink-0">
                                            <EmojiMartIconPicker
                                                value={data.icon}
                                                onChange={(v: string) => setData('icon', v)}
                                                placeholderEmoji="📁"
                                                onImageUpload={handleProjectIconUpload}
                                                size="sm"
                                            />
                                        </div>
                                        <div className="flex-1 min-w-0 space-y-2">
                                            <Input
                                                id="name"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                placeholder="Project name"
                                                required
                                                className="h-10"
                                            />
                                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                                        </div>
                                    </div>
                                </div>
                                <div className="space-y-2 max-w-xs">
                                    <Label htmlFor="key">Project key</Label>
                                    <Input
                                        id="key"
                                        value={data.key}
                                        onChange={(e) => setData('key', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''))}
                                        placeholder="e.g. PROJ"
                                        maxLength={10}
                                        className="font-mono"
                                    />
                                    <p className="text-xs text-muted-foreground">Short code for the project (e.g. PROJ, DEV). Unique per workspace.</p>
                                    {errors.key && <p className="text-sm text-destructive">{errors.key}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Color</Label>
                                    <div className="flex flex-wrap gap-2">
                                        {PROJECT_COLORS.map((color) => (
                                            <button
                                                key={color}
                                                type="button"
                                                className={`w-8 h-8 rounded-full transition-all ${
                                                    (data.color || '#6366f1') === color
                                                        ? 'ring-2 ring-offset-2 ring-primary scale-110'
                                                        : 'hover:scale-105'
                                                }`}
                                                style={{ backgroundColor: color }}
                                                onClick={() => setData('color', color)}
                                                title={color}
                                            />
                                        ))}
                                    </div>
                                    {errors.color && <p className="text-sm text-destructive">{errors.color}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="project-description">Description</Label>
                                    <RichLexicalEditor
                                        content={
                                            (() => {
                                                const d = data.description || '';
                                                if (!d) return '';
                                                if (d.trim().startsWith('<')) return d;
                                                const escaped = d.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                                                return `<p>${escaped}</p>`;
                                            })()
                                        }
                                        onChange={(html) => setData('description', html)}
                                        editable={true}
                                        showToolbar={true}
                                        placeholder="What is this project about? Type / for commands..."
                                        minHeight="200px"
                                        contentKey={`project-description-${project.id}`}
                                        workspaceSlug={workspace.slug}
                                        projectId={project.id}
                                        projectName={project.name}
                                    />
                                    {errors.description && (
                                        <p className="text-sm text-destructive">{errors.description}</p>
                                    )}
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                        </TabsContent>

                        <TabsContent value="labels" className="space-y-8">
                    {/* Labels */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <div className="space-y-1">
                                <CardTitle className="flex items-center gap-2">
                                    <Tag className="size-5" />
                                    Labels
                                </CardTitle>
                                <CardDescription>
                                    Labels help categorize and filter items across your project
                                </CardDescription>
                            </div>
                            <Dialog open={createLabelOpen} onOpenChange={setCreateLabelOpen}>
                                <DialogTrigger asChild>
                                    <Button variant="outline" size="sm" className="gap-2">
                                        <Plus className="size-4" />
                                        Add Label
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Create Label</DialogTitle>
                                        <DialogDescription>
                                            Create a new label to categorize items in your project.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <LabelForm
                                        mode="create"
                                        workspace={workspace}
                                        project={project}
                                        onClose={() => setCreateLabelOpen(false)}
                                    />
                                </DialogContent>
                            </Dialog>
                        </CardHeader>
                        <CardContent>
                            {project.labels && project.labels.length > 0 ? (
                                <div className="space-y-2">
                                    {project.labels.map((label) => (
                                        <div
                                            key={label.id}
                                            className="flex items-center justify-between p-3 rounded-lg border bg-muted/20 hover:bg-muted/40 transition-colors"
                                        >
                                            <Badge
                                                style={{ backgroundColor: label.color, color: '#fff' }}
                                                className="px-3 py-1 text-sm"
                                            >
                                                {label.name}
                                            </Badge>
                                            <div className="flex items-center gap-1">
                                                <Dialog
                                                    open={editingLabel?.id === label.id}
                                                    onOpenChange={(open) => !open && setEditingLabel(null)}
                                                >
                                                    <DialogTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            onClick={() => setEditingLabel(label)}
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                    </DialogTrigger>
                                                    <DialogContent>
                                                        <DialogHeader>
                                                            <DialogTitle>Edit Label</DialogTitle>
                                                            <DialogDescription>
                                                                Update the label name or color.
                                                            </DialogDescription>
                                                        </DialogHeader>
                                                        {editingLabel && (
                                                            <LabelForm
                                                                mode="edit"
                                                                label={editingLabel}
                                                                workspace={workspace}
                                                                project={project}
                                                                onClose={() => setEditingLabel(null)}
                                                            />
                                                        )}
                                                    </DialogContent>
                                                </Dialog>
                                                <AlertDialog>
                                                    <AlertDialogTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8 text-destructive hover:text-destructive"
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    </AlertDialogTrigger>
                                                    <AlertDialogContent>
                                                        <AlertDialogHeader>
                                                            <AlertDialogTitle>Delete Label</AlertDialogTitle>
                                                            <AlertDialogDescription>
                                                                Are you sure you want to delete the {`"${label.name}"`} label?
                                                                This will remove it from all items.
                                                            </AlertDialogDescription>
                                                        </AlertDialogHeader>
                                                        <AlertDialogFooter>
                                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                            <AlertDialogAction
                                                                onClick={() => handleDeleteLabel(label.id)}
                                                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                            >
                                                                Delete
                                                            </AlertDialogAction>
                                                        </AlertDialogFooter>
                                                    </AlertDialogContent>
                                                </AlertDialog>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-8 text-muted-foreground">
                                    <Tag className="size-12 mx-auto mb-3 opacity-50" />
                                    <p className="text-sm">No labels yet</p>
                                    <p className="text-xs">Create labels to categorize your items</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                        </TabsContent>

                        <TabsContent value="item-types" className="space-y-8">
                    {/* Item types */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <div className="space-y-1">
                                <CardTitle className="flex items-center gap-2">
                                    <ListTodo className="size-5" />
                                    Item types
                                </CardTitle>
                                <CardDescription>
                                    Types for board items (e.g. Task, Bug, Story). Default types are shared; add project-specific types below.
                                </CardDescription>
                            </div>
                            <Dialog open={createItemTypeOpen} onOpenChange={setCreateItemTypeOpen}>
                                <DialogTrigger asChild>
                                    <Button variant="outline" size="sm" className="gap-2">
                                        <Plus className="size-4" />
                                        Add type
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Create item type</DialogTitle>
                                        <DialogDescription>
                                            Add a new type for items in this project. It will appear in the type dropdown on boards.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <ItemTypeForm
                                        mode="create"
                                        workspace={workspace}
                                        project={project}
                                        onClose={() => setCreateItemTypeOpen(false)}
                                    />
                                </DialogContent>
                            </Dialog>
                        </CardHeader>
                        <CardContent>
                            {projectItemTypes.length > 0 ? (
                                <div className="space-y-2">
                                    {projectItemTypes.map((type) => {
                                        const isGlobal = type.project_id == null;
                                        const isDisabled = isGlobal && type.is_active === false;
                                        return (
                                            <div
                                                key={type.id}
                                                className={`flex items-center justify-between p-3 rounded-lg border transition-colors ${isDisabled ? 'bg-muted/10 opacity-75' : 'bg-muted/20 hover:bg-muted/40'}`}
                                            >
                                                <div className="flex items-center gap-3">
                                                    <div
                                                        className="size-8 rounded-md shrink-0"
                                                        style={{ backgroundColor: type.color || '#94a3b8' }}
                                                    />
                                                    <ItemTypeIcon type={type} className="size-5" />
                                                    <div>
                                                        <p className="font-medium">{type.name}</p>
                                                        <p className="text-xs text-muted-foreground font-mono">{type.slug}</p>
                                                    </div>
                                                    {isGlobal && (
                                                        <Badge variant="secondary" className="text-[10px]">
                                                            {isDisabled ? 'Disabled' : 'Default'}
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-1">
                                                    {/* Default types are shared by every workspace: they can only be switched off per project. */}
                                                    {!isGlobal && (
                                                    <Dialog
                                                        open={editingItemType?.id === type.id}
                                                        onOpenChange={(open) => !open && setEditingItemType(null)}
                                                    >
                                                        <DialogTrigger asChild>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="size-8"
                                                                onClick={() => setEditingItemType(type)}
                                                                title="Edit"
                                                            >
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                        </DialogTrigger>
                                                        <DialogContent>
                                                            <DialogHeader>
                                                                <DialogTitle>Edit item type</DialogTitle>
                                                                <DialogDescription>
                                                                    Update the type name, color, or icon.
                                                                </DialogDescription>
                                                            </DialogHeader>
                                                            {editingItemType && (
                                                                <ItemTypeForm
                                                                    mode="edit"
                                                                    itemType={editingItemType}
                                                                    workspace={workspace}
                                                                    project={project}
                                                                    onClose={() => setEditingItemType(null)}
                                                                />
                                                            )}
                                                        </DialogContent>
                                                    </Dialog>
                                                    )}
                                                    {isGlobal ? (
                                                        isDisabled ? (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="size-8 text-green-600 hover:text-green-700"
                                                                title="Enable"
                                                                onClick={() =>
                                                                    router.put(
                                                                        route('projects.item-types.update', [workspace.slug, project.slug, type.id]),
                                                                        {
                                                                            name: type.name,
                                                                            slug: type.slug,
                                                                            color: type.color ?? '',
                                                                            icon: type.icon ?? '',
                                                                            description: type.description ?? '',
                                                                            is_active: true,
                                                                        }
                                                                    )
                                                                }
                                                            >
                                                                <Power className="size-4" />
                                                            </Button>
                                                        ) : (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="size-8 text-amber-600 hover:text-amber-700"
                                                                title="Disable (hide from boards)"
                                                                onClick={() =>
                                                                    router.put(
                                                                        route('projects.item-types.update', [workspace.slug, project.slug, type.id]),
                                                                        {
                                                                            name: type.name,
                                                                            slug: type.slug,
                                                                            color: type.color ?? '',
                                                                            icon: type.icon ?? '',
                                                                            description: type.description ?? '',
                                                                            is_active: false,
                                                                        }
                                                                    )
                                                                }
                                                            >
                                                                <PowerOff className="size-4" />
                                                            </Button>
                                                        )
                                                    ) : (
                                                        <AlertDialog>
                                                            <AlertDialogTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8 text-destructive hover:text-destructive"
                                                                    title="Delete"
                                                                >
                                                                    <Trash2 className="size-4" />
                                                                </Button>
                                                            </AlertDialogTrigger>
                                                            <AlertDialogContent>
                                                                <AlertDialogHeader>
                                                                    <AlertDialogTitle>Delete item type</AlertDialogTitle>
                                                                    <AlertDialogDescription>
                                                                        Delete &quot;{type.name}&quot;? This cannot be undone. You can only delete types that are not used by any items.
                                                                    </AlertDialogDescription>
                                                                </AlertDialogHeader>
                                                                <AlertDialogFooter>
                                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                    <AlertDialogAction
                                                                        onClick={() =>
                                                                            router.delete(
                                                                                route('projects.item-types.destroy', [
                                                                                    workspace.slug,
                                                                                    project.slug,
                                                                                    type.id,
                                                                                ])
                                                                            )
                                                                        }
                                                                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                                    >
                                                                        Delete
                                                                    </AlertDialogAction>
                                                                </AlertDialogFooter>
                                                            </AlertDialogContent>
                                                        </AlertDialog>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="text-center py-8 text-muted-foreground">
                                    <ListTodo className="size-12 mx-auto mb-3 opacity-50" />
                                    <p className="text-sm">No item types</p>
                                    <p className="text-xs">Add a type or use the default types (Task, Bug, Story, etc.)</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                        </TabsContent>

                        <TabsContent value="members" className="space-y-8">
                    {/* Members */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Users className="size-5" />
                                Members
                            </CardTitle>
                            <CardDescription>
                                Only assigned members can see this project. Invite users from the workspace and set their role (Admin, Editor, or Viewer).
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {project.users && project.users.length > 0 ? (
                                <div className="space-y-2">
                                    {project.users.map((member) => (
                                        <div
                                            key={member.id}
                                            className="flex items-center justify-between gap-4 p-3 rounded-lg border bg-muted/20 hover:bg-muted/40 transition-colors"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium truncate">{member.name}</p>
                                                <p className="text-xs text-muted-foreground truncate">{member.email}</p>
                                            </div>
                                            {canManageMembers ? (
                                                <div className="flex items-center gap-2 shrink-0">
                                                    <Select
                                                        value={member.pivot.role}
                                                        onValueChange={(v) => handleUpdateMemberRole(member.id, v as ProjectMemberRole)}
                                                    >
                                                        <SelectTrigger className="h-8 w-[110px]">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {PROJECT_ROLES.map((r) => (
                                                                <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <AlertDialog>
                                                        <AlertDialogTrigger asChild>
                                                            <Button variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive">
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                                <AlertDialogTitle>Remove from project</AlertDialogTitle>
                                                                <AlertDialogDescription>
                                                                    Remove {member.name} from this project? They will no longer see it.
                                                                </AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter>
                                                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                <AlertDialogAction
                                                                    onClick={() => handleRemoveMember(member.id)}
                                                                    className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                                >
                                                                    Remove
                                                                </AlertDialogAction>
                                                            </AlertDialogFooter>
                                                        </AlertDialogContent>
                                                    </AlertDialog>
                                                </div>
                                            ) : (
                                                <Badge variant="secondary" className="shrink-0">
                                                    {PROJECT_ROLES.find((r) => r.value === member.pivot.role)?.label ?? member.pivot.role}
                                                </Badge>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No members assigned yet.</p>
                            )}

                            {canManageMembers && availableToAdd.length > 0 && (
                                <form onSubmit={handleAddMember} className="flex flex-wrap items-end gap-2 pt-2 border-t">
                                    <div className="flex-1 min-w-[160px] space-y-1.5">
                                        <Label className="text-xs">Add workspace member</Label>
                                        <Select value={addMemberUserId} onValueChange={setAddMemberUserId} required>
                                            <SelectTrigger className="h-9">
                                                <SelectValue placeholder="Select user…" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {availableToAdd.map((u) => (
                                                    <SelectItem key={u.id} value={u.id.toString()}>{u.name} ({u.email})</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="w-[110px] space-y-1.5">
                                        <Label className="text-xs">Role</Label>
                                        <Select value={addMemberRole} onValueChange={(v) => setAddMemberRole(v as ProjectMemberRole)}>
                                            <SelectTrigger className="h-9">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {PROJECT_ROLES.map((r) => (
                                                    <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <Button type="submit" size="sm" className="h-9">Add</Button>
                                </form>
                            )}
                            {canManageMembers && availableToAdd.length === 0 && project.users && project.users.length > 0 && (
                                <p className="text-xs text-muted-foreground">All workspace members are already in this project.</p>
                            )}
                        </CardContent>
                    </Card>
                        </TabsContent>

                        <TabsContent value="integrations" className="space-y-8">
                            <GithubIntegrationCard workspace={workspace} project={project} github={github} />
                        </TabsContent>

                        <TabsContent value="danger" className="space-y-8">
                            <MoveProjectCard workspace={workspace} project={project} targets={moveTargets} />
                            <Card className="border-destructive/50">
                                <CardHeader>
                                    <CardTitle className="text-destructive">Danger Zone</CardTitle>
                                    <CardDescription>Irreversible and destructive actions</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center justify-between p-4 rounded-lg border border-destructive/30 bg-destructive/5">
                                        <div>
                                            <p className="font-medium">Delete Project</p>
                                            <p className="text-sm text-muted-foreground">Permanently delete this project and all its data</p>
                                        </div>
                                        <AlertDialog>
                                            <AlertDialogTrigger asChild>
                                                <Button variant="destructive">Delete Project</Button>
                                            </AlertDialogTrigger>
                                            <AlertDialogContent>
                                                <AlertDialogHeader>
                                                    <AlertDialogTitle>Delete Project</AlertDialogTitle>
                                                    <AlertDialogDescription>This action cannot be undone. This will permanently delete the project {`"${project.name}"`} and all its boards, items, and data.</AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                    <AlertDialogAction
                                                        onClick={() => router.delete(route('projects.destroy', [workspace.slug, project.slug]))}
                                                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                    >Delete Project</AlertDialogAction>
                                                </AlertDialogFooter>
                                            </AlertDialogContent>
                                        </AlertDialog>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>
                            </div>
                        </div>
                    </Tabs>
                </div>
            </div>
        </AppLayout>
    );
}
