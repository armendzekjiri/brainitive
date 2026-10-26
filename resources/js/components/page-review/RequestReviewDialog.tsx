import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { PageReviewCandidate } from '@/types';
import { router } from '@inertiajs/react';
import { Check, Loader2, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    routeParams: [string, string, string];
    /** people to tick by default (e.g. the reviewers of the previous review) */
    preselected?: number[];
    /** save the editor's pending changes first, so the snapshot is what the author sees; false = stop */
    beforeSubmit?: () => Promise<boolean>;
}

/**
 * "Request review" (BRAI-80): pick reviewers among the people who can open the project and add an
 * optional message. The server freezes the current draft as the review's snapshot.
 */
export default function RequestReviewDialog({ open, onOpenChange, routeParams, preselected = [], beforeSubmit }: Props) {
    const [candidates, setCandidates] = useState<PageReviewCandidate[] | null>(null);
    const [selected, setSelected] = useState<number[]>(preselected);
    const [query, setQuery] = useState('');
    const [message, setMessage] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!open) return;
        setSelected(preselected);
        setError(null);
        setCandidates(null);
        let cancelled = false;
        window.axios
            .get(route('pages.reviews.candidates', routeParams))
            .then(({ data }: { data: { users: PageReviewCandidate[] } }) => {
                if (cancelled) return;
                setCandidates(data.users);
                // only people who can be asked (never yourself, never someone who left the project)
                setSelected((current) => current.filter((id) => data.users.some((user) => user.id === id)));
            })
            .catch(() => !cancelled && setError('Could not load the project members.'));
        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, routeParams.join('/')]);

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        return (candidates ?? []).filter((user) => !q || user.name.toLowerCase().includes(q) || user.email.toLowerCase().includes(q));
    }, [candidates, query]);

    const toggle = (id: number) => setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));

    const submit = async () => {
        setSubmitting(true);
        setError(null);
        if (beforeSubmit && !(await beforeSubmit())) {
            setSubmitting(false);
            setError('Your latest changes aren\'t saved yet. Resolve that first, then request the review.');
            return;
        }
        router.post(route('pages.reviews.store', routeParams), { reviewer_ids: selected, message: message || null }, {
            onSuccess: () => {
                setMessage('');
                onOpenChange(false);
            },
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Could not request the review.'),
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Request review</DialogTitle>
                    <DialogDescription>
                        Reviewers see this draft exactly as it is now. If you keep editing, the review goes out of date and you can ask again.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3">
                    <div className="relative">
                        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search people"
                            aria-label="Search people"
                            className="pl-8"
                        />
                    </div>
                    <div role="group" aria-label="Reviewers" className="max-h-64 overflow-y-auto rounded-lg border divide-y">
                        {!candidates && !error && (
                            <div className="flex items-center gap-2 p-3 text-sm text-muted-foreground">
                                <Loader2 className="size-4 animate-spin" /> Loading…
                            </div>
                        )}
                        {candidates && visible.length === 0 && (
                            <p className="p-3 text-sm text-muted-foreground">No one else can open this project yet.</p>
                        )}
                        {visible.map((user) => {
                            const checked = selected.includes(user.id);
                            return (
                                <button
                                    key={user.id}
                                    type="button"
                                    role="checkbox"
                                    aria-checked={checked}
                                    onClick={() => toggle(user.id)}
                                    className={cn('flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition-colors hover:bg-accent', checked && 'bg-primary/5')}
                                >
                                    <span className={cn('flex size-4 shrink-0 items-center justify-center rounded border', checked ? 'border-primary bg-primary text-primary-foreground' : 'border-input')}>
                                        {checked && <Check className="size-3" />}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">{user.name}</span>
                                        <span className="block truncate text-xs text-muted-foreground">{user.email}</span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="review-message">Message (optional)</Label>
                        <Textarea
                            id="review-message"
                            value={message}
                            onChange={(e) => setMessage(e.target.value)}
                            placeholder="What should reviewers look at?"
                            rows={3}
                            maxLength={2000}
                        />
                    </div>
                    {error && <p className="text-sm text-destructive">{error}</p>}
                </div>

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button onClick={submit} disabled={submitting || selected.length === 0}>
                        {submitting && <Loader2 className="size-4 mr-1.5 animate-spin" />}
                        {selected.length > 1 ? `Request review from ${selected.length} people` : 'Request review'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
