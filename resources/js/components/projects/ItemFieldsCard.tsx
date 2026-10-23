import { useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import type { ItemField, ItemFieldType, Project, Workspace } from '@/types';
import { cn } from '@/lib/utils';
import { FIELD_TYPE_LABELS } from '@/lib/customFields';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Archive, ArchiveRestore, ArrowDown, ArrowUp, Pencil, Plus, SlidersHorizontal, Trash2, X } from 'lucide-react';

const OPTION_COLORS = ['#ef4444', '#f97316', '#f59e0b', '#22c55e', '#14b8a6', '#3b82f6', '#6366f1', '#a855f7', '#ec4899', '#64748b'];

type BoardLite = { id: number; name: string; slug: string };
type OptionDraft = { key: string; id?: number; label: string; color: string | null; archived: boolean };

let draftKey = 0;
const nextKey = () => `o${++draftKey}`;

/**
 * Project settings → Fields (BRAI-77): create, edit, order, archive / restore and delete
 * custom fields for the project's board issues.
 */
export default function ItemFieldsCard({ workspace, project, fields, boards }: {
    workspace: Workspace;
    project: Project;
    fields: ItemField[];
    boards: BoardLite[];
}) {
    const [editing, setEditing] = useState<ItemField | 'new' | null>(null);
    const [deleting, setDeleting] = useState<ItemField | null>(null);
    const [reordering, setReordering] = useState(false);
    const active = fields.filter((f) => !f.archived_at);
    const archived = fields.filter((f) => f.archived_at);
    const boardName = (id: number) => boards.find((b) => b.id === id)?.name ?? 'Deleted board';

    const options = { preserveScroll: true, onError: (errors: Record<string, string>) => toast.error(Object.values(errors)[0] ?? 'Something went wrong.') };

    const setArchived = (field: ItemField, value: boolean) =>
        router.put(route('projects.fields.update', [workspace.slug, project.slug, field.id]), { name: field.name, board_ids: field.board_ids, archived: value }, options);

    const move = (index: number, delta: number) => {
        const ids = active.map((f) => f.id);
        const target = index + delta;
        if (target < 0 || target >= ids.length) return;
        [ids[index], ids[target]] = [ids[target], ids[index]];
        // One move at a time: the next swap is computed from the saved order.
        router.post(route('projects.fields.reorder', [workspace.slug, project.slug]), { ids: [...ids, ...archived.map((f) => f.id)] }, {
            ...options,
            onStart: () => setReordering(true),
            onFinish: () => setReordering(false),
        });
    };

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div className="space-y-1">
                    <CardTitle className="flex items-center gap-2">
                        <SlidersHorizontal className="size-5" />
                        Fields
                    </CardTitle>
                    <CardDescription>
                        Your own fields on issues — text, number, date, checkbox or a list of options. Filter and sort boards by them.
                    </CardDescription>
                </div>
                <Button variant="outline" size="sm" className="gap-2" onClick={() => setEditing('new')}>
                    <Plus className="size-4" />
                    Add field
                </Button>
            </CardHeader>
            <CardContent className="space-y-6">
                {active.length === 0 ? (
                    <div className="text-center py-8 text-muted-foreground">
                        <SlidersHorizontal className="size-8 mx-auto mb-2 opacity-50" />
                        <p className="text-sm">No fields yet</p>
                        <p className="text-xs">Add a field like “Customer”, “Budget” or “Go-live date”.</p>
                    </div>
                ) : (
                    <div className="space-y-2">
                        {active.map((field, index) => (
                            <div key={field.id} className="flex items-center justify-between gap-3 p-3 rounded-lg border bg-muted/20 hover:bg-muted/40 transition-colors">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <p className="font-medium truncate">{field.name}</p>
                                        <Badge variant="secondary" className="text-[10px] shrink-0">{FIELD_TYPE_LABELS[field.type]}</Badge>
                                    </div>
                                    <p className="text-xs text-muted-foreground truncate">
                                        {field.board_ids === null ? 'All boards' : field.board_ids.map(boardName).join(', ')}
                                        {' · '}
                                        {field.values_count ? `used on ${field.values_count} issue${field.values_count === 1 ? '' : 's'}` : 'not used yet'}
                                        {field.type === 'select' && ` · ${field.options.filter((o) => !o.archived_at).length} options`}
                                    </p>
                                </div>
                                <div className="flex items-center gap-0.5 shrink-0">
                                    <Button variant="ghost" size="icon" className="size-8" disabled={reordering || index === 0} onClick={() => move(index, -1)} aria-label={`Move ${field.name} up`}>
                                        <ArrowUp className="size-4" />
                                    </Button>
                                    <Button variant="ghost" size="icon" className="size-8" disabled={reordering || index === active.length - 1} onClick={() => move(index, 1)} aria-label={`Move ${field.name} down`}>
                                        <ArrowDown className="size-4" />
                                    </Button>
                                    <Button variant="ghost" size="icon" className="size-8" onClick={() => setEditing(field)} aria-label={`Edit ${field.name}`}>
                                        <Pencil className="size-4" />
                                    </Button>
                                    {field.values_count ? (
                                        <Button variant="ghost" size="icon" className="size-8" onClick={() => setArchived(field, true)} aria-label={`Archive ${field.name}`} title="Archive (values are kept)">
                                            <Archive className="size-4" />
                                        </Button>
                                    ) : (
                                        <Button variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive" onClick={() => setDeleting(field)} aria-label={`Delete ${field.name}`}>
                                            <Trash2 className="size-4" />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {archived.length > 0 && (
                    <div className="space-y-2">
                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Archived</p>
                        {archived.map((field) => (
                            <div key={field.id} className="flex items-center justify-between gap-3 p-3 rounded-lg border border-dashed opacity-80">
                                <div className="min-w-0">
                                    <p className="font-medium truncate">{field.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {FIELD_TYPE_LABELS[field.type]} · hidden from boards{field.values_count ? ` · values kept on ${field.values_count} issue${field.values_count === 1 ? '' : 's'}` : ''}
                                    </p>
                                </div>
                                <Button variant="outline" size="sm" className="gap-1.5 shrink-0" onClick={() => setArchived(field, false)}>
                                    <ArchiveRestore className="size-4" />
                                    Restore
                                </Button>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{editing === 'new' ? 'Add field' : 'Edit field'}</DialogTitle>
                        <DialogDescription>
                            Fields appear in the issue details and can be shown as columns in the board's list view.
                        </DialogDescription>
                    </DialogHeader>
                    {editing !== null && (
                        <FieldForm
                            key={editing === 'new' ? 'new' : editing.id}
                            field={editing === 'new' ? null : editing}
                            workspace={workspace}
                            project={project}
                            boards={boards}
                            onClose={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <AlertDialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete “{deleting?.name}”?</AlertDialogTitle>
                        <AlertDialogDescription>No issue uses this field yet, so nothing else is lost.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            onClick={() => deleting && router.delete(route('projects.fields.destroy', [workspace.slug, project.slug, deleting.id]), options)}
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Card>
    );
}

function FieldForm({ field, workspace, project, boards, onClose }: {
    field: ItemField | null;
    workspace: Workspace;
    project: Project;
    boards: BoardLite[];
    onClose: () => void;
}) {
    const [name, setName] = useState(field?.name ?? '');
    const [type, setType] = useState<ItemFieldType>(field?.type ?? 'text');
    const [allBoards, setAllBoards] = useState(field ? field.board_ids === null : true);
    const [boardIds, setBoardIds] = useState<number[]>(field?.board_ids ?? []);
    const [options, setOptions] = useState<OptionDraft[]>(() =>
        (field?.options ?? []).map((o) => ({ key: nextKey(), id: o.id, label: o.label, color: o.color, archived: !!o.archived_at })));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const typeLocked = !!field?.values_count;

    const activeOptions = options.filter((o) => !o.archived);
    const archivedOptions = options.filter((o) => o.archived);
    const updateOption = (key: string, patch: Partial<OptionDraft>) => setOptions((all) => all.map((o) => (o.key === key ? { ...o, ...patch } : o)));
    const moveOption = (key: string, delta: number) => setOptions((all) => {
        const visible = all.filter((o) => !o.archived);
        const index = visible.findIndex((o) => o.key === key);
        const target = index + delta;
        if (target < 0 || target >= visible.length) return all;
        [visible[index], visible[target]] = [visible[target], visible[index]];
        return [...visible, ...all.filter((o) => o.archived)];
    });
    // New options disappear; saved ones are archived on save (issues keep showing them).
    const removeOption = (option: OptionDraft) => (option.id
        ? updateOption(option.key, { archived: true })
        : setOptions((all) => all.filter((o) => o.key !== option.key)));

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const data = {
            name,
            type,
            board_ids: allBoards ? null : boardIds,
            ...(type === 'select' ? { options: activeOptions.map((o) => ({ id: o.id ?? null, label: o.label, color: o.color })) } : {}),
        };
        const visit = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: onClose,
            onError: (errs: Record<string, string>) => setErrors(errs),
        };
        if (field) router.put(route('projects.fields.update', [workspace.slug, project.slug, field.id]), data, visit);
        else router.post(route('projects.fields.store', [workspace.slug, project.slug]), data, visit);
    };

    const optionError = (index: number) => errors[`options.${index}.label`] ?? errors[`options.${index}.id`];

    return (
        <form onSubmit={submit} className="space-y-5">
            <div className="space-y-2">
                <Label htmlFor="field-name">Name</Label>
                <Input id="field-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={100} placeholder="e.g. Customer, Budget, Go-live date" required autoFocus />
                {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
            </div>

            <div className="space-y-2">
                <Label>Type</Label>
                <Select value={type} onValueChange={(v) => setType(v as ItemFieldType)} disabled={typeLocked}>
                    <SelectTrigger aria-label="Field type"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        {(Object.keys(FIELD_TYPE_LABELS) as ItemFieldType[]).map((t) => (
                            <SelectItem key={t} value={t}>{FIELD_TYPE_LABELS[t]}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {typeLocked && (
                    <p className="text-xs text-muted-foreground">
                        {field!.values_count} issue{field!.values_count === 1 ? ' uses' : 's use'} this field, so its type is fixed. To change it, add a new field and archive this one.
                    </p>
                )}
                {errors.type && <p className="text-sm text-destructive">{errors.type}</p>}
            </div>

            <div className="space-y-2">
                <Label>Boards</Label>
                <div className="flex gap-2">
                    <Button type="button" size="sm" variant={allBoards ? 'secondary' : 'ghost'} aria-pressed={allBoards} onClick={() => setAllBoards(true)}>All boards</Button>
                    <Button type="button" size="sm" variant={!allBoards ? 'secondary' : 'ghost'} aria-pressed={!allBoards} onClick={() => setAllBoards(false)} disabled={boards.length === 0}>Selected boards</Button>
                </div>
                {!allBoards && (
                    <div className="space-y-1 rounded-md border p-2 max-h-40 overflow-y-auto">
                        {boards.map((board) => (
                            <label key={board.id} className="flex items-center gap-2 px-1 py-1 text-sm cursor-pointer rounded hover:bg-muted">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-primary"
                                    checked={boardIds.includes(board.id)}
                                    onChange={(e) => setBoardIds((ids) => (e.target.checked ? [...ids, board.id] : ids.filter((id) => id !== board.id)))}
                                />
                                {board.name}
                            </label>
                        ))}
                    </div>
                )}
                {(errors.board_ids || Object.keys(errors).some((k) => k.startsWith('board_ids.'))) && (
                    <p className="text-sm text-destructive">{errors.board_ids ?? 'Choose boards of this project.'}</p>
                )}
            </div>

            {type === 'select' && (
                <div className="space-y-2">
                    <Label>Options</Label>
                    <div className="space-y-1.5">
                        {activeOptions.map((option, index) => (
                            <div key={option.key}>
                                <div className="flex items-center gap-1.5">
                                    <ColorPicker value={option.color} onChange={(color) => updateOption(option.key, { color })} />
                                    <Input
                                        value={option.label}
                                        onChange={(e) => updateOption(option.key, { label: e.target.value })}
                                        maxLength={100}
                                        placeholder={`Option ${index + 1}`}
                                        className="h-8"
                                        aria-label={`Option ${index + 1}`}
                                        required
                                    />
                                    <Button type="button" variant="ghost" size="icon" className="size-8 shrink-0" disabled={index === 0} onClick={() => moveOption(option.key, -1)} aria-label="Move option up">
                                        <ArrowUp className="size-3.5" />
                                    </Button>
                                    <Button type="button" variant="ghost" size="icon" className="size-8 shrink-0" disabled={index === activeOptions.length - 1} onClick={() => moveOption(option.key, 1)} aria-label="Move option down">
                                        <ArrowDown className="size-3.5" />
                                    </Button>
                                    <Button type="button" variant="ghost" size="icon" className="size-8 shrink-0" onClick={() => removeOption(option)} aria-label={`Remove ${option.label || 'option'}`}>
                                        <X className="size-3.5" />
                                    </Button>
                                </div>
                                {optionError(index) && <p className="text-xs text-destructive mt-1">{optionError(index)}</p>}
                            </div>
                        ))}
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="gap-1.5"
                        onClick={() => setOptions((all) => [...all.filter((o) => !o.archived), { key: nextKey(), label: '', color: null, archived: false }, ...all.filter((o) => o.archived)])}
                    >
                        <Plus className="size-3.5" />
                        Add option
                    </Button>
                    {archivedOptions.length > 0 && (
                        <div className="pt-1 text-xs text-muted-foreground">
                            Removed options (issues that have them keep them):{' '}
                            {archivedOptions.map((option, i) => (
                                <span key={option.key}>
                                    {i > 0 && ', '}
                                    {option.label}{' '}
                                    <button type="button" className="text-primary hover:underline" onClick={() => updateOption(option.key, { archived: false })}>restore</button>
                                </span>
                            ))}
                        </div>
                    )}
                    {errors.options && <p className="text-sm text-destructive">{errors.options}</p>}
                </div>
            )}

            <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                <Button type="submit" disabled={processing}>{processing ? 'Saving…' : field ? 'Save changes' : 'Add field'}</Button>
            </div>
        </form>
    );
}

function ColorPicker({ value, onChange }: { value: string | null; onChange: (color: string | null) => void }) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn('size-8 shrink-0 rounded-md border flex items-center justify-center hover:bg-muted', !value && 'border-dashed')}
                    aria-label="Option color"
                >
                    <span className="size-3.5 rounded-full" style={{ backgroundColor: value ?? 'transparent', border: value ? undefined : '1px dashed currentColor' }} />
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-2" align="start">
                <div className="flex flex-wrap gap-1.5 max-w-[11rem]">
                    {OPTION_COLORS.map((color) => (
                        <button
                            key={color}
                            type="button"
                            className={cn('size-6 rounded-full transition-transform hover:scale-110', value === color && 'ring-2 ring-offset-2 ring-primary ring-offset-background')}
                            style={{ backgroundColor: color }}
                            onClick={() => onChange(color)}
                            aria-label={color}
                        />
                    ))}
                    <button type="button" className="h-6 px-2 rounded-full border text-[11px] text-muted-foreground hover:bg-muted" onClick={() => onChange(null)}>None</button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
