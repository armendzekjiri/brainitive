import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { AlertTriangle, X } from 'lucide-react';
import type { PageProps } from '@/types';
import { KIND_NOUNS, formatAmount, nearLimitRows } from '@/lib/entitlements';

const storageKey = (workspaceId: number) => `entitlements-warning-dismissed-${workspaceId}`;

/** The limits this person already dismissed the warning for, in this workspace. */
function readDismissed(workspaceId: number): string[] {
    try {
        return (localStorage.getItem(storageKey(workspaceId)) ?? '').split(',').filter(Boolean);
    } catch {
        return []; // storage blocked: the banner shows again, nothing breaks
    }
}

/**
 * A thin strip under the top bar once the current workspace uses 90 % or more of a plan limit
 * (Cloud plans, v1.46.0). Only for workspace admins (they can upgrade) and only while limits are
 * enforced. Dismissing it is remembered per workspace and per limit: a new limit near its end
 * shows it again.
 */
export function UsageWarningBanner() {
    const { entitlements, currentWorkspace, canManageWorkspace } = usePage<PageProps>().props;
    const workspaceId = currentWorkspace?.id;
    // read on first render: AppLayout mounts per page, an effect alone would flash the banner
    const [dismissed, setDismissed] = useState<string[]>(() => (workspaceId ? readDismissed(workspaceId) : []));

    useEffect(() => {
        setDismissed(workspaceId ? readDismissed(workspaceId) : []);
    }, [workspaceId]);

    if (!entitlements?.enforced || !workspaceId || !canManageWorkspace) return null;
    const rows = nearLimitRows(entitlements);
    if (rows.length === 0 || rows.every((row) => dismissed.includes(row.kind))) return null;

    const dismiss = () => {
        const kinds = Array.from(new Set([...dismissed, ...rows.map((row) => row.kind)]));
        setDismissed(kinds);
        try {
            localStorage.setItem(storageKey(workspaceId), kinds.join(','));
        } catch {
            /* storage blocked: hidden until the next page load */
        }
    };

    const reached = rows.some((row) => row.ratio >= 1);
    const summary = rows.map((row) => `${formatAmount(row.kind, row.used)} of ${formatAmount(row.kind, row.limit as number)} ${KIND_NOUNS[row.kind]}`).join(', ');

    return (
        <div
            role="status"
            aria-label="Plan usage warning"
            className="flex shrink-0 items-start gap-2 border-b border-amber-200 bg-amber-50 py-2 pl-[max(0.75rem,env(safe-area-inset-left))] pr-[max(0.5rem,env(safe-area-inset-right))] text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200 md:items-center md:px-4"
        >
            <AlertTriangle className="mt-0.5 size-4 shrink-0 md:mt-0" />
            <p className="min-w-0 flex-1">
                {reached ? 'This workspace has reached' : 'This workspace is close to'} the limits of the {entitlements.limits.plan} plan: {summary}.{' '}
                {entitlements.billingUrl && (
                    <a href={entitlements.billingUrl} className="font-medium underline underline-offset-2 hover:no-underline">
                        See plans
                    </a>
                )}
            </p>
            <button
                type="button"
                onClick={dismiss}
                className="-my-1 shrink-0 rounded-sm p-1 text-amber-800/80 hover:text-amber-950 dark:text-amber-200/80 dark:hover:text-amber-50"
                aria-label="Dismiss plan usage warning"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}
