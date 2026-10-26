import type { SourcePreviewProps } from '@/components/imports/sources';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';

/** JiraImporter::preview() (v1.41.0, BRAI-139). */
interface Named { name: string; count: number }
interface JiraPreviewData {
    issues: number;
    projects: { key: string; name: string }[];
    statuses: (Named & { category: 'todo' | 'in_progress' | 'done' })[];
    types: Named[];
    priorities: Named[];
    sprints: Named[];
    labels: number;
    people: Named[];
    comments: number;
    links: number;
    nested: number;
    unsupported: Record<string, number>;
    warnings: string[];
    choices: {
        types: { id: number; name: string; slug: string }[];
        priorities: { id: number; name: string }[];
        members: { id: number; name: string; email: string }[];
    };
    defaults: {
        board_name: string;
        statuses: { name: string; column: string }[];
        done_column: string;
        types: { name: string; target: string }[];
        priorities: { name: string; target: number | null }[];
        people: { name: string; user_id: number | null }[];
        import_comments: boolean;
        create_sprints: boolean;
    };
}

const CATEGORY_LABELS = { todo: 'To do', in_progress: 'In progress', done: 'Done' } as const;
const NONE = 'none';

/** Jira name → chosen value (the options are sent as lists: Jira names may contain dots) */
type ByName = Record<string, string>;
const byName = <R extends { name: string }>(rows: R[], value: (row: R) => string): ByName => Object.fromEntries(rows.map((r) => [r.name, value(r)]));
const idOrNone = (id: number | null) => (id === null ? NONE : String(id));

function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
    return (
        <section className="space-y-2" aria-label={title}>
            <div>
                <h3 className="text-sm font-semibold">{title}</h3>
                {description && <p className="text-xs text-muted-foreground">{description}</p>}
            </div>
            {children}
        </section>
    );
}

/** One "Jira value → Brainitive value" row. */
function MappingRow({ name, count, extra, children }: { name: string; count: number; extra?: ReactNode; children: ReactNode }) {
    return (
        <li className="grid items-center gap-2 py-1.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)]">
            <span className="flex min-w-0 flex-wrap items-center gap-2 text-sm">
                <span className="truncate font-medium">{name}</span>
                <span className="text-xs text-muted-foreground">{count}</span>
                {extra}
            </span>
            {children}
        </li>
    );
}

export default function JiraPreview({ run, busy, onStart }: SourcePreviewProps) {
    const preview = run.preview as unknown as JiraPreviewData;
    const { defaults, choices } = preview;

    const [boardName, setBoardName] = useState(defaults.board_name);
    const [columns, setColumns] = useState(() => byName(defaults.statuses, (r) => r.column));
    const [doneColumn, setDoneColumn] = useState(defaults.done_column);
    const [types, setTypes] = useState(() => byName(defaults.types, (r) => r.target));
    const [priorities, setPriorities] = useState(() => byName(defaults.priorities, (r) => idOrNone(r.target)));
    const [people, setPeople] = useState(() => byName(defaults.people, (r) => idOrNone(r.user_id)));
    const [importComments, setImportComments] = useState(defaults.import_comments);
    const [createSprints, setCreateSprints] = useState(defaults.create_sprints);

    // the board's columns: the distinct names, in status order; the done column always exists
    const columnNames = useMemo(() => {
        const seen = new Map<string, string>();
        preview.statuses.forEach((s) => {
            const name = (columns[s.name] ?? '').trim();
            if (name && !seen.has(name.toLowerCase())) seen.set(name.toLowerCase(), name);
        });
        return [...seen.values()];
    }, [columns, preview.statuses]);
    const doneOptions = columnNames.some((c) => c.toLowerCase() === doneColumn.trim().toLowerCase()) ? columnNames : [...columnNames, doneColumn];

    const unsupported = Object.entries(preview.unsupported ?? {}).filter(([, n]) => n > 0);
    const invalid = !boardName.trim() || preview.statuses.some((s) => !(columns[s.name] ?? '').trim());

    const start = () =>
        onStart({
            board_name: boardName.trim(),
            statuses: preview.statuses.map((s) => ({ name: s.name, column: columns[s.name].trim() })),
            done_column: doneColumn.trim(),
            types: preview.types.map((t) => ({ name: t.name, target: types[t.name] })),
            priorities: preview.priorities.map((p) => ({ name: p.name, target: priorities[p.name] === NONE ? null : Number(priorities[p.name]) })),
            people: preview.people.map((p) => ({ name: p.name, user_id: people[p.name] === NONE ? null : Number(people[p.name]) })),
            import_comments: importComments,
            create_sprints: createSprints,
        });

    const projects = preview.projects.map((p) => (p.key ? `${p.name} (${p.key})` : p.name)).join(', ');

    return (
        <Card data-testid="jira-preview">
            <CardHeader>
                <CardTitle className="text-base">
                    {preview.issues} {preview.issues === 1 ? 'issue' : 'issues'}{projects ? ` from ${projects}` : ''}
                </CardTitle>
                <CardDescription>Check how the Jira data maps to the new board, then start. Nothing has been created yet.</CardDescription>
                <ul className="flex flex-wrap gap-2 pt-1" aria-label="What the file contains">
                    {[
                        [preview.nested, 'nested under an epic or parent'],
                        [preview.comments, 'comments'],
                        [preview.links, 'issue links'],
                        [preview.labels, 'labels'],
                        [preview.sprints.length, 'sprints'],
                    ].filter(([n]) => (n as number) > 0).map(([n, what]) => (
                        <li key={what as string}><Badge variant="secondary">{n} {what}</Badge></li>
                    ))}
                </ul>
            </CardHeader>
            <CardContent className="space-y-6">
                <div className="space-y-1.5">
                    <Label htmlFor="jira-board-name">Board name</Label>
                    <Input id="jira-board-name" value={boardName} maxLength={255} onChange={(e) => setBoardName(e.target.value)} className="max-w-md" />
                </div>

                <Section title="Statuses → columns" description="Each status becomes a column. Give several statuses the same column name to merge them.">
                    <datalist id="jira-column-names">{columnNames.map((c) => <option key={c} value={c} />)}</datalist>
                    <ul className="divide-y">
                        {preview.statuses.map((s) => (
                            <MappingRow key={s.name} name={s.name} count={s.count} extra={<Badge variant="outline" className="text-[10px]">{CATEGORY_LABELS[s.category]}</Badge>}>
                                <Input
                                    aria-label={`Column for ${s.name}`}
                                    list="jira-column-names"
                                    maxLength={60}
                                    value={columns[s.name] ?? ''}
                                    onChange={(e) => setColumns((prev) => ({ ...prev, [s.name]: e.target.value }))}
                                />
                            </MappingRow>
                        ))}
                    </ul>
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        <Label htmlFor="jira-done-column" className="text-sm">Done column</Label>
                        <Select value={doneColumn} onValueChange={setDoneColumn}>
                            <SelectTrigger id="jira-done-column" aria-label="Done column" className="w-64"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                {doneOptions.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                </Section>

                {preview.types.length > 0 && (
                    <Section title="Issue types" description="Matched by name; a type the project doesn't have yet is created.">
                        <ul className="divide-y">
                            {preview.types.map((t) => (
                                <MappingRow key={t.name} name={t.name} count={t.count}>
                                    <Select value={types[t.name]} onValueChange={(v) => setTypes((prev) => ({ ...prev, [t.name]: v }))}>
                                        <SelectTrigger aria-label={`Item type for ${t.name}`}><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="create">Create “{t.name}”</SelectItem>
                                            {choices.types.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </MappingRow>
                            ))}
                        </ul>
                    </Section>
                )}

                {preview.priorities.length > 0 && (
                    <Section title="Priorities">
                        <ul className="divide-y">
                            {preview.priorities.map((p) => (
                                <MappingRow key={p.name} name={p.name} count={p.count}>
                                    <Select value={priorities[p.name]} onValueChange={(v) => setPriorities((prev) => ({ ...prev, [p.name]: v }))}>
                                        <SelectTrigger aria-label={`Priority for ${p.name}`}><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>Project default</SelectItem>
                                            {choices.priorities.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </MappingRow>
                            ))}
                        </ul>
                    </Section>
                )}

                {preview.people.length > 0 && (
                    <Section title="People" description="Assignees and reporters become project members you pick here. Nobody is invited; anyone left unassigned is kept as text in the issue.">
                        <ul className="divide-y">
                            {preview.people.map((p) => (
                                <MappingRow key={p.name} name={p.name} count={p.count}>
                                    <Select value={people[p.name]} onValueChange={(v) => setPeople((prev) => ({ ...prev, [p.name]: v }))}>
                                        <SelectTrigger aria-label={`Member for ${p.name}`}><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>Unassigned</SelectItem>
                                            {choices.members.map((m) => <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </MappingRow>
                            ))}
                        </ul>
                    </Section>
                )}

                <Section title="Options">
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" className="size-4" checked={importComments} onChange={(e) => setImportComments(e.target.checked)} disabled={preview.comments === 0} />
                        Import comments ({preview.comments}), added by you with “Originally by …” on top
                    </label>
                    {preview.sprints.length > 0 && (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" className="size-4" checked={createSprints} onChange={(e) => setCreateSprints(e.target.checked)} />
                            Create the {preview.sprints.length} sprints (makes it a Scrum board)
                        </label>
                    )}
                </Section>

                {unsupported.length > 0 && (
                    <Section title="Not imported">
                        <ul className="list-disc pl-5 text-sm text-muted-foreground" aria-label="Not imported">
                            {unsupported.map(([what, n]) => <li key={what}>{what}: {n}</li>)}
                        </ul>
                    </Section>
                )}

                {preview.warnings.length > 0 && (
                    <div className="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        <p className="flex items-center gap-2 font-medium"><AlertTriangle className="size-4" /> Notes</p>
                        <ul className="list-disc pl-5">{preview.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
                    </div>
                )}

                <Button onClick={start} disabled={busy || invalid}>
                    {busy && <Loader2 className="size-4 animate-spin" />} Start import
                </Button>
            </CardContent>
        </Card>
    );
}
