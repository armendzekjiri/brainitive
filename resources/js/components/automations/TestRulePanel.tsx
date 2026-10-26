import { useState, type FormEvent } from 'react';
import axios from 'axios';
import { CheckCircle2, CircleSlash, FlaskConical, Loader2, XCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AutomationAction, AutomationCondition, AutomationMatch, AutomationPreview, AutomationSubject } from '@/types';

/**
 * "Test on an issue" (v1.40.0, BRAI-63): runs the rule as it is in the form (saved or not) against
 * one issue or ticket and shows what would happen. Nothing is written (automations.preview).
 */
interface Props {
    url: string;
    subject: AutomationSubject;
    definition: { name: string; trigger: string; trigger_config: { column_id?: number | null }; condition_match: AutomationMatch; conditions: AutomationCondition[]; actions: AutomationAction[] };
}

export default function TestRulePanel({ url, subject, definition }: Props) {
    const [key, setKey] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<AutomationPreview | null>(null);
    const noun = subject === 'ticket' ? 'ticket' : 'issue';

    const run = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        setResult(null);
        try {
            const { data } = await axios.post<AutomationPreview>(url, { ...definition, subject_key: key });
            setResult(data);
        } catch (err) {
            const errors = axios.isAxiosError(err) ? (err.response?.data?.errors as Record<string, string[]> | undefined) : undefined;
            setError(errors ? Object.values(errors)[0]?.[0] ?? 'Check the rule.' : 'The test could not run.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-3">
            <form onSubmit={run} className="flex flex-wrap items-end gap-2">
                <div className="space-y-1.5">
                    <Label htmlFor="automation-test-key">{subject === 'ticket' ? 'Ticket key' : 'Issue key'}</Label>
                    <Input id="automation-test-key" value={key} onChange={(e) => setKey(e.target.value)} placeholder={subject === 'ticket' ? 'e.g. SUP-12' : 'e.g. WEB-12'} className="w-40" required />
                </div>
                <Button type="submit" variant="outline" disabled={busy || key.trim() === ''} className="gap-1.5">
                    {busy ? <Loader2 className="size-4 animate-spin" /> : <FlaskConical className="size-4" />}Test on this {noun}
                </Button>
            </form>
            <p className="text-xs text-muted-foreground">A dry run: nothing changes. Each action is checked against the {noun} as it is now.</p>
            {error && <p className="text-sm text-destructive" role="alert">{error}</p>}
            {result && (
                <div className="space-y-3 rounded-lg border p-3" aria-label="Test result">
                    <p className="text-sm font-medium">
                        {result.subject}: {result.matches ? 'the conditions match, the rule would run.' : 'the conditions don’t match, the rule would do nothing.'}
                    </p>
                    {result.conditions.length > 1 && (
                        <p className="text-xs text-muted-foreground">{result.match === 'any' ? 'Any condition may match.' : 'All conditions must match.'}</p>
                    )}
                    {result.conditions.length > 0 && (
                        <ul className="space-y-1">
                            {result.conditions.map((c, i) => (
                                <li key={i} className="flex items-center gap-2 text-sm">
                                    {c.passed ? <CheckCircle2 className="size-4 text-green-600" aria-label="Matches" /> : <XCircle className="size-4 text-red-600" aria-label="Doesn't match" />}
                                    {c.label}
                                </li>
                            ))}
                        </ul>
                    )}
                    {result.matches && (
                        <ul className="space-y-1 border-t pt-2">
                            {result.actions.map((a, i) => (
                                <li key={i} className="flex items-start gap-2 text-sm">
                                    {a.status === 'ok' ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-green-600" /> : a.status === 'skipped' ? <CircleSlash className="mt-0.5 size-4 shrink-0 text-amber-600" /> : <XCircle className="mt-0.5 size-4 shrink-0 text-red-600" />}
                                    <span><span className="font-medium">{a.label}</span> — <span className="text-muted-foreground">{a.message}</span></span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
