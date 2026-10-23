import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { CustomLane, LaneRules, MODE_LABELS, SwimlaneConfig, SwimlaneMode, canonicalConfig, describeRules } from '@/lib/swimlanes';
import type { Board, Project, Workspace } from '@/types';
import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronDown, Plus, Rows3, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

export interface LaneOptions {
    priorities: { id: number; name: string; color?: string | null }[];
    types: { id: number; name: string; color?: string | null }[];
    labels: { id: number; name: string; color?: string | null }[];
    users: { id: number; name: string }[];
    releases: { id: number; name: string; status: string }[];
}

const COLORS = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#0ea5e9', '#6366f1', '#a855f7', '#ec4899', '#64748b'];
const MAX_LANES = 20;

type RuleKey = Exclude<keyof LaneRules, 'overdue'>;

/** "Priority: High, Highest ▾" — a compact multi-select for one condition. */
function RulePicker({ label, value, options, onChange }: {
    label: string;
    value: number[] | undefined;
    options: { id: number; name: string; color?: string | null }[];
    onChange: (ids: number[]) => void;
}) {
    const selected = new Set(value ?? []);
    const names = options.filter((o) => selected.has(o.id)).map((o) => o.name);
    const toggle = (id: number) => {
        const next = new Set(selected);
        if (next.has(id)) next.delete(id); else next.add(id);
        onChange([...next]);
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'inline-flex max-w-full items-center gap-1 rounded-md border px-2 py-1 text-xs transition-colors',
                        names.length ? 'border-primary/40 bg-primary/5 text-foreground' : 'border-dashed text-muted-foreground hover:text-foreground',
                    )}
                >
                    <span className="font-medium">{label}:</span>
                    <span className="truncate">{names.length ? names.join(', ') : 'any'}</span>
                    <ChevronDown className="size-3 shrink-0" />
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-64 p-1" align="start">
                {options.length === 0 && <p className="p-2 text-xs text-muted-foreground">Nothing to choose yet.</p>}
                <div className="max-h-64 overflow-y-auto">
                    {options.map((o) => (
                        <label key={o.id} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted">
                            <input type="checkbox" className="size-4 accent-primary" checked={selected.has(o.id)} onChange={() => toggle(o.id)} />
                            {o.color && <span className="size-2.5 shrink-0 rounded-full" style={{ backgroundColor: o.color }} />}
                            <span className="truncate">{o.name}</span>
                        </label>
                    ))}
                </div>
                {selected.size > 0 && (
                    <button type="button" className="mt-1 w-full rounded px-2 py-1 text-left text-xs text-muted-foreground hover:bg-muted" onClick={() => onChange([])}>
                        Clear (any {label.toLowerCase()})
                    </button>
                )}
            </PopoverContent>
        </Popover>
    );
}

export default function SwimlaneSettings({ workspace, project, board, options }: { workspace: Workspace; project: Project; board: Board; options: LaneOptions }) {
    const initial = useMemo(() => canonicalConfig(board.settings?.swimlanes), [board.settings]);
    const [config, setConfig] = useState<SwimlaneConfig>(initial);
    const [saving, setSaving] = useState(false);
    const dirty = JSON.stringify(canonicalConfig(config)) !== JSON.stringify(initial);

    const choices: Record<RuleKey, { label: string; options: { id: number; name: string; color?: string | null }[] }> = {
        priority_ids: { label: 'Priority', options: options.priorities },
        type_ids: { label: 'Type', options: options.types },
        label_ids: { label: 'Label', options: options.labels },
        assignee_ids: { label: 'Assignee', options: [{ id: 0, name: 'Unassigned' }, ...options.users] },
        release_ids: { label: 'Fix version', options: [{ id: 0, name: 'No fix version' }, ...options.releases.map((r) => ({ id: r.id, name: r.status === 'released' ? `${r.name} (released)` : r.name }))] },
    };
    const names = {
        priorities: new Map(options.priorities.map((p) => [p.id, p.name])),
        types: new Map(options.types.map((t) => [t.id, t.name])),
        labels: new Map(options.labels.map((l) => [l.id, l.name])),
        users: new Map(options.users.map((u) => [u.id, u.name])),
        releases: new Map(options.releases.map((r) => [r.id, r.name])),
    };

    const updateLane = (index: number, patch: Partial<CustomLane>) =>
        setConfig((c) => ({ ...c, lanes: c.lanes.map((l, i) => (i === index ? { ...l, ...patch } : l)) }));
    const setRule = (index: number, key: keyof LaneRules, value: number[] | boolean) =>
        setConfig((c) => ({ ...c, lanes: c.lanes.map((l, i) => (i === index ? { ...l, rules: { ...l.rules, [key]: value } } : l)) }));
    const move = (index: number, delta: number) => setConfig((c) => {
        const lanes = [...c.lanes];
        const [lane] = lanes.splice(index, 1);
        lanes.splice(index + delta, 0, lane);
        return { ...c, lanes };
    });
    const addLane = () => setConfig((c) => ({
        ...c,
        mode: c.lanes.length === 0 && c.mode === 'none' ? 'custom' : c.mode,
        lanes: [...c.lanes, { id: `lane-${Math.random().toString(36).slice(2, 10)}`, name: c.lanes.length === 0 ? 'High priority' : `Lane ${c.lanes.length + 1}`, color: COLORS[c.lanes.length % COLORS.length], rules: {} }],
    }));

    const save = () => {
        setSaving(true);
        router.put(route('boards.swimlanes.update', [workspace.slug, project.slug, board.slug]), config as never, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                // Continue from what the server stored (it drops unknown ids and empty conditions).
                setConfig(canonicalConfig((page.props.board as Board).settings?.swimlanes));
                toast.success('Swimlanes saved.');
            },
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not save the swimlanes.'),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <Card id="swimlanes" className="scroll-mt-6">
            <CardHeader>
                <CardTitle className="flex items-center gap-2"><Rows3 className="size-5" /> Swimlanes</CardTitle>
                <CardDescription>
                    Rows across the board. Add as many lanes as you like, each with its own conditions — a card goes into the <strong>first</strong> lane that matches, everything else into the last lane.
                    Everyone can still switch lanes for themselves in the board toolbar.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
                <div className="max-w-xs space-y-1.5">
                    <Label>Default for everyone</Label>
                    <Select value={config.mode} onValueChange={(v) => setConfig((c) => ({ ...c, mode: v as SwimlaneMode }))}>
                        <SelectTrigger aria-label="Default lanes"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            {(Object.keys(MODE_LABELS) as SwimlaneMode[]).map((m) => <SelectItem key={m} value={m}>{MODE_LABELS[m]}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>

                <div className="space-y-2">
                    <Label>Custom lanes</Label>
                    {config.lanes.length === 0 && (
                        <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                            No custom lanes yet. Example: “🔥 Urgent” (priority Highest or High), then “Bugs” (type Bug), then everything else.
                        </p>
                    )}
                    <ol className="space-y-2">
                        {config.lanes.map((lane, index) => (
                            <li key={lane.id} className="rounded-md border bg-card p-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="w-5 text-center text-xs font-semibold text-muted-foreground">{index + 1}</span>
                                    <div className="flex items-center gap-1" role="radiogroup" aria-label={`Color of lane ${lane.name}`}>
                                        {COLORS.map((color) => (
                                            <button
                                                key={color}
                                                type="button"
                                                role="radio"
                                                aria-checked={lane.color === color}
                                                aria-label={color}
                                                onClick={() => updateLane(index, { color })}
                                                className={cn('size-4 rounded-full border-2', lane.color === color ? 'border-foreground' : 'border-transparent')}
                                                style={{ backgroundColor: color }}
                                            />
                                        ))}
                                    </div>
                                    <Input
                                        value={lane.name}
                                        onChange={(e) => updateLane(index, { name: e.target.value })}
                                        maxLength={60}
                                        className="h-8 min-w-[10rem] flex-1"
                                        aria-label={`Name of lane ${index + 1}`}
                                    />
                                    <div className="flex items-center">
                                        <Button type="button" variant="ghost" size="icon" className="size-8" disabled={index === 0} onClick={() => move(index, -1)} aria-label="Move lane up"><ArrowUp className="size-4" /></Button>
                                        <Button type="button" variant="ghost" size="icon" className="size-8" disabled={index === config.lanes.length - 1} onClick={() => move(index, 1)} aria-label="Move lane down"><ArrowDown className="size-4" /></Button>
                                        <Button type="button" variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive" onClick={() => setConfig((c) => ({ ...c, lanes: c.lanes.filter((_, i) => i !== index) }))} aria-label={`Delete lane ${lane.name}`}><Trash2 className="size-4" /></Button>
                                    </div>
                                </div>
                                <div className="mt-2 flex flex-wrap items-center gap-1.5 pl-7">
                                    {(Object.keys(choices) as RuleKey[]).map((key) => (
                                        <RulePicker key={key} label={choices[key].label} value={lane.rules[key]} options={choices[key].options} onChange={(ids) => setRule(index, key, ids)} />
                                    ))}
                                    <label className={cn('inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 text-xs', lane.rules.overdue ? 'border-primary/40 bg-primary/5' : 'border-dashed text-muted-foreground')}>
                                        <input type="checkbox" className="size-3.5 accent-primary" checked={!!lane.rules.overdue} onChange={(e) => setRule(index, 'overdue', e.target.checked)} />
                                        Overdue
                                    </label>
                                </div>
                                <p className="mt-1.5 pl-7 text-xs text-muted-foreground">Gets: {describeRules(lane.rules, names)}{index > 0 ? ' (not already in a lane above)' : ''}</p>
                            </li>
                        ))}
                    </ol>
                    {config.lanes.length > 0 && (
                        <div className="flex items-center gap-2 rounded-md border border-dashed p-3">
                            <span className="w-5 text-center text-xs font-semibold text-muted-foreground">{config.lanes.length + 1}</span>
                            <Input value={config.rest_name} onChange={(e) => setConfig((c) => ({ ...c, rest_name: e.target.value }))} maxLength={60} className="h-8 max-w-xs" aria-label="Name of the last lane" />
                            <span className="text-xs text-muted-foreground">all other cards</span>
                        </div>
                    )}
                    <Button type="button" variant="outline" onClick={addLane} disabled={config.lanes.length >= MAX_LANES}><Plus className="size-4" /> Add lane</Button>
                </div>

                <div className="flex items-center gap-3 border-t pt-4">
                    <Button onClick={save} disabled={!dirty || saving || config.lanes.some((l) => !l.name.trim())}>Save swimlanes</Button>
                    {dirty && <span className="text-xs text-amber-700 dark:text-amber-400">Unsaved changes</span>}
                    {config.mode === 'custom' && config.lanes.length === 0 && <span className="text-xs text-muted-foreground">Add at least one lane for “Custom lanes”.</span>}
                </div>
            </CardContent>
        </Card>
    );
}
