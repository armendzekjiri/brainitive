import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm, router, Link } from '@inertiajs/react';
import { PageProps, Workspace, Project, Helpdesk, HelpdeskColumn, HelpdeskField } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
import {
    ArrowLeft,
    Settings,
    Plus,
    GripVertical,
    Trash2,
    Columns,
    Edit2,
    LifeBuoy,
    Palette,
    FormInput,
    Code,
    Eye,
    Lock,
    Link2,
    Check,
    Copy,
    ExternalLink,
} from 'lucide-react';
import { useState, useEffect, FormEvent, DragEvent } from 'react';
import axios from 'axios';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk & { columns: HelpdeskColumn[]; fields: HelpdeskField[] };
    appUrl: string;
}

const columnColors = [
    { name: 'Gray', value: '#94a3b8' },
    { name: 'Blue', value: '#3b82f6' },
    { name: 'Cyan', value: '#06b6d4' },
    { name: 'Green', value: '#22c55e' },
    { name: 'Yellow', value: '#eab308' },
    { name: 'Orange', value: '#f97316' },
    { name: 'Red', value: '#ef4444' },
    { name: 'Purple', value: '#a855f7' },
    { name: 'Pink', value: '#ec4899' },
];

const brandColors = [
    { name: 'Blue', value: '#0E83DC' },
    { name: 'Slate', value: '#485E72' },
    { name: 'Green', value: '#69A748' },
    ...columnColors,
];

/** Fixed default fields (not editable). */
const DEFAULT_FIELDS_LOCKED = [
    { label: 'Email', type: 'text', is_required: true, key: 'contact_email' },
    { label: 'Subject', type: 'text', is_required: true, key: 'subject' },
] as const;

/** Editable default fields (label/required stored in helpdesk.settings). */
const EDITABLE_DEFAULT_KEYS = [
    { key: 'contact_name', settingsKey: 'default_field_contact_name', defaultLabel: 'Name', defaultRequired: false, type: 'text' as const },
    { key: 'description', settingsKey: 'default_field_description', defaultLabel: 'Message', defaultRequired: false, type: 'textarea' as const },
] as const;

/** Optional sections in ticket modal (no label/required, just show/hide). */
const OPTIONAL_SECTIONS = [
    { key: 'attachments', settingsKey: 'default_field_attachments', defaultLabel: 'Attachments' },
] as const;

const fieldTypes: { value: HelpdeskField['type']; label: string; needsOptions?: boolean }[] = [
    { value: 'text', label: 'Text' },
    { value: 'rich_text', label: 'Rich text' },
    { value: 'number', label: 'Number' },
    { value: 'date', label: 'Date' },
    { value: 'select', label: 'Dropdown (select)', needsOptions: true },
    { value: 'radio', label: 'Radio buttons (single choice)', needsOptions: true },
    { value: 'checkboxes', label: 'Checkboxes (multiple choice)', needsOptions: true },
    { value: 'checkbox', label: 'Single checkbox (yes/no)' },
    { value: 'file', label: 'File upload' },
];

function ColumnCard({
    column,
    workspace,
    project,
    helpdesk,
    onEdit,
    onDragStart,
    onDragOver,
    onDragEnd,
    onDrop,
    isDragging,
    isDragOver,
}: {
    column: HelpdeskColumn;
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk;
    onEdit: (column: HelpdeskColumn) => void;
    onDragStart: (e: DragEvent, column: HelpdeskColumn) => void;
    onDragOver: (e: DragEvent, column: HelpdeskColumn) => void;
    onDragEnd: () => void;
    onDrop: (e: DragEvent, column: HelpdeskColumn) => void;
    isDragging: boolean;
    isDragOver: boolean;
}) {
    const ticketsCount = column.tickets_count ?? 0;
    const handleDelete = () => {
        router.delete(route('helpdesks.columns.destroy', [workspace.slug, project.slug, helpdesk.slug, column.id]));
    };

    return (
        <Card
            className={`border bg-background transition-all ${isDragging ? 'opacity-50 scale-95' : ''} ${isDragOver ? 'border-primary ring-2 ring-primary/20' : ''}`}
            draggable
            onDragStart={(e) => onDragStart(e, column)}
            onDragOver={(e) => onDragOver(e, column)}
            onDragEnd={onDragEnd}
            onDrop={(e) => onDrop(e, column)}
        >
            <CardContent className="p-4">
                <div className="flex items-center gap-4">
                    <div className="cursor-grab active:cursor-grabbing text-muted-foreground hover:text-foreground">
                        <GripVertical className="size-5" />
                    </div>
                    <div
                        className="w-3 h-8 rounded-full shrink-0"
                        style={{ backgroundColor: column.color || '#94a3b8' }}
                    />
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                            <h4 className="font-medium truncate">{column.name}</h4>
                            {column.is_done_column && (
                                <Badge variant="secondary" className="text-xs">
                                    Done
                                </Badge>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {ticketsCount} {ticketsCount === 1 ? 'ticket' : 'tickets'}
                            {column.wip_limit != null && ` • WIP limit: ${column.wip_limit}`}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" size="icon" className="size-8" onClick={() => onEdit(column)}>
                            <Edit2 className="size-4" />
                        </Button>
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8 text-destructive hover:text-destructive"
                                    disabled={ticketsCount > 0}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Delete column?</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Are you sure you want to delete "{column.name}"? This action cannot be undone.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={handleDelete}
                                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                    >
                                        Delete
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function AddColumnDialog({
    workspace,
    project,
    helpdesk,
}: {
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk;
}) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, reset, errors } = useForm({
        name: '',
        color: '#94a3b8',
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post(route('helpdesks.columns.store', [workspace.slug, project.slug, helpdesk.slug]), {
            onSuccess: () => {
                setOpen(false);
                reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button className="gap-2">
                    <Plus className="size-4" />
                    Add Column
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add Column</DialogTitle>
                    <DialogDescription>
                        Create a new column for your helpdesk workflow.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4 mt-4">
                    <div className="space-y-2">
                        <Label htmlFor="name">Column Name</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g., In Progress, Review, Done"
                            required
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label>Color</Label>
                        <div className="flex flex-wrap gap-2">
                            {columnColors.map((color) => (
                                <button
                                    key={color.value}
                                    type="button"
                                    onClick={() => setData('color', color.value)}
                                    className={`w-8 h-8 rounded-full border-2 transition-all ${
                                        data.color === color.value
                                            ? 'border-foreground scale-110'
                                            : 'border-transparent hover:scale-105'
                                    }`}
                                    style={{ backgroundColor: color.value }}
                                    title={color.name}
                                />
                            ))}
                        </div>
                    </div>

                    <div className="flex justify-end gap-3 pt-4">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Column'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditColumnDialog({
    column,
    workspace,
    project,
    helpdesk,
    open,
    onClose,
}: {
    column: HelpdeskColumn | null;
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk;
    open: boolean;
    onClose: () => void;
}) {
    const { data, setData, put, processing, errors } = useForm({
        name: column?.name || '',
        color: column?.color || '#94a3b8',
        wip_limit: column?.wip_limit != null ? column.wip_limit.toString() : '',
        is_done_column: column?.is_done_column ?? false,
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!column) return;
        put(route('helpdesks.columns.update', [workspace.slug, project.slug, helpdesk.slug, column.id]), {
            onSuccess: () => onClose(),
        });
    };

    if (!column) return null;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit Column</DialogTitle>
                    <DialogDescription>
                        Update column settings.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4 mt-4">
                    <div className="space-y-2">
                        <Label htmlFor="edit-name">Column Name</Label>
                        <Input
                            id="edit-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label>Color</Label>
                        <div className="flex flex-wrap gap-2">
                            {columnColors.map((color) => (
                                <button
                                    key={color.value}
                                    type="button"
                                    onClick={() => setData('color', color.value)}
                                    className={`w-8 h-8 rounded-full border-2 transition-all ${
                                        data.color === color.value
                                            ? 'border-foreground scale-110'
                                            : 'border-transparent hover:scale-105'
                                    }`}
                                    style={{ backgroundColor: color.value }}
                                    title={color.name}
                                />
                            ))}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="wip-limit">WIP Limit (optional)</Label>
                        <Input
                            id="wip-limit"
                            type="number"
                            min={0}
                            value={data.wip_limit}
                            onChange={(e) => setData('wip_limit', e.target.value)}
                            placeholder="No limit"
                        />
                        <p className="text-xs text-muted-foreground">
                            Maximum number of tickets allowed in this column
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            id="is-done"
                            checked={data.is_done_column}
                            onChange={(e) => setData('is_done_column', e.target.checked)}
                            className="size-4 rounded border-input"
                        />
                        <Label htmlFor="is-done" className="font-normal">
                            Mark as "Done" column
                        </Label>
                    </div>

                    <div className="flex justify-end gap-3 pt-4">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// --- Fields tab: Field card and dialogs ---
function FieldCard({
    field,
    workspace,
    project,
    helpdesk,
    onEdit,
    onDragStart,
    onDragOver,
    onDragEnd,
    onDrop,
    isDragging,
    isDragOver,
}: {
    field: HelpdeskField;
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk;
    onEdit: (f: HelpdeskField) => void;
    onDragStart: (e: DragEvent, f: HelpdeskField) => void;
    onDragOver: (e: DragEvent, f: HelpdeskField) => void;
    onDragEnd: () => void;
    onDrop: (e: DragEvent, f: HelpdeskField) => void;
    isDragging: boolean;
    isDragOver: boolean;
}) {
    const handleDelete = () => {
        router.delete(route('helpdesks.fields.destroy', [workspace.slug, project.slug, helpdesk.slug, field.id]));
    };
    return (
        <Card
            className={`border bg-background transition-all ${isDragging ? 'opacity-50 scale-95' : ''} ${isDragOver ? 'border-primary ring-2 ring-primary/20' : ''}`}
            draggable
            onDragStart={(e) => onDragStart(e, field)}
            onDragOver={(e) => onDragOver(e, field)}
            onDragEnd={onDragEnd}
            onDrop={(e) => onDrop(e, field)}
        >
            <CardContent className="p-3 flex items-center gap-3">
                <div className="cursor-grab active:cursor-grabbing text-muted-foreground">
                    <GripVertical className="size-4" />
                </div>
                <div className="flex-1 min-w-0">
                    <p className="font-medium text-sm truncate">{field.label}</p>
                    <p className="text-xs text-muted-foreground capitalize">{field.type.replace('_', ' ')}</p>
                </div>
                <div className="flex items-center gap-1">
                    {field.is_required && <Badge variant="secondary" className="text-xs">Required</Badge>}
                    {field.is_visible_on_card && <Badge variant="outline" className="text-xs">On card</Badge>}
                </div>
                <Button variant="ghost" size="icon" className="size-8" onClick={() => onEdit(field)}>
                    <Edit2 className="size-4" />
                </Button>
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive">
                            <Trash2 className="size-4" />
                        </Button>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Delete field?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Remove "{field.label}" from the form? Existing ticket values for this field will be lost.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction onClick={handleDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                                Delete
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </CardContent>
        </Card>
    );
}

function AddFieldDialog({ workspace, project, helpdesk }: { workspace: Workspace; project: Project; helpdesk: Helpdesk }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const { data, setData, reset, errors } = useForm({
        label: '',
        type: 'text' as HelpdeskField['type'],
        optionsText: '',
        is_required: false,
        is_visible_on_card: false,
    });
    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const needsOptions = fieldTypes.find((t) => t.value === data.type)?.needsOptions;
        const options = needsOptions && data.optionsText.trim()
            ? data.optionsText.split('\n').map((s) => s.trim()).filter(Boolean)
            : [];
        setProcessing(true);
        router.post(route('helpdesks.fields.store', [workspace.slug, project.slug, helpdesk.slug]), {
            label: data.label,
            type: data.type,
            options,
            is_required: data.is_required,
            is_visible_on_card: data.is_visible_on_card,
        }, {
            preserveScroll: true,
            onSuccess: () => { setOpen(false); reset(); setProcessing(false); },
            onError: () => setProcessing(false),
            onFinish: () => setProcessing(false),
        });
    };
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button className="gap-2">
                    <Plus className="size-4" />
                    Add field
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Add form field</DialogTitle>
                    <DialogDescription>This field will appear on the public submission form and on tickets.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4 mt-4">
                    <div className="space-y-2">
                        <Label htmlFor="field-label">Label</Label>
                        <Input
                            id="field-label"
                            value={data.label}
                            onChange={(e) => setData('label', e.target.value)}
                            placeholder="e.g., Category, Priority"
                            required
                        />
                        {errors.label && <p className="text-sm text-destructive">{errors.label}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label>Type</Label>
                        <select
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value as HelpdeskField['type'])}
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                            {fieldTypes.map((t) => (
                                <option key={t.value} value={t.value}>{t.label}</option>
                            ))}
                        </select>
                    </div>
                    {(fieldTypes.find((t) => t.value === data.type)?.needsOptions) && (
                        <div className="space-y-2">
                            <Label htmlFor="field-options">Options (one per line)</Label>
                            <Textarea
                                id="field-options"
                                value={data.optionsText}
                                onChange={(e) => setData('optionsText', e.target.value)}
                                placeholder={'Option A\nOption B\nOption C'}
                                rows={4}
                                className="font-mono text-sm resize-y"
                            />
                            <p className="text-xs text-muted-foreground">
                                Enter each choice on a new line. Customers will pick one (select/radio) or several (checkboxes).
                            </p>
                        </div>
                    )}
                    <div className="flex items-center gap-4">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={data.is_required}
                                onChange={(e) => setData('is_required', e.target.checked)}
                                className="size-4 rounded border-input"
                            />
                            Required
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={data.is_visible_on_card}
                                onChange={(e) => setData('is_visible_on_card', e.target.checked)}
                                className="size-4 rounded border-input"
                            />
                            Show on ticket card
                        </label>
                    </div>
                    <div className="flex justify-end gap-3 pt-2">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>{processing ? 'Adding...' : 'Add field'}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditFieldDialog({
    field,
    workspace,
    project,
    helpdesk,
    open,
    onClose,
}: {
    field: HelpdeskField | null;
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk;
    open: boolean;
    onClose: () => void;
}) {
    const optionsText = (field?.options && Array.isArray(field.options) && field.options.length > 0) ? field.options.join('\n') : '';
    const { data, setData, errors } = useForm({
        label: field?.label ?? '',
        type: (field?.type ?? 'text') as HelpdeskField['type'],
        optionsText: optionsText,
        is_required: field?.is_required ?? false,
        is_visible_on_card: field?.is_visible_on_card ?? false,
    });
    const [processing, setProcessing] = useState(false);
    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!field) return;
        const needsOptions = fieldTypes.find((t) => t.value === data.type)?.needsOptions;
        const options = needsOptions && data.optionsText.trim()
            ? data.optionsText.split('\n').map((s) => s.trim()).filter(Boolean)
            : [];
        setProcessing(true);
        router.put(route('helpdesks.fields.update', [workspace.slug, project.slug, helpdesk.slug, field.id]), {
            label: data.label,
            type: data.type,
            options,
            is_required: data.is_required,
            is_visible_on_card: data.is_visible_on_card,
        }, {
            preserveScroll: true,
            onSuccess: () => { onClose(); setProcessing(false); },
            onError: () => setProcessing(false),
            onFinish: () => setProcessing(false),
        });
    };
    if (!field) return null;
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Edit field</DialogTitle>
                    <DialogDescription>Update label, type, and options.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4 mt-4">
                    <div className="space-y-2">
                        <Label htmlFor="edit-field-label">Label</Label>
                        <Input
                            id="edit-field-label"
                            value={data.label}
                            onChange={(e) => setData('label', e.target.value)}
                            required
                        />
                        {errors.label && <p className="text-sm text-destructive">{errors.label}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label>Type</Label>
                        <select
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value as HelpdeskField['type'])}
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                            {fieldTypes.map((t) => (
                                <option key={t.value} value={t.value}>{t.label}</option>
                            ))}
                        </select>
                    </div>
                    {(fieldTypes.find((t) => t.value === data.type)?.needsOptions) && (
                        <div className="space-y-2">
                            <Label htmlFor="edit-field-options">Options (one per line)</Label>
                            <Textarea
                                id="edit-field-options"
                                value={data.optionsText}
                                onChange={(e) => setData('optionsText', e.target.value)}
                                placeholder={'Option A\nOption B\nOption C'}
                                rows={4}
                                className="font-mono text-sm resize-y"
                            />
                            <p className="text-xs text-muted-foreground">
                                Enter each choice on a new line.
                            </p>
                        </div>
                    )}
                    <div className="flex items-center gap-4">
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.is_required} onChange={(e) => setData('is_required', e.target.checked)} className="size-4 rounded border-input" />
                            Required
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.is_visible_on_card} onChange={(e) => setData('is_visible_on_card', e.target.checked)} className="size-4 rounded border-input" />
                            Show on ticket card
                        </label>
                    </div>
                    <div className="flex justify-end gap-3 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={processing}>{processing ? 'Saving...' : 'Save'}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const defaultSettings = {
    brand_color: '#0E83DC',
    logo_url: '',
    welcome_message: '',
    button_text: 'Support',
    button_position: 'bottom-right' as 'bottom-right' | 'bottom-left',
};

export default function HelpdesksEdit({ workspace, project, helpdesk, appUrl }: Props) {
    const [editingColumn, setEditingColumn] = useState<HelpdeskColumn | null>(null);
    const [columns, setColumns] = useState<HelpdeskColumn[]>(helpdesk.columns);
    const [draggedColumn, setDraggedColumn] = useState<HelpdeskColumn | null>(null);
    const [dragOverColumn, setDragOverColumn] = useState<HelpdeskColumn | null>(null);

    const [fields, setFields] = useState<HelpdeskField[]>(helpdesk.fields ?? []);
    useEffect(() => {
        setFields(helpdesk.fields ?? []);
    }, [helpdesk.fields]);
    const [editingField, setEditingField] = useState<HelpdeskField | null>(null);
    const [editingEditableKey, setEditingEditableKey] = useState<typeof EDITABLE_DEFAULT_KEYS[number]['key'] | null>(null);
    const [editableForm, setEditableForm] = useState<{ label: string; is_required: boolean }>({ label: '', is_required: false });
    const [draggedField, setDraggedField] = useState<HelpdeskField | null>(null);
    const [dragOverField, setDragOverField] = useState<HelpdeskField | null>(null);

    const settings = (helpdesk.settings && typeof helpdesk.settings === 'object') ? helpdesk.settings : {};
    const mergedSettings = { ...defaultSettings, ...settings };

    useEffect(() => {
        if (editingEditableKey) {
            const def = EDITABLE_DEFAULT_KEYS.find((d) => d.key === editingEditableKey);
            if (def) {
                const s = (helpdesk.settings && typeof helpdesk.settings === 'object') ? helpdesk.settings : {};
                const stored = s[def.settingsKey];
                const label = (stored && typeof stored === 'object' && 'label' in stored) ? String(stored.label) : def.defaultLabel;
                const isRequired = (stored && typeof stored === 'object' && 'is_required' in stored) ? Boolean(stored.is_required) : def.defaultRequired;
                setEditableForm({ label, is_required: isRequired });
            }
        }
    }, [editingEditableKey, helpdesk.settings]);

    const { data, setData, put, processing, errors } = useForm({
        name: helpdesk.name,
        description: helpdesk.description || '',
        public_slug: helpdesk.public_slug ?? '',
        settings: {
            brand_color: mergedSettings.brand_color ?? defaultSettings.brand_color,
            logo_url: mergedSettings.logo_url ?? '',
            welcome_message: mergedSettings.welcome_message ?? '',
            button_text: mergedSettings.button_text ?? defaultSettings.button_text,
            button_position: mergedSettings.button_position ?? defaultSettings.button_position,
        },
    });

    const handleDragStart = (e: DragEvent, column: HelpdeskColumn) => {
        setDraggedColumn(column);
        e.dataTransfer.effectAllowed = 'move';
    };

    const handleDragOver = (e: DragEvent, column: HelpdeskColumn) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (draggedColumn && column.id !== draggedColumn.id) {
            setDragOverColumn(column);
        }
    };

    const handleDragEnd = () => {
        setDraggedColumn(null);
        setDragOverColumn(null);
    };

    const handleDrop = async (e: DragEvent, targetColumn: HelpdeskColumn) => {
        e.preventDefault();
        if (!draggedColumn || draggedColumn.id === targetColumn.id) {
            handleDragEnd();
            return;
        }

        const newColumns = [...columns];
        const draggedIndex = newColumns.findIndex((c) => c.id === draggedColumn.id);
        const targetIndex = newColumns.findIndex((c) => c.id === targetColumn.id);

        newColumns.splice(draggedIndex, 1);
        newColumns.splice(targetIndex, 0, draggedColumn);

        const updatedColumns = newColumns.map((col, index) => ({
            ...col,
            position: index,
        }));

        setColumns(updatedColumns);
        handleDragEnd();

        try {
            await axios.post(route('helpdesks.columns.reorder', [workspace.slug, project.slug, helpdesk.slug]), {
                columns: updatedColumns.map((col) => ({ id: col.id, position: col.position })),
            });
        } catch (error) {
            setColumns(helpdesk.columns);
            console.error('Failed to reorder columns:', error);
        }
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        put(route('helpdesks.update', [workspace.slug, project.slug, helpdesk.slug]));
    };

    const handleFieldDragStart = (e: DragEvent, field: HelpdeskField) => {
        setDraggedField(field);
        e.dataTransfer.effectAllowed = 'move';
    };
    const handleFieldDragOver = (e: DragEvent, field: HelpdeskField) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (draggedField && field.id !== draggedField.id) setDragOverField(field);
    };
    const handleFieldDragEnd = () => {
        setDraggedField(null);
        setDragOverField(null);
    };
    const handleFieldDrop = async (e: DragEvent, target: HelpdeskField) => {
        e.preventDefault();
        if (!draggedField || draggedField.id === target.id) {
            handleFieldDragEnd();
            return;
        }
        const newFields = [...fields];
        const from = newFields.findIndex((f) => f.id === draggedField.id);
        const to = newFields.findIndex((f) => f.id === target.id);
        newFields.splice(from, 1);
        newFields.splice(to, 0, draggedField);
        const withPosition = newFields.map((f, i) => ({ ...f, position: i }));
        setFields(withPosition);
        handleFieldDragEnd();
        try {
            await axios.post(route('helpdesks.fields.reorder', [workspace.slug, project.slug, helpdesk.slug]), {
                fields: withPosition.map((f) => ({ id: f.id, position: f.position })),
            });
        } catch (err) {
            setFields(helpdesk.fields ?? []);
            console.error(err);
        }
    };

    const handleDeleteHelpdesk = () => {
        router.delete(route('helpdesks.destroy', [workspace.slug, project.slug, helpdesk.slug]));
    };

    const embedScript = `<script src="${appUrl}/js/helpdesk-widget.js" data-helpdesk-id="${helpdesk.uuid}" data-button-text="${(data.settings?.button_text ?? '').replace(/"/g, '&quot;')}" data-button-position="${data.settings?.button_position ?? 'bottom-right'}" data-brand-color="${(data.settings?.brand_color ?? '').replace(/"/g, '')}"><\/script>`;
    const embedIframeUrl = `${appUrl}/widget/helpdesks/${helpdesk.uuid}/embed`;
    const embedIframeSnippet = `<iframe src="${embedIframeUrl}" width="100%" height="500" frameborder="0" title="Contact support"></iframe>`;

    return (
        <AppLayout>
            <Head title={`Settings - ${helpdesk.name}`} />

            <div className="min-h-full bg-muted/20">
                <div className="bg-background border-b px-6 py-4">
                    <div className="max-w-4xl mx-auto">
                        <div className="flex items-center gap-4">
                            <Link href={route('helpdesks.show', [workspace.slug, project.slug, helpdesk.slug])}>
                                <Button variant="ghost" size="icon" className="size-9">
                                    <ArrowLeft className="size-5" />
                                </Button>
                            </Link>
                            <div className="flex items-center gap-3">
                                <ProjectIcon project={project} size="md" />
                                <div>
                                    <h1 className="text-xl font-semibold">Helpdesk Settings</h1>
                                    <p className="text-sm text-muted-foreground">{helpdesk.name}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="max-w-4xl mx-auto p-6">
                    <Tabs defaultValue="general" className="space-y-6">
                        <TabsList className="grid w-full grid-cols-4 lg:w-auto lg:inline-grid">
                            <TabsTrigger value="general" className="gap-2">
                                <Settings className="size-4" />
                                General
                            </TabsTrigger>
                            <TabsTrigger value="columns" className="gap-2">
                                <Columns className="size-4" />
                                Columns
                            </TabsTrigger>
                            <TabsTrigger value="fields" className="gap-2">
                                <FormInput className="size-4" />
                                Fields
                            </TabsTrigger>
                            <TabsTrigger value="embed" className="gap-2">
                                <Code className="size-4" />
                                Embed
                            </TabsTrigger>
                        </TabsList>

                        <TabsContent value="general" className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-3">
                                        <Settings className="size-5 text-primary" />
                                        General & branding
                                    </CardTitle>
                                    <CardDescription>
                                        Name, description, and widget branding (colors, welcome message, button).
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={handleSubmit} className="space-y-6">
                                        <div className="space-y-2">
                                            <Label htmlFor="name">Helpdesk name</Label>
                                            <Input
                                                id="name"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                required
                                            />
                                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="description">Description (optional)</Label>
                                            <Textarea
                                                id="description"
                                                value={data.description}
                                                onChange={(e) => setData('description', e.target.value)}
                                                placeholder="Describe the purpose of this helpdesk..."
                                                rows={3}
                                            />
                                        </div>
                                        <Separator />
                                        <div>
                                            <h4 className="text-sm font-medium mb-3 flex items-center gap-2">
                                                <Palette className="size-4" />
                                                Widget branding
                                            </h4>
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label>Brand color</Label>
                                                    <div className="flex flex-wrap gap-2">
                                                        {brandColors.slice(0, 8).map((c) => (
                                                            <button
                                                                key={c.value}
                                                                type="button"
                                                                onClick={() => setData('settings', { ...data.settings, brand_color: c.value })}
                                                                className={`h-8 w-8 rounded-full border-2 transition-all ${data.settings?.brand_color === c.value ? 'border-foreground scale-110' : 'border-transparent'}`}
                                                                style={{ backgroundColor: c.value }}
                                                                title={c.name}
                                                            />
                                                        ))}
                                                    </div>
                                                    <Input
                                                        value={data.settings?.brand_color ?? ''}
                                                        onChange={(e) => setData('settings', { ...data.settings, brand_color: e.target.value })}
                                                        placeholder="#0E83DC"
                                                        className="mt-1 font-mono text-sm"
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="logo_url">Logo URL (optional)</Label>
                                                    <Input
                                                        id="logo_url"
                                                        value={data.settings?.logo_url ?? ''}
                                                        onChange={(e) => setData('settings', { ...data.settings, logo_url: e.target.value })}
                                                        placeholder="https://..."
                                                    />
                                                </div>
                                            </div>
                                            <div className="mt-4 space-y-2">
                                                <Label htmlFor="welcome_message">Welcome message (optional)</Label>
                                                <Textarea
                                                    id="welcome_message"
                                                    value={data.settings?.welcome_message ?? ''}
                                                    onChange={(e) => setData('settings', { ...data.settings, welcome_message: e.target.value })}
                                                    placeholder="How can we help?"
                                                    rows={2}
                                                />
                                            </div>
                                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="button_text">Button text</Label>
                                                    <Input
                                                        id="button_text"
                                                        value={data.settings?.button_text ?? ''}
                                                        onChange={(e) => setData('settings', { ...data.settings, button_text: e.target.value })}
                                                        placeholder="Support"
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Button position</Label>
                                                    <select
                                                        value={data.settings?.button_position ?? 'bottom-right'}
                                                        onChange={(e) => setData('settings', { ...data.settings, button_position: e.target.value as 'bottom-right' | 'bottom-left' })}
                                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                                                    >
                                                        <option value="bottom-right">Bottom right</option>
                                                        <option value="bottom-left">Bottom left</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex justify-end pt-2">
                                            <Button type="submit" disabled={processing}>
                                                {processing ? 'Saving...' : 'Save changes'}
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <TabsContent value="columns" className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <CardTitle className="flex items-center gap-3">
                                                <Columns className="size-5 text-primary" />
                                                Columns
                                            </CardTitle>
                                            <CardDescription>
                                                Manage your helpdesk workflow columns. Drag to reorder.
                                            </CardDescription>
                                        </div>
                                        <AddColumnDialog workspace={workspace} project={project} helpdesk={helpdesk} />
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {columns.length === 0 ? (
                                        <div className="text-center py-12">
                                            <div className="size-16 rounded-full bg-muted mx-auto mb-4 flex items-center justify-center">
                                                <LifeBuoy className="size-8 text-muted-foreground" />
                                            </div>
                                            <h3 className="text-lg font-semibold mb-2">No columns yet</h3>
                                            <p className="text-muted-foreground mb-6 max-w-sm mx-auto">
                                                Add columns to define your ticket workflow stages.
                                            </p>
                                            <AddColumnDialog workspace={workspace} project={project} helpdesk={helpdesk} />
                                        </div>
                                    ) : (
                                        <div className="space-y-2">
                                            {columns.map((column) => (
                                                <ColumnCard
                                                    key={column.id}
                                                    column={column}
                                                    workspace={workspace}
                                                    project={project}
                                                    helpdesk={helpdesk}
                                                    onEdit={setEditingColumn}
                                                    onDragStart={handleDragStart}
                                                    onDragOver={handleDragOver}
                                                    onDragEnd={handleDragEnd}
                                                    onDrop={handleDrop}
                                                    isDragging={draggedColumn?.id === column.id}
                                                    isDragOver={dragOverColumn?.id === column.id}
                                                />
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <TabsContent value="fields" className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <CardTitle className="flex items-center gap-3">
                                                <FormInput className="size-5 text-primary" />
                                                Form fields
                                            </CardTitle>
                                            <CardDescription>
                                                Default fields are always on the submission form. Add custom fields below and drag to reorder.
                                            </CardDescription>
                                        </div>
                                        <AddFieldDialog workspace={workspace} project={project} helpdesk={helpdesk} />
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    {/* Fixed default fields (read-only) */}
                                    <div>
                                        <h4 className="text-sm font-medium flex items-center gap-2 mb-2 text-muted-foreground">
                                            <Lock className="size-4" />
                                            Default fields (fixed)
                                        </h4>
                                        <div className="space-y-2">
                                            {DEFAULT_FIELDS_LOCKED.map((f) => (
                                                <div
                                                    key={f.key}
                                                    className="flex items-center gap-3 rounded-lg border bg-muted/20 px-3 py-2.5"
                                                >
                                                    <div className="w-6 shrink-0 text-muted-foreground/50">
                                                        <Lock className="size-4" />
                                                    </div>
                                                    <div className="flex-1 min-w-0">
                                                        <p className="font-medium text-sm">{f.label}</p>
                                                        <p className="text-xs text-muted-foreground capitalize">{f.type}</p>
                                                    </div>
                                                    {f.is_required && <Badge variant="secondary" className="text-xs">Required</Badge>}
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    <Separator />

                                    {/* Editable default fields (Name, Message) + optional sections (Attachments) */}
                                    <div>
                                        <h4 className="text-sm font-medium flex items-center gap-2 mb-2">Editable fields & sections</h4>
                                        <p className="text-xs text-muted-foreground mb-2">Add, edit, or remove Name and Message from the form. Control which sections appear in the ticket modal.</p>
                                        <div className="space-y-2">
                                            {EDITABLE_DEFAULT_KEYS.map((def) => {
                                                const stored = settings[def.settingsKey];
                                                const hidden = stored && typeof stored === 'object' && 'hidden' in stored && stored.hidden === true;
                                                if (hidden) {
                                                    return (
                                                        <div
                                                            key={def.key}
                                                            className="flex items-center justify-between gap-3 rounded-lg border border-dashed bg-muted/20 px-3 py-2.5"
                                                        >
                                                            <span className="text-sm text-muted-foreground">"{def.defaultLabel}" not on form</span>
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() => {
                                                                    const newSettings = { ...settings, [def.settingsKey]: { label: def.defaultLabel, is_required: def.defaultRequired } };
                                                                    router.put(route('helpdesks.update', [workspace.slug, project.slug, helpdesk.slug]), {
                                                                        name: data.name,
                                                                        description: data.description,
                                                                        settings: newSettings,
                                                                    });
                                                                }}
                                                            >
                                                                Add {def.defaultLabel}
                                                            </Button>
                                                        </div>
                                                    );
                                                }
                                                const label = (stored && typeof stored === 'object' && 'label' in stored) ? String(stored.label) : def.defaultLabel;
                                                const isRequired = (stored && typeof stored === 'object' && 'is_required' in stored) ? Boolean(stored.is_required) : def.defaultRequired;
                                                return (
                                                    <div
                                                        key={def.key}
                                                        className="flex items-center gap-3 rounded-lg border bg-background px-3 py-2.5"
                                                    >
                                                        <div className="flex-1 min-w-0">
                                                            <p className="font-medium text-sm">{label}</p>
                                                            <p className="text-xs text-muted-foreground capitalize">{def.type}</p>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            {isRequired && <Badge variant="secondary" className="text-xs">Required</Badge>}
                                                            <Button variant="ghost" size="icon" className="size-8" onClick={() => setEditingEditableKey(def.key)}>
                                                                <Edit2 className="size-4" />
                                                            </Button>
                                                            <AlertDialog>
                                                                <AlertDialogTrigger asChild>
                                                                    <Button variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive">
                                                                        <Trash2 className="size-4" />
                                                                    </Button>
                                                                </AlertDialogTrigger>
                                                                <AlertDialogContent>
                                                                    <AlertDialogHeader>
                                                                        <AlertDialogTitle>Remove from form?</AlertDialogTitle>
                                                                        <AlertDialogDescription>
                                                                            "{label}" will no longer appear on the submission form. You can add it back anytime.
                                                                        </AlertDialogDescription>
                                                                    </AlertDialogHeader>
                                                                    <AlertDialogFooter>
                                                                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                        <AlertDialogAction
                                                                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                                            onClick={() => {
                                                                                const newSettings = { ...settings, [def.settingsKey]: { hidden: true } };
                                                                                router.put(route('helpdesks.update', [workspace.slug, project.slug, helpdesk.slug]), {
                                                                                    name: data.name,
                                                                                    description: data.description,
                                                                                    settings: newSettings,
                                                                                });
                                                                            }}
                                                                        >
                                                                            Remove
                                                                        </AlertDialogAction>
                                                                    </AlertDialogFooter>
                                                                </AlertDialogContent>
                                                            </AlertDialog>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                            {OPTIONAL_SECTIONS.map((section) => {
                                                const stored = settings[section.settingsKey];
                                                const hidden = stored && typeof stored === 'object' && 'hidden' in stored && stored.hidden === true;
                                                if (hidden) {
                                                    return (
                                                        <div
                                                            key={section.key}
                                                            className="flex items-center justify-between gap-3 rounded-lg border border-dashed bg-muted/20 px-3 py-2.5"
                                                        >
                                                            <span className="text-sm text-muted-foreground">"{section.defaultLabel}" not in ticket view</span>
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() => {
                                                                    const newSettings = { ...settings, [section.settingsKey]: {} };
                                                                    router.put(route('helpdesks.update', [workspace.slug, project.slug, helpdesk.slug]), {
                                                                        name: data.name,
                                                                        description: data.description,
                                                                        settings: newSettings,
                                                                    });
                                                                }}
                                                            >
                                                                Add {section.defaultLabel}
                                                            </Button>
                                                        </div>
                                                    );
                                                }
                                                return (
                                                    <div
                                                        key={section.key}
                                                        className="flex items-center gap-3 rounded-lg border bg-background px-3 py-2.5"
                                                    >
                                                        <div className="flex-1 min-w-0">
                                                            <p className="font-medium text-sm">{section.defaultLabel}</p>
                                                            <p className="text-xs text-muted-foreground">Section in ticket modal</p>
                                                        </div>
                                                        <AlertDialog>
                                                            <AlertDialogTrigger asChild>
                                                                <Button variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive" title={`Remove ${section.defaultLabel} from ticket view`}>
                                                                    <Trash2 className="size-4" />
                                                                </Button>
                                                            </AlertDialogTrigger>
                                                            <AlertDialogContent>
                                                                <AlertDialogHeader>
                                                                    <AlertDialogTitle>Remove from ticket view?</AlertDialogTitle>
                                                                    <AlertDialogDescription>
                                                                        The "{section.defaultLabel}" section will be hidden in the ticket modal. You can add it back anytime.
                                                                    </AlertDialogDescription>
                                                                </AlertDialogHeader>
                                                                <AlertDialogFooter>
                                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                    <AlertDialogAction
                                                                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                                        onClick={() => {
                                                                            const newSettings = { ...settings, [section.settingsKey]: { hidden: true } };
                                                                            router.put(route('helpdesks.update', [workspace.slug, project.slug, helpdesk.slug]), {
                                                                                name: data.name,
                                                                                description: data.description,
                                                                                settings: newSettings,
                                                                            });
                                                                        }}
                                                                    >
                                                                        Remove
                                                                    </AlertDialogAction>
                                                                </AlertDialogFooter>
                                                            </AlertDialogContent>
                                                        </AlertDialog>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    <Separator />

                                    {/* Custom fields */}
                                    <div>
                                        <h4 className="text-sm font-medium flex items-center gap-2 mb-2">Custom fields</h4>
                                        {fields.length === 0 ? (
                                            <div className="text-center py-12 rounded-xl border-2 border-dashed border-muted-foreground/25">
                                                <FormInput className="size-12 text-muted-foreground mx-auto mb-4" />
                                                <p className="text-sm font-medium mb-1">No custom fields yet</p>
                                                <p className="text-xs text-muted-foreground max-w-sm mx-auto mb-5">
                                                    Add fields like Category, Priority, or custom text so submitters can provide more context.
                                                </p>
                                                <AddFieldDialog workspace={workspace} project={project} helpdesk={helpdesk} />
                                            </div>
                                        ) : (
                                            <div className="space-y-2">
                                                {fields.map((f) => (
                                                    <FieldCard
                                                        key={f.id}
                                                        field={f}
                                                        workspace={workspace}
                                                        project={project}
                                                        helpdesk={helpdesk}
                                                        onEdit={setEditingField}
                                                        onDragStart={handleFieldDragStart}
                                                        onDragOver={handleFieldDragOver}
                                                        onDragEnd={handleFieldDragEnd}
                                                        onDrop={handleFieldDrop}
                                                        isDragging={draggedField?.id === f.id}
                                                        isDragOver={dragOverField?.id === f.id}
                                                    />
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    {/* Form preview: fixed default + editable + custom */}
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <h4 className="text-sm font-medium flex items-center gap-2 mb-3">
                                            <Eye className="size-4" />
                                            Form preview (order on submit form)
                                        </h4>
                                        <div className="space-y-2 text-sm">
                                            {DEFAULT_FIELDS_LOCKED.map((f) => (
                                                <div key={f.key} className="flex items-center gap-2">
                                                    <span className="font-medium text-muted-foreground w-32 truncate">{f.label}</span>
                                                    <span className="text-muted-foreground">({f.type})</span>
                                                    {f.is_required && <Badge variant="secondary" className="text-xs">Required</Badge>}
                                                    <Badge variant="outline" className="text-xs">Fixed</Badge>
                                                </div>
                                            ))}
                                            {EDITABLE_DEFAULT_KEYS.map((def) => {
                                                const stored = settings[def.settingsKey];
                                                const hidden = stored && typeof stored === 'object' && 'hidden' in stored && stored.hidden === true;
                                                if (hidden) return null;
                                                const label = (stored && typeof stored === 'object' && 'label' in stored) ? String(stored.label) : def.defaultLabel;
                                                const isRequired = (stored && typeof stored === 'object' && 'is_required' in stored) ? Boolean(stored.is_required) : def.defaultRequired;
                                                return (
                                                    <div key={def.key} className="flex items-center gap-2">
                                                        <span className="font-medium text-muted-foreground w-32 truncate">{label}</span>
                                                        <span className="text-muted-foreground">({def.type})</span>
                                                        {isRequired && <Badge variant="secondary" className="text-xs">Required</Badge>}
                                                    </div>
                                                );
                                            })}
                                            {OPTIONAL_SECTIONS.map((section) => {
                                                const stored = settings[section.settingsKey];
                                                const hidden = stored && typeof stored === 'object' && 'hidden' in stored && stored.hidden === true;
                                                if (hidden) return null;
                                                return (
                                                    <div key={section.key} className="flex items-center gap-2">
                                                        <span className="font-medium text-muted-foreground w-32 truncate">{section.defaultLabel}</span>
                                                        <span className="text-muted-foreground">(section in ticket modal)</span>
                                                    </div>
                                                );
                                            })}
                                            {fields.map((f) => (
                                                <div key={f.id} className="flex items-center gap-2">
                                                    <span className="font-medium text-muted-foreground w-32 truncate">{f.label}</span>
                                                    <span className="text-muted-foreground">({f.type.replace('_', ' ')})</span>
                                                    {f.is_required && <Badge variant="secondary" className="text-xs">Required</Badge>}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <TabsContent value="embed" className="space-y-6">
                            <SupportPageCard
                                appUrl={appUrl}
                                savedSlug={helpdesk.public_slug ?? ''}
                                value={data.public_slug}
                                onChange={(v) => setData('public_slug', v.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-{2,}/g, '-'))}
                                suggestion={slugify(`${workspace.name} ${helpdesk.name}`)}
                                error={errors.public_slug}
                                processing={processing}
                                onSave={() => put(route('helpdesks.update', [workspace.slug, project.slug, helpdesk.slug]), { preserveScroll: true })}
                            />
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-3">
                                        <Code className="size-5 text-primary" />
                                        Floating widget (click to open)
                                    </CardTitle>
                                    <CardDescription>
                                        Add this script to show a floating button. Users click it to open the form in a panel.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <Label>Script snippet</Label>
                                        <pre className="rounded-lg border bg-muted/50 p-4 text-xs overflow-x-auto whitespace-pre-wrap break-all font-mono">
                                            {embedScript}
                                        </pre>
                                        <p className="text-xs text-muted-foreground">
                                            Optional data attributes: data-button-text, data-button-position, data-brand-color (overrides General branding when set on the script tag).
                                        </p>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <h4 className="text-sm font-medium mb-2">Current widget settings</h4>
                                        <ul className="text-sm text-muted-foreground space-y-1">
                                            <li>Button text: <strong className="text-foreground">{data.settings?.button_text || 'Support'}</strong></li>
                                            <li>Position: <strong className="text-foreground">{data.settings?.button_position || 'bottom-right'}</strong></li>
                                            <li>Brand color: <span className="inline-block w-4 h-4 rounded border align-middle mr-1" style={{ backgroundColor: data.settings?.brand_color || '#0E83DC' }} /> <code className="text-xs">{data.settings?.brand_color || '#0E83DC'}</code></li>
                                        </ul>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-3">
                                        <Eye className="size-5 text-primary" />
                                        Inline embed (always visible)
                                    </CardTitle>
                                    <CardDescription>
                                        Embed an iframe to show the support form directly on the page—no button to click.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <Label>Iframe URL</Label>
                                        <pre className="rounded-lg border bg-muted/50 p-4 text-xs overflow-x-auto whitespace-pre-wrap break-all font-mono">
                                            {embedIframeUrl}
                                        </pre>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Iframe snippet</Label>
                                        <pre className="rounded-lg border bg-muted/50 p-4 text-xs overflow-x-auto whitespace-pre-wrap break-all font-mono">
                                            {embedIframeSnippet}
                                        </pre>
                                        <p className="text-xs text-muted-foreground">
                                            Adjust width and height as needed. For ticket view with replies, use: <code className="bg-muted px-1 rounded">{embedIframeUrl}?ticket=UUID&amp;token=TOKEN</code>
                                        </p>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    </Tabs>

                    <Card className="border-destructive/50 mt-8">
                        <CardHeader>
                            <CardTitle className="text-destructive flex items-center gap-3">
                                <Trash2 className="size-5" />
                                Danger Zone
                            </CardTitle>
                            <CardDescription>
                                Irreversible actions. Proceed with caution.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-between p-4 rounded-lg border border-destructive/30 bg-destructive/5">
                                <div>
                                    <h4 className="font-semibold">Delete this helpdesk</h4>
                                    <p className="text-sm text-muted-foreground">
                                        All columns and tickets will be permanently deleted.
                                    </p>
                                </div>
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button variant="destructive" className="gap-2">
                                            <Trash2 className="size-4" />
                                            Delete Helpdesk
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Delete helpdesk?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                Are you sure you want to delete "{helpdesk.name}"? This will permanently
                                                delete all columns and tickets. This action cannot be undone.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                onClick={handleDeleteHelpdesk}
                                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                            >
                                                Delete Helpdesk
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <EditColumnDialog
                    key={editingColumn?.id ?? 'closed'}
                    column={editingColumn}
                    workspace={workspace}
                    project={project}
                    helpdesk={helpdesk}
                    open={!!editingColumn}
                    onClose={() => setEditingColumn(null)}
                />
                <EditFieldDialog
                    key={editingField?.id ?? 'closed'}
                    field={editingField}
                    workspace={workspace}
                    project={project}
                    helpdesk={helpdesk}
                    open={!!editingField}
                    onClose={() => setEditingField(null)}
                />
                <Dialog open={!!editingEditableKey} onOpenChange={(open) => !open && setEditingEditableKey(null)}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Edit field</DialogTitle>
                            <DialogDescription>
                                {editingEditableKey === 'contact_name' ? 'Change the label and required state for the Name field.' : 'Change the label and required state for the Message field.'}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (!editingEditableKey) return;
                                const def = EDITABLE_DEFAULT_KEYS.find((d) => d.key === editingEditableKey);
                                if (!def) return;
                                const newSettings = { ...settings, [def.settingsKey]: { label: editableForm.label.trim() || def.defaultLabel, is_required: editableForm.is_required } };
                                router.put(route('helpdesks.update', [workspace.slug, project.slug, helpdesk.slug]), {
                                    name: data.name,
                                    description: data.description,
                                    settings: newSettings,
                                });
                                setEditingEditableKey(null);
                            }}
                            className="space-y-4 mt-4"
                        >
                            <div className="space-y-2">
                                <Label htmlFor="editable-label">Label</Label>
                                <Input
                                    id="editable-label"
                                    value={editableForm.label}
                                    onChange={(e) => setEditableForm((f) => ({ ...f, label: e.target.value }))}
                                />
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={editableForm.is_required}
                                    onChange={(e) => setEditableForm((f) => ({ ...f, is_required: e.target.checked }))}
                                    className="size-4 rounded border-input"
                                />
                                Required
                            </label>
                            <div className="flex justify-end gap-2 pt-2">
                                <Button type="button" variant="outline" onClick={() => setEditingEditableKey(null)}>
                                    Cancel
                                </Button>
                                <Button type="submit">Save</Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}

function slugify(text: string): string {
    return text
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);
}

/** Short public address for the support form, to send to customers or link from a website. */
function SupportPageCard({
    appUrl,
    savedSlug,
    value,
    onChange,
    suggestion,
    error,
    processing,
    onSave,
}: {
    appUrl: string;
    savedSlug: string;
    value: string;
    onChange: (value: string) => void;
    suggestion: string;
    error?: string;
    processing: boolean;
    onSave: () => void;
}) {
    const [copied, setCopied] = useState(false);
    const savedUrl = savedSlug ? `${appUrl}/support/${savedSlug}` : null;
    const host = appUrl.replace(/^https?:\/\//, '');

    const copy = async () => {
        if (!savedUrl) return;
        await navigator.clipboard.writeText(savedUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-3">
                    <Link2 className="size-5 text-primary" />
                    Support page
                </CardTitle>
                <CardDescription>
                    A short link to your support form as a full page. Send it to customers, put it in your email signature or link it from your website.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="public_slug">Address</Label>
                    <div className="flex items-stretch gap-2">
                        <div className="flex flex-1 items-center overflow-hidden rounded-md border bg-background focus-within:ring-2 focus-within:ring-ring">
                            <span className="whitespace-nowrap border-r bg-muted/50 px-3 py-2 text-sm text-muted-foreground">{host}/support/</span>
                            <input
                                id="public_slug"
                                value={value}
                                onChange={(e) => onChange(e.target.value)}
                                placeholder={suggestion || 'acme'}
                                maxLength={60}
                                className="min-w-0 flex-1 bg-transparent px-3 py-2 text-sm outline-none"
                            />
                        </div>
                        <Button type="button" onClick={onSave} disabled={processing || value === savedSlug}>
                            Save
                        </Button>
                    </div>
                    {error && <p className="text-sm text-destructive">{error}</p>}
                    {!value && suggestion && (
                        <button type="button" className="text-xs text-primary hover:underline" onClick={() => onChange(suggestion)}>
                            Use “{suggestion}”
                        </button>
                    )}
                </div>
                {savedUrl && (
                    <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/30 p-3">
                        <code className="flex-1 break-all text-sm">{savedUrl}</code>
                        <Button type="button" variant="outline" size="sm" onClick={copy}>
                            {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                            {copied ? 'Copied' : 'Copy'}
                        </Button>
                        <Button type="button" variant="outline" size="sm" asChild>
                            <a href={savedUrl} target="_blank" rel="noreferrer">
                                <ExternalLink className="size-4" /> Open
                            </a>
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
