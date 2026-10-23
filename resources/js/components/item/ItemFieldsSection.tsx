import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import type { Board, Item, ItemField, Project, Workspace } from '@/types';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { fieldValue, formatFieldValue, selectableOptions, type FieldValue } from '@/lib/customFields';

const TRIGGER = 'h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 transition-colors focus:ring-0 text-sm px-2 -ml-2 rounded-md';
const INPUT = 'h-8 border-0 shadow-none bg-transparent hover:bg-muted/80 focus:bg-muted/80 transition-colors focus-visible:ring-0 text-sm px-0 rounded-md';

/**
 * Custom field rows for the issue sidebar (BRAI-77): one typed editor per field, saved on
 * blur / change. Viewers see the values read-only.
 */
export function ItemFieldsSection({ item, fields, canEdit, workspace, project, board }: {
    item: Item;
    fields: ItemField[];
    canEdit: boolean;
    workspace: Workspace;
    project: Project;
    board: Board;
}) {
    const save = (field: ItemField, value: FieldValue) => {
        router.put(
            route('boards.items.fields.update', [workspace.slug, project.slug, board.slug, item.id]),
            { values: { [field.id]: value } },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => toast.error(Object.values(errors)[0] ?? `Couldn't save ${field.name}.`),
            },
        );
    };

    if (fields.length === 0) return null;

    return (
        <>
            {fields.map((field) => (
                <div key={field.id} className="flex items-center min-h-[40px] py-1">
                    <span className="w-[40%] shrink-0 text-xs text-muted-foreground truncate pr-2" title={field.name}>{field.name}</span>
                    <div className="flex-1 min-w-0">
                        {canEdit
                            ? <FieldEditor field={field} value={fieldValue(item, field)} onSave={(v) => save(field, v)} itemId={item.id} />
                            : <ReadOnlyValue field={field} value={fieldValue(item, field)} />}
                    </div>
                </div>
            ))}
        </>
    );
}

function ReadOnlyValue({ field, value }: { field: ItemField; value: FieldValue }) {
    const text = formatFieldValue(field, value);
    return text
        ? <span className="block truncate px-0 text-sm" title={text}>{text}</span>
        : <span className="text-sm text-muted-foreground">None</span>;
}

function FieldEditor({ field, value, onSave, itemId }: { field: ItemField; value: FieldValue; onSave: (value: FieldValue) => void; itemId: number }) {
    // Draft for typed inputs; reset when the stored value or the issue changes.
    const stored = value === null ? '' : String(value);
    const [draft, setDraft] = useState(stored);
    useEffect(() => setDraft(stored), [stored, itemId]);

    switch (field.type) {
        case 'select': {
            const options = selectableOptions(field, value);
            return (
                <Select value={value === null ? 'none' : String(value)} onValueChange={(v) => onSave(v === 'none' ? null : Number(v))}>
                    <SelectTrigger className={TRIGGER} aria-label={field.name}>
                        <SelectValue placeholder="None" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">None</SelectItem>
                        {options.map((o) => (
                            <SelectItem key={o.id} value={String(o.id)}>
                                <span className="inline-flex items-center gap-2">
                                    {o.color && <span className="size-2.5 rounded-full" style={{ backgroundColor: o.color }} />}
                                    {o.label}
                                    {o.archived_at && <span className="text-xs text-muted-foreground">(archived)</span>}
                                </span>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            );
        }
        case 'checkbox':
            // Three states: yes, no and not set (false is a value, not "empty").
            return (
                <Select value={value === null ? 'none' : value ? 'yes' : 'no'} onValueChange={(v) => onSave(v === 'none' ? null : v === 'yes')}>
                    <SelectTrigger className={TRIGGER} aria-label={field.name}>
                        <SelectValue placeholder="None" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">None</SelectItem>
                        <SelectItem value="yes">Yes</SelectItem>
                        <SelectItem value="no">No</SelectItem>
                    </SelectContent>
                </Select>
            );
        case 'date': {
            // Saved on blur / Enter: typing a year fires a change per digit (0002, 0020, …).
            const commit = () => { if (draft !== stored) onSave(draft || null); };
            return (
                <div className="px-2 -ml-2">
                    <Input
                        type="date"
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onBlur={commit}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') (e.target as HTMLInputElement).blur();
                            if (e.key === 'Escape') setDraft(stored);
                        }}
                        className={INPUT}
                        aria-label={field.name}
                    />
                </div>
            );
        }
        default: {
            const commit = () => {
                const next = draft.trim();
                if (next === stored) return;
                if (field.type === 'number' && next !== '' && !Number.isFinite(Number(next))) {
                    toast.error(`${field.name}: enter a number.`);
                    setDraft(stored);
                    return;
                }
                onSave(next === '' ? null : field.type === 'number' ? Number(next) : next);
            };
            return (
                <div className="px-2 -ml-2">
                    <Input
                        type={field.type === 'number' ? 'number' : 'text'}
                        step="any"
                        maxLength={field.type === 'text' ? 1000 : undefined}
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onBlur={commit}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') (e.target as HTMLInputElement).blur();
                            if (e.key === 'Escape') setDraft(stored);
                        }}
                        className={INPUT}
                        placeholder="None"
                        aria-label={field.name}
                    />
                </div>
            );
        }
    }
}
