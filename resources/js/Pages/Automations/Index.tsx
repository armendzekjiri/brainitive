import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { AlertTriangle, ArrowLeft, History, Plus, Workflow } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import RunLog, { RunStatusBadge } from '@/components/automations/RunLog';
import { cn } from '@/lib/utils';
import type { AutomationRule, AutomationRun } from '@/types';

/**
 * Project → Automations (v1.40.0, BRAI-63): the project's WHEN / IF / THEN rules with an on/off switch
 * and their last run, plus the run history of all rules. Project admins only (AutomationController).
 */
interface Props {
    workspace: { id: number; name: string; slug: string };
    project: { id: number; name: string; slug: string; key: string };
    rules: AutomationRule[];
    runs: AutomationRun[];
    maxRules: number;
}

/** On/off switch; local state so it flips at once and re-syncs from the server's answer. */
function RuleSwitch({ rule, url }: { rule: AutomationRule; url: string }) {
    const [on, setOn] = useState(rule.enabled);
    useEffect(() => setOn(rule.enabled), [rule.enabled]);

    const toggle = () => {
        const next = !on;
        setOn(next);
        router.patch(url, { enabled: next }, { preserveScroll: true, onError: () => setOn(!next) });
    };

    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            aria-label={`${rule.name} on`}
            onClick={toggle}
            className={cn('relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', on ? 'bg-primary' : 'bg-muted-foreground/30')}
        >
            <span className={cn('inline-block size-4 rounded-full bg-background shadow transition-transform', on ? 'translate-x-4' : 'translate-x-0.5')} />
        </button>
    );
}

export default function AutomationsIndex({ workspace, project, rules, runs, maxRules }: Props) {
    const params = [workspace.slug, project.slug];

    return (
        <AppLayout>
            <Head title={`Automations - ${project.name}`} />
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-8">
                <div>
                    <Link href={route('projects.show', params)} className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-4" />{project.name}
                    </Link>
                    <div className="mt-3 flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight"><Workflow className="size-6" />Automations</h1>
                            <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                                WHEN something happens, IF it matches your conditions, THEN do the boring part: assign, move, set fields, comment,
                                notify or create a linked issue. Rules start switched off and run as the person who saved them last.
                            </p>
                        </div>
                        {rules.length < maxRules && (
                            <Button asChild className="gap-1.5">
                                <Link href={route('automations.create', params)}><Plus className="size-4" />New rule</Link>
                            </Button>
                        )}
                    </div>
                </div>

                {rules.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                            <Workflow className="size-10 text-muted-foreground" />
                            <div>
                                <p className="font-medium">No rules yet</p>
                                <p className="text-sm text-muted-foreground">For example: when an issue is created on a board, assign it and set its priority.</p>
                            </div>
                            <Button asChild variant="outline" className="gap-1.5"><Link href={route('automations.create', params)}><Plus className="size-4" />New rule</Link></Button>
                        </CardContent>
                    </Card>
                ) : (
                    <ul className="space-y-3" aria-label="Rules">
                        {rules.map((rule) => (
                            <li key={rule.id}>
                                <Card>
                                    <CardContent className="flex items-start gap-4 p-4">
                                        <div className="pt-0.5"><RuleSwitch rule={rule} url={route('automations.toggle', [...params, rule.id])} /></div>
                                        <div className="min-w-0 flex-1 space-y-1">
                                            <Link href={route('automations.edit', [...params, rule.id])} className="font-medium hover:underline">{rule.name}</Link>
                                            <p className="text-sm text-muted-foreground">
                                                <span className="font-semibold text-foreground">When</span> {rule.summary.when}
                                                {rule.summary.if.length > 0 && <> <span className="font-semibold text-foreground">if</span> {rule.summary.if.join(' and ')}</>}
                                                {' '}<span className="font-semibold text-foreground">then</span> {rule.summary.then.join(', ')}
                                            </p>
                                            <p className="text-xs text-muted-foreground">Runs as {rule.actor?.name ?? 'nobody'} · version {rule.version}</p>
                                            {rule.actor_problem && (
                                                <p className="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300"><AlertTriangle className="size-3.5" />{rule.actor_problem}</p>
                                            )}
                                        </div>
                                        <div className="hidden shrink-0 text-right text-xs text-muted-foreground sm:block">
                                            {rule.last_run_at && rule.last_run_status ? (
                                                <div className="space-y-1">
                                                    <RunStatusBadge status={rule.last_run_status} />
                                                    <div>{formatDistanceToNow(new Date(rule.last_run_at), { addSuffix: true })}</div>
                                                </div>
                                            ) : (
                                                'Never ran'
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base"><History className="size-4" />Run history</CardTitle>
                        <CardDescription>The last 50 runs of all rules. Open a row to see what each action did. Kept for 30 days.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <RunLog runs={runs} showRule />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
