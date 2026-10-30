import { ArrowUpRight, Gauge, Info } from 'lucide-react';
import type { WorkspaceEntitlements } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { WARN_RATIO, formatAmount, usageRows, type UsageRow } from '@/lib/entitlements';

function UsageBar({ row }: { row: UsageRow }) {
    const unlimited = row.limit === null;
    const percent = Math.min(100, Math.round(row.ratio * 100));
    const tone = row.ratio >= 1 ? 'bg-destructive' : row.ratio >= WARN_RATIO ? 'bg-amber-500' : 'bg-primary';

    return (
        <div className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="font-medium">{row.label}</span>
                <span className="text-right tabular-nums text-muted-foreground" data-testid={`usage-${row.kind}`}>
                    {formatAmount(row.kind, row.used)}
                    {unlimited ? ' · unlimited' : ` of ${formatAmount(row.kind, row.limit as number)}`}
                </span>
            </div>
            {unlimited ? (
                <div className="h-2 rounded-full bg-muted" />
            ) : (
                <div
                    role="progressbar"
                    aria-label={row.label}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={percent}
                    className="h-2 overflow-hidden rounded-full bg-muted"
                >
                    <div className={cn('h-full rounded-full transition-all', tone)} style={{ width: `${percent}%` }} />
                </div>
            )}
        </div>
    );
}

/**
 * "Plan & usage" in the workspace settings (Cloud plans, v1.46.0): the plan, a bar per limit and
 * the link to Plan & billing. Members and projects are counted live, content and files come from
 * the nightly usage:measure. Only rendered when the `entitlements` prop exists (never self-hosted).
 */
export function WorkspaceUsageCard({ entitlements }: { entitlements: WorkspaceEntitlements }) {
    const { limits, usage, enforced, billingUrl } = entitlements;
    const measuredAt = usage.measuredAt ? new Date(usage.measuredAt) : null;
    const graceUntil = limits.graceUntil ? new Date(limits.graceUntil) : null;

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1.5">
                        <CardTitle className="flex items-center gap-3 text-xl">
                            <Gauge className="size-5 text-primary" />
                            Plan &amp; usage
                        </CardTitle>
                        <CardDescription>
                            This workspace is on the <span className="font-medium text-foreground">{limits.plan}</span> plan.
                        </CardDescription>
                    </div>
                    {billingUrl && (
                        <Button asChild variant="outline" className="shrink-0 gap-1.5 self-start">
                            <a href={billingUrl}>
                                Plan &amp; billing
                                <ArrowUpRight className="size-4" />
                            </a>
                        </Button>
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-6">
                {!enforced && (
                    <p className="flex gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/40 dark:text-sky-200">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        Limits are not enforced yet — Brainitive Cloud is free during early access.
                    </p>
                )}
                {graceUntil && graceUntil.getTime() > Date.now() && (
                    <p className="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        Nothing is deleted until {graceUntil.toLocaleDateString()}. Until then, adding is paused while the workspace is over a limit.
                    </p>
                )}

                <div className="grid gap-5 sm:grid-cols-2">
                    {usageRows(entitlements).map((row) => (
                        <UsageBar key={row.kind} row={row} />
                    ))}
                </div>

                <p className="text-xs text-muted-foreground">
                    Members (including open invitations) and projects are counted live.{' '}
                    {measuredAt
                        ? `Content and files were last measured ${measuredAt.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}.`
                        : 'Content and files are measured once a night; not measured yet.'}
                </p>
            </CardContent>
        </Card>
    );
}
