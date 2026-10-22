import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, LayoutDashboard, ScrollText, Settings, ShieldCheck, Users } from 'lucide-react';
import { ReactNode, useEffect } from 'react';
import { toast } from 'sonner';

const TABS = [
    { route: 'admin.overview', match: 'admin.overview', label: 'Overview', icon: LayoutDashboard },
    { route: 'admin.users.index', match: 'admin.users.*', label: 'Users', icon: Users },
    { route: 'admin.workspaces.index', match: 'admin.workspaces.*', label: 'Workspaces', icon: Building2 },
    { route: 'admin.settings.edit', match: 'admin.settings.*', label: 'Settings', icon: Settings },
    { route: 'admin.audit-log', match: 'admin.audit-log', label: 'Audit log', icon: ScrollText },
] as const;

/**
 * Frame for every instance admin page: header, tab navigation and flash toasts.
 */
export default function AdminShell({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
    const { flash } = usePage<PageProps>().props;

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
    }, [flash?.success]);

    useEffect(() => {
        if (flash?.error) toast.error(flash.error);
    }, [flash?.error]);

    return (
        <AppLayout>
            <Head title={`${title} · Instance admin`} />

            <div className="p-6 md:p-8 lg:p-10 max-w-6xl mx-auto">
                <div className="mb-6 flex flex-col gap-1">
                    <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                        <ShieldCheck className="size-4" />
                        Instance admin
                    </div>
                    <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                            <p className="text-muted-foreground mt-1 text-sm">
                                Run this instance. Workspace content stays private to its members.
                            </p>
                        </div>
                        {actions}
                    </div>
                </div>

                <nav className="mb-6 flex gap-1 overflow-x-auto border-b" aria-label="Admin sections">
                    {TABS.map(({ route: name, match, label, icon: Icon }) => {
                        const active = route().current(match);
                        return (
                            <Link
                                key={name}
                                href={route(name)}
                                className={cn(
                                    '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                                    active
                                        ? 'border-primary text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                                aria-current={active ? 'page' : undefined}
                            >
                                <Icon className="size-4" />
                                {label}
                            </Link>
                        );
                    })}
                </nav>

                {children}
            </div>
        </AppLayout>
    );
}
