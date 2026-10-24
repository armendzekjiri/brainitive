import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Link, router } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    AlertTriangle, ArrowLeft, CheckCircle2, CopyCheck, ExternalLink, FileText, ListChecks, Loader2, Quote, Sparkles,
} from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type {
    MeetingNotesCreateResult, MeetingNotesExtraction, MeetingNotesOptions, MeetingNotesProposal,
} from '@/types';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspace: { slug: string };
    project: { slug: string; name: string };
    /** Doc entry point: read this doc. Without it the user pastes the notes. */
    pageUuid?: string | null;
}

type Step = 'setup' | 'review' | 'done';

/** The user's edits of one proposal. `assignee`: member id, 'none', or null = still to choose. */
interface Draft {
    selected: boolean;
    title: string;
    description: string;
    assignee: string | null;
    dueDate: string;
}

const NONE = 'none';

const newKey = (): string =>
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });

function draftFor(p: MeetingNotesProposal): Draft {
    const assignee = p.assignee?.status === 'matched' && p.assignee.user_id
        ? String(p.assignee.user_id)
        : p.assignee?.status === 'ambiguous' ? null : NONE;
    return { selected: p.selected, title: p.title, description: p.description, assignee, dueDate: p.due?.date ?? '' };
}

/**
 * Meeting notes → issues (BRAI-62). Three steps: pick the board and the notes, review the AI's
 * proposals next to the sentence each one comes from, create the selected ones. Extraction
 * changes nothing; Create runs once per review (idempotency key), so a retry never duplicates.
 */
export default function ExtractActionItemsDialog({ open, onOpenChange, workspace, project, pageUuid = null }: Props) {
    const [step, setStep] = useState<Step>('setup');
    const [options, setOptions] = useState<MeetingNotesOptions | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [boardId, setBoardId] = useState('');
    const [text, setText] = useState('');
    const [meetingDate, setMeetingDate] = useState('');
    const [timezone, setTimezone] = useState('');
    const [extracting, setExtracting] = useState(false);
    const [error, setError] = useState<{ code?: string; message: string } | null>(null);
    const [extraction, setExtraction] = useState<MeetingNotesExtraction | null>(null);
    const [drafts, setDrafts] = useState<Record<string, Draft>>({});
    const [linkSource, setLinkSource] = useState(true);
    const [idempotencyKey, setIdempotencyKey] = useState('');
    const [creating, setCreating] = useState(false);
    const [createError, setCreateError] = useState<string | null>(null);
    const [result, setResult] = useState<MeetingNotesCreateResult | null>(null);

    // Fresh state each time the dialog opens.
    useEffect(() => {
        if (!open) return;
        setStep('setup');
        setOptions(null);
        setLoadError(null);
        setError(null);
        setExtraction(null);
        setResult(null);
        setCreateError(null);
        setMeetingDate('');
        const controller = new AbortController();
        axios.get<MeetingNotesOptions>(route('meeting-notes.options', [workspace.slug, project.slug]), {
            params: pageUuid ? { page: pageUuid } : {},
            signal: controller.signal,
        }).then(({ data }) => {
            setOptions(data);
            setTimezone(data.timezone);
            // most notes are from today's meeting; with a date, "by Friday" turns into a due date
            setMeetingDate((current) => current || data.today);
            setBoardId((current) => (data.boards.some((b) => String(b.id) === current) ? current : data.boards.length === 1 ? String(data.boards[0].id) : ''));
        }).catch((e) => {
            if (axios.isCancel(e)) return;
            setLoadError(e.response?.status === 403 ? 'You can’t read these notes.' : e.response?.data?.message ?? 'Couldn’t load the boards of this project.');
        });
        return () => controller.abort();
    }, [open, workspace.slug, project.slug, pageUuid]);

    const proposals = extraction?.proposals ?? [];
    const selected = proposals.filter((p) => drafts[p.temp_id]?.selected);
    const unresolved = selected.filter((p) => drafts[p.temp_id].assignee === null);
    const untitled = selected.filter((p) => drafts[p.temp_id].title.trim() === '');
    const members = options?.members ?? [];
    const memberName = useMemo(() => new Map(members.map((m) => [m.id, m.name])), [members]);
    const sourceTooLong = !!options?.source?.too_long;
    const maxChars = options?.limits.max_chars ?? 20000;
    const canExtract = !!options?.ai_configured && boardId !== '' && !extracting && !sourceTooLong
        && (pageUuid ? true : text.trim() !== '' && text.length <= maxChars);

    const update = (id: string, patch: Partial<Draft>) => setDrafts((d) => ({ ...d, [id]: { ...d[id], ...patch } }));

    const extract = async () => {
        setExtracting(true);
        setError(null);
        try {
            const { data } = await axios.post<MeetingNotesExtraction>(route('meeting-notes.extract', [workspace.slug, project.slug]), {
                board_id: Number(boardId),
                page: pageUuid ?? undefined,
                text: pageUuid ? undefined : text,
                meeting_date: meetingDate || undefined,
                timezone: meetingDate ? timezone : undefined,
            });
            setExtraction(data);
            setDrafts(Object.fromEntries(data.proposals.map((p) => [p.temp_id, draftFor(p)])));
            setIdempotencyKey(newKey());
            setCreateError(null);
            setStep('review');
        } catch (e: any) {
            const body = e.response?.data;
            const firstError = body?.errors ? (Object.values(body.errors)[0] as string[])[0] : null;
            setError({ code: body?.code, message: firstError ?? body?.message ?? 'Couldn’t reach the server. Try again.' });
        } finally {
            setExtracting(false);
        }
    };

    const create = async () => {
        if (!extraction) return;
        setCreating(true);
        setCreateError(null);
        try {
            const { data } = await axios.post<MeetingNotesCreateResult>(route('meeting-notes.store', [workspace.slug, project.slug]), {
                board_id: extraction.board.id,
                page: pageUuid ?? undefined,
                link_source: linkSource,
                idempotency_key: idempotencyKey,
                actions: selected.map((p) => {
                    const d = drafts[p.temp_id];
                    return {
                        title: d.title.trim(),
                        description: d.description.trim() || null,
                        excerpt: p.excerpt,
                        assigned_to: d.assignee && d.assignee !== NONE ? Number(d.assignee) : null,
                        due_date: d.dueDate || null,
                    };
                }),
            });
            setResult(data);
            setStep('done');
            toast.success(data.replayed ? 'These issues were already created' : `Created ${data.count} issue${data.count === 1 ? '' : 's'}`);
        } catch (e: any) {
            const status = e.response?.status;
            const body = e.response?.data;
            setCreateError(
                status === 403 ? 'You can no longer add issues to this board or read these notes. Nothing was created.'
                    : status === 409 || status === 422 ? (body?.errors ? (Object.values(body.errors)[0] as string[])[0] : body?.message) ?? 'Check the suggestions and try again.'
                        : 'The issues may not have been created. Click Create again — they are never created twice.',
            );
        } finally {
            setCreating(false);
        }
    };

    const allSelected = proposals.length > 0 && selected.length === proposals.length;
    const titleFor = (tempId: string) => proposals.findIndex((p) => p.temp_id === tempId) + 1;

    return (
        <Dialog open={open} onOpenChange={(o) => !creating && !extracting && onOpenChange(o)}>
            <DialogContent className="sm:max-w-4xl max-h-[92vh] overflow-y-auto p-4 sm:p-6">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ListChecks className="size-5 text-primary" /> Extract action items
                    </DialogTitle>
                    <DialogDescription>
                        {step === 'setup' && 'Pick the board, then let AI suggest issues from the notes. Nothing is created until you review the suggestions and click Create.'}
                        {step === 'review' && extraction && `Review the suggestions for ${extraction.board.name}. Only the selected ones are created.`}
                        {step === 'done' && 'Done. The notes themselves were not changed.'}
                    </DialogDescription>
                </DialogHeader>

                {!options && !loadError && (
                    <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                        <Loader2 className="size-4 animate-spin" /> Loading…
                    </div>
                )}
                {loadError && <p className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">{loadError}</p>}

                {options && step === 'setup' && (
                    !options.ai_configured || error?.code === 'not_configured' ? (
                        <NotConfigured options={options} />
                    ) : options.boards.length === 0 ? (
                        <p className="rounded-lg border bg-muted/40 p-4 text-sm text-muted-foreground">
                            You can’t add issues to any board in {project.name}. Ask a project admin for editor access.
                        </p>
                    ) : (
                        <div className="space-y-5">
                            <div className="space-y-1.5">
                                <Label htmlFor="mn-board">Create issues on</Label>
                                <Select value={boardId} onValueChange={setBoardId}>
                                    <SelectTrigger id="mn-board" aria-label="Destination board" className="sm:max-w-sm">
                                        <SelectValue placeholder="Choose a board" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.boards.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>

                            {pageUuid ? (
                                <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                                    <div className="flex items-center gap-2 font-medium"><FileText className="size-4 text-muted-foreground" /> {options.source?.title ?? 'This doc'}</div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {options.source?.uses_draft ? 'Reads your unpublished changes, as you see them in the editor. ' : 'Reads the doc as you see it. '}
                                        {options.source?.length ? `${options.source.length.toLocaleString()} characters.` : 'The doc is empty.'}
                                    </p>
                                    {sourceTooLong && (
                                        <p className="mt-2 text-xs text-destructive">This doc is longer than {maxChars.toLocaleString()} characters. Paste the relevant section in the project’s “Meeting notes → issues” instead.</p>
                                    )}
                                </div>
                            ) : (
                                <div className="space-y-1.5">
                                    <Label htmlFor="mn-text">Meeting notes</Label>
                                    <Textarea
                                        id="mn-text"
                                        value={text}
                                        onChange={(e) => setText(e.target.value)}
                                        placeholder={'Paste your notes, e.g.\n- Max to update the pricing page by Friday\n- Alex will book the venue'}
                                        className="min-h-[180px] font-mono text-[13px]"
                                    />
                                    <p className={cn('text-xs text-muted-foreground text-right', text.length > maxChars && 'text-destructive')}>
                                        {text.length.toLocaleString()} / {maxChars.toLocaleString()}
                                    </p>
                                </div>
                            )}

                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="mn-date">Meeting date</Label>
                                    <Input id="mn-date" type="date" value={meetingDate} onChange={(e) => setMeetingDate(e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="mn-tz">Time zone</Label>
                                    <Input id="mn-tz" value={timezone} onChange={(e) => setTimezone(e.target.value)} placeholder="Europe/Berlin" disabled={!meetingDate} />
                                </div>
                                <p className="text-xs text-muted-foreground sm:col-span-2">
                                    Deadlines like “by Friday” become due dates counted from the meeting date. Clear it to use only full dates (“14 November 2026”).
                                </p>
                            </div>

                            {error && error.code !== 'not_configured' && (
                                <p role="alert" className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" /> {error.message}
                                </p>
                            )}
                        </div>
                    )
                )}

                {step === 'review' && extraction && (
                    <div className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {proposals.length === 0 ? 'No action items found in these notes.' : `${proposals.length} suggestion${proposals.length === 1 ? '' : 's'} · ${selected.length} selected`}
                            </span>
                            {proposals.length > 0 && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7"
                                    onClick={() => setDrafts((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, { ...v, selected: !allSelected }])))}
                                >
                                    {allSelected ? 'Select none' : 'Select all'}
                                </Button>
                            )}
                        </div>
                        {(extraction.dropped > 0 || extraction.truncated) && (
                            <p className="rounded-lg border border-amber-300/60 bg-amber-50 p-2.5 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-300">
                                {extraction.dropped > 0 && `${extraction.dropped} suggestion${extraction.dropped === 1 ? ' was' : 's were'} left out because ${extraction.dropped === 1 ? 'its quote isn’t' : 'their quotes aren’t'} in the notes. `}
                                {extraction.truncated && `Only the first ${proposals.length} suggestions are shown.`}
                            </p>
                        )}

                        {proposals.map((p, i) => (
                            <ProposalCard
                                key={p.temp_id}
                                index={i + 1}
                                proposal={p}
                                draft={drafts[p.temp_id]}
                                members={members}
                                memberName={memberName}
                                duplicateIndex={p.duplicate_of ? titleFor(p.duplicate_of) : null}
                                onChange={(patch) => update(p.temp_id, patch)}
                            />
                        ))}

                        {createError && (
                            <p role="alert" className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" /> {createError}
                            </p>
                        )}
                    </div>
                )}

                {step === 'done' && result && (
                    <div className="space-y-3">
                        <div className="flex items-start gap-3 rounded-lg border border-emerald-300/60 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-950/30 dark:text-emerald-200">
                            <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                            <span>
                                {result.replayed
                                    ? `These ${result.count} issues were already created — nothing was duplicated.`
                                    : `Created ${result.count} issue${result.count === 1 ? '' : 's'} on ${extraction?.board.name ?? 'the board'}.`}
                            </span>
                        </div>
                        <ul className="divide-y rounded-lg border">
                            {result.items.map((item) => (
                                <li key={item.id}>
                                    <Link href={item.url} className="flex items-center gap-3 px-3 py-2 text-sm hover:bg-muted/60">
                                        <span className="font-mono text-xs text-muted-foreground">{item.key}</span>
                                        <span className="min-w-0 flex-1 truncate">{item.title}</span>
                                        <ExternalLink className="size-3.5 shrink-0 text-muted-foreground" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <DialogFooter className="gap-2 sm:gap-2 flex-col-reverse sm:flex-row sm:items-center">
                    {step === 'setup' && (
                        <>
                            <Button variant="outline" onClick={() => onOpenChange(false)} disabled={extracting}>Cancel</Button>
                            {options?.ai_configured && options.boards.length > 0 && error?.code !== 'not_configured' && (
                                <Button onClick={extract} disabled={!canExtract}>
                                    {extracting ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}
                                    {extracting ? 'Reading the notes…' : 'Extract action items'}
                                </Button>
                            )}
                        </>
                    )}
                    {step === 'review' && (
                        <>
                            <Button variant="ghost" onClick={() => setStep('setup')} disabled={creating} className="sm:mr-auto">
                                <ArrowLeft className="size-4" /> Back
                            </Button>
                            <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                <input type="checkbox" className="size-4 accent-primary" checked={linkSource} onChange={(e) => setLinkSource(e.target.checked)} />
                                Add the quote and a link to the notes
                            </label>
                            <Button onClick={create} disabled={creating || selected.length === 0 || unresolved.length > 0 || untitled.length > 0}>
                                {creating ? <Loader2 className="size-4 animate-spin" /> : <ListChecks className="size-4" />}
                                Create {selected.length} issue{selected.length === 1 ? '' : 's'}
                            </Button>
                        </>
                    )}
                    {step === 'done' && result && (
                        <>
                            <Button variant="outline" onClick={() => onOpenChange(false)}>Close</Button>
                            <Button onClick={() => { onOpenChange(false); router.visit(result.board_url); }}>Open board</Button>
                        </>
                    )}
                </DialogFooter>
                {step === 'review' && unresolved.length > 0 && (
                    <p className="-mt-2 text-right text-xs text-amber-700 dark:text-amber-400">Choose an assignee (or “Unassigned”) for every highlighted suggestion first.</p>
                )}
            </DialogContent>
        </Dialog>
    );
}

function NotConfigured({ options }: { options: MeetingNotesOptions }) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed px-4 py-8 text-center">
            <div className="flex size-10 items-center justify-center rounded-full bg-primary/10"><Sparkles className="size-5 text-primary" /></div>
            <p className="font-medium">AI isn’t set up for this workspace</p>
            <p className="max-w-md text-sm text-muted-foreground">
                Action items are extracted with your workspace’s own AI provider (an OpenAI-compatible endpoint and key).
                {options.can_configure_ai ? ' Add one in the workspace settings, then try again.' : ' Ask a workspace admin to add one in the workspace settings.'}
            </p>
            {options.can_configure_ai && (
                <Button asChild variant="outline" size="sm" className="mt-1">
                    <Link href={options.settings_url}>Open workspace settings</Link>
                </Button>
            )}
        </div>
    );
}

function ProposalCard({ index, proposal: p, draft, members, memberName, duplicateIndex, onChange }: {
    index: number;
    proposal: MeetingNotesProposal;
    draft: Draft;
    members: { id: number; name: string }[];
    memberName: Map<number, string>;
    duplicateIndex: number | null;
    onChange: (patch: Partial<Draft>) => void;
}) {
    const id = `mn-${p.temp_id}`;
    const ambiguous = p.assignee?.status === 'ambiguous';
    const needsChoice = draft.selected && draft.assignee === null;
    // ambiguous names list their candidates first
    const assigneeOptions = ambiguous
        ? [...p.assignee!.candidates, ...members.filter((m) => !p.assignee!.candidates.some((c) => c.id === m.id))]
        : members;

    return (
        <div
            role="group"
            aria-label={`Suggestion ${index}`}
            className={cn(
                'rounded-xl border bg-card p-3 sm:p-4 transition-colors',
                !draft.selected && 'bg-muted/30 opacity-70',
                needsChoice && 'border-amber-400 dark:border-amber-500/60',
            )}
        >
            <div className="flex items-start gap-3">
                <input
                    type="checkbox"
                    className="mt-2.5 size-4 shrink-0 accent-primary"
                    checked={draft.selected}
                    onChange={(e) => onChange({ selected: e.target.checked })}
                    aria-label={`Create suggestion ${index}`}
                />
                <div className="grid min-w-0 flex-1 gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,17rem)]">
                    <div className="min-w-0 space-y-2">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-xs font-medium text-muted-foreground">#{index}</span>
                            {duplicateIndex !== null && (
                                <Badge variant="outline" className="gap-1 border-amber-400/70 text-amber-700 dark:text-amber-400"><CopyCheck className="size-3" /> Same as #{duplicateIndex}</Badge>
                            )}
                            {p.existing && (
                                <Badge variant="outline" className="gap-1 border-amber-400/70 text-amber-700 dark:text-amber-400">
                                    <CopyCheck className="size-3" /> Already on the board:
                                    <a href={p.existing.url} target="_blank" rel="noreferrer" className="underline">{p.existing.key}</a>
                                </Badge>
                            )}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor={`${id}-title`} className="sr-only">Title</Label>
                            <Input id={`${id}-title`} aria-label="Title" value={draft.title} maxLength={255} onChange={(e) => onChange({ title: e.target.value })} className="font-medium" />
                        </div>
                        <Textarea aria-label="Description" value={draft.description} onChange={(e) => onChange({ description: e.target.value })} placeholder="Description (optional)" className="min-h-[60px] text-sm" maxLength={5000} />
                        <div className="grid gap-2 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor={`${id}-assignee`} className="text-xs text-muted-foreground">Assignee</Label>
                                <Select value={draft.assignee ?? ''} onValueChange={(v) => onChange({ assignee: v })}>
                                    <SelectTrigger id={`${id}-assignee`} aria-label="Assignee" className={cn('h-9', needsChoice && 'border-amber-400 dark:border-amber-500/60')}>
                                        <SelectValue placeholder="Choose who…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Unassigned</SelectItem>
                                        {assigneeOptions.map((m) => <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor={`${id}-due`} className="text-xs text-muted-foreground">Due date</Label>
                                <Input id={`${id}-due`} type="date" className="h-9" value={draft.dueDate} onChange={(e) => onChange({ dueDate: e.target.value })} />
                            </div>
                        </div>
                    </div>

                    <div className="min-w-0 space-y-2 rounded-lg bg-muted/50 p-2.5 text-xs">
                        <div className="flex items-center gap-1 font-medium text-muted-foreground"><Quote className="size-3" /> From the notes</div>
                        <blockquote className="border-l-2 border-primary/50 pl-2 text-[13px] leading-snug text-foreground whitespace-pre-wrap break-words">{p.excerpt}</blockquote>
                        {p.assignee && (
                            <p className={cn('text-muted-foreground', p.assignee.status !== 'matched' && 'text-amber-700 dark:text-amber-400')}>
                                Owner: “{p.assignee.mention}”
                                {p.assignee.status === 'matched' && p.assignee.user_id && ` → ${memberName.get(p.assignee.user_id) ?? 'member'}`}
                                {p.assignee.status === 'ambiguous' && ` matches ${p.assignee.candidates.length} members — choose one.`}
                                {p.assignee.status === 'unknown' && ' isn’t a member of this project, left unassigned.'}
                            </p>
                        )}
                        {p.due && (
                            <p className={cn('text-muted-foreground', p.due.status !== 'set' && 'text-amber-700 dark:text-amber-400')}>
                                Deadline: “{p.due.text}”
                                {p.due.status === 'needs_meeting_date' && ' — add the meeting date to turn it into a date.'}
                                {p.due.status === 'unclear' && ' — couldn’t be read as a date; set it yourself.'}
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
