import { useEffect, useState, type FormEvent, type KeyboardEvent } from 'react';
import { MessageSquare, Sun } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { addToMyDay, commentOnIssue, myDayKey, updateIssue, MY_DAY_KEY, type IssueChanges } from '@/lib/offline/actions';
import { issueKey, pendingKeys, useOfflineState } from '@/lib/offline/store';
import type { OfflineIssueSnapshot, OfflineMyDaySnapshot } from '@/types';
import NotSyncedBadge from './NotSyncedBadge';
import { useSnapshot } from './useSnapshot';

const selectClass = 'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50';

/**
 * An issue from this device (BRAI-82): title, status, priority, assignee and due date are
 * editable, comments can be added; the description is read-only offline (rich text).
 */
export default function OfflineIssueView({ issue }: { issue: OfflineIssueSnapshot }) {
    const state = useOfflineState();
    const pending = pendingKeys(state).has(issueKey(issue.id));
    const { data: day } = useSnapshot<OfflineMyDaySnapshot>(MY_DAY_KEY);
    const planned = (day?.rows ?? []).some((r) => myDayKey(r) === `my_day:board:${issue.id}`);
    const [title, setTitle] = useState(issue.title);
    const [comment, setComment] = useState('');
    const edit = issue.canEdit;
    const column = issue.columns.find((c) => c.id === issue.column_id);

    useEffect(() => setTitle(issue.title), [issue.title]);

    const change = (changes: IssueChanges) => void updateIssue(issue, changes);
    const saveTitle = () => {
        const next = title.trim();
        if (next && next !== issue.title) change({ title: next });
        else setTitle(issue.title);
    };
    const onTitleKey = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') (e.target as HTMLInputElement).blur();
    };
    const addComment = async (e: FormEvent) => {
        e.preventDefault();
        const text = comment.trim();
        if (!text) return;
        setComment('');
        await commentOnIssue(issue, text);
    };
    const plan = async () => {
        await addToMyDay({
            work_type: 'board',
            work_id: issue.id,
            title: issue.title,
            key: issue.key,
            url: issue.url,
            project_name: issue.project.name,
            done: !!column?.is_done_column,
            column_id: issue.column_id,
            done_column_id: issue.columns.find((c) => c.is_done_column)?.id ?? null,
            open_column_id: issue.columns.find((c) => !c.is_done_column)?.id ?? null,
        });
        toast.success('Added to My Day');
    };

    return (
        <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6">
            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <span className="truncate">{issue.project.name} · {issue.board.name}</span>
                <span className="font-mono">{issue.key}</span>
                {pending && <NotSyncedBadge />}
            </div>

            <div className="mt-2">
                <Label htmlFor="offline-issue-title" className="sr-only">Title</Label>
                <Input
                    id="offline-issue-title"
                    value={title}
                    disabled={!edit}
                    onChange={(e) => setTitle(e.target.value)}
                    onBlur={saveTitle}
                    onKeyDown={onTitleKey}
                    maxLength={255}
                    className="h-auto border-transparent px-1 py-1 text-2xl font-bold tracking-tight shadow-none hover:border-input focus-visible:border-input"
                />
            </div>
            {!edit && <p className="mt-1 text-sm text-muted-foreground">You can view this issue; changing it needs edit rights.</p>}

            <div className="mt-6 grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="offline-issue-status">Status</Label>
                    <select id="offline-issue-status" className={selectClass} disabled={!edit} value={issue.column_id} onChange={(e) => change({ column_id: Number(e.target.value) })}>
                        {issue.columns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="offline-issue-priority">Priority</Label>
                    <select id="offline-issue-priority" className={selectClass} disabled={!edit} value={issue.priority_id ?? ''} onChange={(e) => change({ priority_id: e.target.value ? Number(e.target.value) : null })}>
                        <option value="">None</option>
                        {issue.priorities.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="offline-issue-assignee">Assignee</Label>
                    <select id="offline-issue-assignee" className={selectClass} disabled={!edit} value={issue.assigned_to ?? ''} onChange={(e) => change({ assigned_to: e.target.value ? Number(e.target.value) : null })}>
                        <option value="">Unassigned</option>
                        {issue.members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="offline-issue-due">Due date</Label>
                    <Input id="offline-issue-due" type="date" disabled={!edit} value={issue.due_date ?? ''} onChange={(e) => change({ due_date: e.target.value || null })} />
                </div>
            </div>

            <div className="mt-4">
                <Button variant="outline" size="sm" disabled={planned} onClick={() => void plan()}>
                    <Sun className="h-4 w-4" /> {planned ? 'On My Day' : 'Add to My Day'}
                </Button>
            </div>

            <section className="mt-8">
                <h2 className="text-sm font-semibold">Description</h2>
                <p className="mt-2 whitespace-pre-wrap break-words text-sm text-muted-foreground">
                    {issue.description_text || 'No description.'}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">The description can be edited when you’re online.</p>
            </section>

            <section className="mt-8" aria-label="Comments">
                <h2 className="flex items-center gap-2 text-sm font-semibold"><MessageSquare className="h-4 w-4" /> Comments</h2>
                {edit && (
                    <form onSubmit={addComment} className="mt-3 space-y-2">
                        <Label htmlFor="offline-issue-comment" className="sr-only">Add a comment</Label>
                        <Textarea id="offline-issue-comment" rows={3} value={comment} onChange={(e) => setComment(e.target.value)} placeholder="Add a comment" maxLength={10000} />
                        <Button type="submit" size="sm" disabled={!comment.trim()}>Comment</Button>
                    </form>
                )}
                <ul className="mt-4 space-y-3">
                    {issue.comments.map((c) => (
                        <li key={c.id ?? c.ref} className="rounded-xl border bg-card p-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span className="font-medium text-foreground">{c.author}</span>
                                <span>{new Date(c.created_at).toLocaleString()}</span>
                                {!c.id && <NotSyncedBadge />}
                            </div>
                            <p className="mt-1 whitespace-pre-wrap break-words">{c.text}</p>
                        </li>
                    ))}
                    {issue.comments.length === 0 && <li className="text-sm text-muted-foreground">No comments yet.</li>}
                </ul>
            </section>
        </div>
    );
}
