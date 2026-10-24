import type { ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { ReceiptText } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Shell of every Workspace → Billing page (v1.38.0, workspace admins): title + tabs. A tab only
 * shows once its route exists, so each billing ticket can ship its own tab independently.
 * Contract: docs/ai/tasks/release-v1.38.0-billing.md.
 */
const TABS: { route: string; label: string }[] = [
    { route: 'billing.reports.index', label: 'Reports' },
    { route: 'billing.time.index', label: 'Time' },
    { route: 'billing.clients.index', label: 'Clients' },
    { route: 'billing.activities.index', label: 'Activities' },
    { route: 'billing.settings.edit', label: 'Settings' },
];

export default function BillingLayout({ title, description, actions, children }: {
    /** Browser tab title and the page heading's suffix, e.g. "Reports". */
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { currentWorkspace } = usePage<PageProps>().props;
    const current = route().current() ?? '';
    const tabs = TABS.filter((tab) => route().has(tab.route));

    return (
        <AppLayout>
            <Head title={`${title} · Billing`} />
            <div className="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                            <ReceiptText className="size-6 text-primary" />
                            Billing
                        </h1>
                        {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
                    </div>
                    {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
                </div>

                {currentWorkspace && tabs.length > 0 && (
                    <nav className="-mx-4 overflow-x-auto border-b border-border px-4 sm:mx-0 sm:px-0" aria-label="Billing">
                        <div className="flex gap-5">
                            {tabs.map((tab) => {
                                // a tab is active on its own pages too (billing.reports.show is under Reports)
                                const active = current === tab.route || current.startsWith(tab.route.replace(/\.(index|edit)$/, '.'));
                                return (
                                    <Link
                                        key={tab.route}
                                        href={route(tab.route, currentWorkspace.slug)}
                                        aria-current={active ? 'page' : undefined}
                                        className={cn(
                                            '-mb-px whitespace-nowrap border-b-2 pb-2 pt-1 text-sm font-medium transition-colors',
                                            active ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                                        )}
                                    >
                                        {tab.label}
                                    </Link>
                                );
                            })}
                        </div>
                    </nav>
                )}

                {children}
            </div>
        </AppLayout>
    );
}
