import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { HealthClaim, HealthMetricKey, HealthSection, HealthSource, HealthStatus, Project, ProjectHealthData, Workspace } from '@/types';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Loader2, Plus, Quote, Sparkles, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { METRIC_LABELS, STATUSES, STATUS_META, SourceChip } from './HealthBits';

const SECTIONS: { key: HealthSection; title: string; hint: string }[] = [
    { key: 'progress', title: 'Progress', hint: 'What got done.' },
    { key: 'risks', title: 'Risks', hint: 'What is late, blocked or unclear.' },
    { key: 'next_steps', title: 'Next steps', hint: 'What happens next, and who needs to act.' },
];

const MAX_POINTS = 10;

/** A point being edited; `uid` is a client-side key (stable while points are removed). */
type DraftClaim = HealthClaim & { uid: number };

type Sections = Record<HealthSection, DraftClaim[]>;

let nextUid = 0;
const draftClaim = (claim: HealthClaim = { text: '', sources: [] }): DraftClaim => ({ text: claim.text, sources: [...claim.sources], uid: ++nextUid });

const emptySections = (): Sections => ({ progress: [], risks: [], next_steps: [] });

interface Props {
    workspace: Workspace;
    project: Project;
    health: ProjectHealthData;
    aiConfigured: boolean;
}

/** Write (optionally AI-drafted) a status update: pick a status, review points + sources, save. */
export function HealthReportEditor({ workspace, project, health, aiConfigured }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [status, setStatus] = useState<HealthStatus | null>(null);
    const [summary, setSummary] = useState('');
    const [sections, setSections] = useState<Sections>(emptySections);
    const [aiSources, setAiSources] = useState<Record<string, HealthSource>>({});
    const [aiAssisted, setAiAssisted] = useState(false);
    const [drafting, setDrafting] = useState(false);
    const [aiError, setAiError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // everything on the page can be cited: metrics, listed issues, milestone plans
    const options = useMemo(() => {
        const metrics: HealthSource[] = (Object.keys(METRIC_LABELS) as HealthMetricKey[]).map((key) => ({
            ref: `metric:${key}`, available: true, type: 'metric', url: null,
            label: `${METRIC_LABELS[key]}: ${health.values[key] ?? 'Unknown'}`,
        }));
        const issues = new Map<string, HealthSource>();
        const addIssue = (i: { ref: string; key: string; title: string; url: string }) => issues.set(i.ref, { ref: i.ref, available: true, type: 'item', label: `${i.key} ${i.title}`, url: i.url });
        [...health.lists.overdue, ...health.lists.due_soon, ...health.lists.unassigned, ...health.lists.completed].forEach(addIssue);
        health.lists.blocked.forEach((b) => { addIssue(b.item); b.blockers.filter((x) => !x.external).forEach(addIssue); });
        const plans = new Map<string, HealthSource>();
        health.lists.milestones.forEach((m) => plans.set(m.ref, { ref: m.ref, available: true, type: 'plan', label: m.plan, url: m.url }));
        return { metrics, issues: [...issues.values()], plans: [...plans.values()] };
    }, [health]);

    const sources = useMemo(() => {
        const map: Record<string, HealthSource> = { ...aiSources };
        [...options.metrics, ...options.issues, ...options.plans].forEach((s) => { map[s.ref] = s; });
        return map;
    }, [aiSources, options]);

    const update = (section: HealthSection, index: number, claim: DraftClaim | null) => setSections((current) => ({
        ...current,
        [section]: claim === null ? current[section].filter((_, i) => i !== index) : current[section].map((c, i) => (i === index ? claim : c)),
    }));

    const draft = async () => {
        setDrafting(true);
        setAiError(null);
        try {
            const { data } = await axios.post(route('projects.health.narrative', [workspace.slug, project.slug]), {
                tz: health.timezone, from: health.period.from, to: health.period.to,
            });
            const drafted = data.sections as Record<HealthSection, HealthClaim[]>;
            setSections({
                progress: (drafted.progress ?? []).map((c) => draftClaim(c)),
                risks: (drafted.risks ?? []).map((c) => draftClaim(c)),
                next_steps: (drafted.next_steps ?? []).map((c) => draftClaim(c)),
            });
            setAiSources(data.sources ?? {});
            setAiAssisted(true);
            toast.success(data.dropped > 0
                ? `Draft ready. ${data.dropped} point${data.dropped === 1 ? '' : 's'} without a valid source ${data.dropped === 1 ? 'was' : 'were'} left out — review before saving.`
                : 'Draft ready — review every point before saving.');
        } catch (error) {
            const message = axios.isAxiosError(error) ? error.response?.data?.message : null;
            setAiError(message ?? 'The AI draft failed. Write the update yourself, or try again.');
        } finally {
            setDrafting(false);
        }
    };

    const save = () => {
        setSaving(true);
        router.post(route('projects.health.reports.store', [workspace.slug, project.slug]), {
            status: status ?? '',
            summary,
            sections: Object.fromEntries(SECTIONS.map(({ key }) => [
                key,
                sections[key].filter((c) => c.text.trim() !== '' || c.sources.length > 0).map((c) => ({ text: c.text, sources: [...c.sources] })),
            ])),
            ai_assisted: aiAssisted,
            tz: health.timezone,
            from: health.period.from,
            to: health.period.to,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Status update saved.');
                setStatus(null);
                setSummary('');
                setSections(emptySections());
                setAiSources({});
                setAiAssisted(false);
            },
            onFinish: () => setSaving(false),
        });
    };

    const sectionErrors = Object.entries(errors).filter(([key]) => key.startsWith('sections'));

    return (
        <Card>
            <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <CardTitle className="text-base">Write a status update</CardTitle>
                    <CardDescription>Pick the health status yourself — the numbers above are evidence, not a verdict.</CardDescription>
                </div>
                {aiConfigured ? (
                    <Button variant="outline" size="sm" onClick={draft} disabled={drafting} className="shrink-0">
                        {drafting ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}
                        Draft with AI
                    </Button>
                ) : (
                    <p className="max-w-xs text-xs text-muted-foreground" data-testid="ai-not-configured">
                        <Sparkles className="mr-1 inline size-3.5" />
                        AI isn’t set up for this workspace. Write the update yourself, or ask a workspace admin to add an AI provider in workspace settings.
                    </p>
                )}
            </CardHeader>
            <CardContent className="space-y-5">
                {aiError && <p role="alert" className="rounded-md border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{aiError}</p>}

                <div>
                    <Label id="health-status-label">Health status</Label>
                    <div role="radiogroup" aria-labelledby="health-status-label" className="mt-2 flex flex-wrap gap-2">
                        {STATUSES.map((value, i) => (
                            <button
                                key={value}
                                type="button"
                                role="radio"
                                aria-checked={status === value}
                                // one tab stop; arrow keys move between the options (radio group pattern)
                                tabIndex={status === value || (status === null && i === 0) ? 0 : -1}
                                onClick={() => setStatus(value)}
                                onKeyDown={(e) => {
                                    const step = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0;
                                    if (step === 0) return;
                                    e.preventDefault();
                                    const next = STATUSES[(i + step + STATUSES.length) % STATUSES.length];
                                    setStatus(next);
                                    (e.currentTarget.parentElement?.children[STATUSES.indexOf(next)] as HTMLElement | undefined)?.focus();
                                }}
                                className={cn(
                                    'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition-colors',
                                    status === value ? STATUS_META[value].badge + ' ring-2 ring-ring/30' : 'hover:bg-muted',
                                )}
                            >
                                <span className={cn('size-2 rounded-full', STATUS_META[value].dot)} aria-hidden />
                                {STATUS_META[value].label}
                            </button>
                        ))}
                    </div>
                    {errors.status && <p className="mt-1 text-sm text-destructive">{errors.status}</p>}
                </div>

                <div>
                    <Label htmlFor="health-summary">Summary <span className="font-normal text-muted-foreground">(optional)</span></Label>
                    <Textarea id="health-summary" value={summary} onChange={(e) => setSummary(e.target.value)} rows={2} maxLength={5000} className="mt-1.5" placeholder="One or two sentences for the team." />
                </div>

                {aiAssisted && (
                    <p className="text-xs text-muted-foreground">AI draft — check every point against its sources. Points can only cite this project’s issues, plans and metrics.</p>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    {SECTIONS.map(({ key, title, hint }) => (
                        <section key={key} aria-label={title} className="rounded-lg border p-3">
                            <h3 className="text-sm font-semibold">{title}</h3>
                            <p className="mb-2 text-xs text-muted-foreground">{hint}</p>
                            <ul className="space-y-3">
                                {sections[key].map((claim, index) => (
                                    <li key={claim.uid} className="space-y-1.5">
                                        <div className="flex items-start gap-1">
                                            <Textarea
                                                aria-label={`${title} point ${index + 1}`}
                                                value={claim.text}
                                                onChange={(e) => update(key, index, { ...claim, text: e.target.value })}
                                                rows={2}
                                                maxLength={1000}
                                                className="min-h-[3.5rem] text-sm"
                                            />
                                            <Button type="button" variant="ghost" size="icon" className="size-8 shrink-0" aria-label={`Remove ${title} point ${index + 1}`} onClick={() => update(key, index, null)}>
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-1">
                                            {claim.sources.map((ref) => (
                                                <SourceChip key={ref} refKey={ref} source={sources[ref]} onRemove={() => update(key, index, { ...claim, sources: claim.sources.filter((s) => s !== ref) })} />
                                            ))}
                                            <SourcePicker
                                                options={options}
                                                selected={claim.sources}
                                                onPick={(ref) => update(key, index, { ...claim, sources: [...claim.sources, ref].slice(0, 10) })}
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            {sections[key].length < MAX_POINTS && (
                                <Button type="button" variant="ghost" size="sm" className="mt-2 h-7 px-2 text-xs" onClick={() => setSections((c) => ({ ...c, [key]: [...c[key], draftClaim()] }))}>
                                    <Plus className="size-3.5" /> Add point
                                </Button>
                            )}
                        </section>
                    ))}
                </div>

                {sectionErrors.length > 0 && (
                    <div role="alert" className="text-sm text-destructive">{sectionErrors.map(([k, v]) => <p key={k}>{v}</p>)}</div>
                )}

                <div className="flex flex-wrap items-center justify-end gap-3">
                    <p className="text-xs text-muted-foreground">The numbers are recalculated and stored when you save.</p>
                    <Button onClick={save} disabled={saving}>
                        {saving && <Loader2 className="size-4 animate-spin" />}
                        Save status update
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

function SourcePicker({ options, selected, onPick }: { options: { metrics: HealthSource[]; issues: HealthSource[]; plans: HealthSource[] }; selected: string[]; onPick: (ref: string) => void }) {
    const [open, setOpen] = useState(false);
    const groups: [string, HealthSource[]][] = [['Metrics', options.metrics], ['Issues', options.issues], ['Plans', options.plans]];

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button type="button" variant="ghost" size="sm" className="h-6 px-1.5 text-[11px] text-muted-foreground" disabled={selected.length >= 10}>
                    <Quote className="size-3" /> Cite source
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 p-0" align="start">
                <Command>
                    <CommandInput placeholder="Search metrics, issues, plans…" />
                    <CommandList>
                        <CommandEmpty>Nothing to cite.</CommandEmpty>
                        {groups.map(([heading, items]) => items.length > 0 && (
                            <CommandGroup key={heading} heading={heading}>
                                {items.filter((s) => !selected.includes(s.ref)).map((s) => (
                                    <CommandItem key={s.ref} value={`${s.ref} ${s.label}`} onSelect={() => { onPick(s.ref); setOpen(false); }}>
                                        <span className="truncate">{s.label}</span>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
