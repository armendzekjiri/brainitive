import AdminShell from '@/components/admin/AdminShell';
import { bytes } from '@/components/admin/format';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

interface Props {
    stats: {
        users: number;
        users_active_30d: number;
        users_new_30d: number;
        users_suspended: number;
        users_unverified: number;
        instance_admins: number;
        workspaces: number;
        projects: number;
        pages: number;
        boards: number;
        items: number;
        helpdesks: number;
        tickets: number;
    };
    system: {
        php: string;
        laravel: string;
        database: string;
        database_size: number | null;
        storage_size: number;
        queue: string;
        jobs_pending: number | null;
        jobs_failed: number;
        mail: string;
        registration_open: boolean;
        email_verification: boolean;
    };
}

function Stat({ label, value, hint }: { label: string; value: number | string; hint?: string }) {
    return (
        <div className="rounded-lg border bg-card p-4">
            <div className="text-sm text-muted-foreground">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums">{value}</div>
            {hint && <div className="mt-1 text-xs text-muted-foreground">{hint}</div>}
        </div>
    );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-4 py-2 text-sm border-b last:border-0">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-medium text-right">{children}</span>
        </div>
    );
}

export default function Overview({ stats, system }: Props) {
    const usingLogMailer = system.mail.startsWith('log') || system.mail.startsWith('array');

    return (
        <AdminShell title="Overview">
            {usingLogMailer && (
                <div className="mb-6 flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
                    <AlertTriangle className="size-4 mt-0.5 text-amber-600 shrink-0" />
                    <div>
                        Emails aren’t being delivered (mailer: <code>{system.mail}</code>), so password resets, invitations and
                        notifications never reach anyone.{' '}
                        <Link href={route('admin.settings.edit')} className="font-medium underline underline-offset-2">
                            Set up an SMTP server
                        </Link>
                        .
                    </div>
                </div>
            )}

            <section className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
                <Stat label="Users" value={stats.users} hint={`${stats.users_new_30d} new in 30 days`} />
                <Stat label="Active (30 days)" value={stats.users_active_30d} hint="Signed in at least once" />
                <Stat label="Workspaces" value={stats.workspaces} />
                <Stat label="Projects" value={stats.projects} />
                <Stat label="Pages" value={stats.pages} />
                <Stat label="Boards" value={stats.boards} hint={`${stats.items} issues`} />
                <Stat label="Helpdesks" value={stats.helpdesks} hint={`${stats.tickets} tickets`} />
                <Stat label="Instance admins" value={stats.instance_admins} />
            </section>

            <div className="grid gap-6 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Accounts</CardTitle>
                        <CardDescription>Things that may need your attention.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Row label="Suspended">
                            <Link href={route('admin.users.index', { filter: 'suspended' })} className="hover:underline">
                                {stats.users_suspended}
                            </Link>
                        </Row>
                        <Row label="Email not verified">
                            <Link href={route('admin.users.index', { filter: 'unverified' })} className="hover:underline">
                                {stats.users_unverified}
                            </Link>
                        </Row>
                        <Row label="Sign-up">
                            <Badge variant={system.registration_open ? 'secondary' : 'outline'}>
                                {system.registration_open ? 'Open to everyone' : 'Invitation only'}
                            </Badge>
                        </Row>
                        <Row label="Email verification">{system.email_verification ? 'Required' : 'Off'}</Row>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>System</CardTitle>
                        <CardDescription>Versions, storage and background jobs.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Row label="PHP / Laravel">
                            {system.php} / {system.laravel}
                        </Row>
                        <Row label="Database">
                            {system.database} · {bytes(system.database_size)}
                        </Row>
                        <Row label="Uploaded files">{bytes(system.storage_size)}</Row>
                        <Row label="Mail">{system.mail}</Row>
                        <Row label="Queue">
                            {system.queue}
                            {system.jobs_pending !== null && ` · ${system.jobs_pending} waiting`}
                        </Row>
                        <Row label="Failed jobs">
                            <span className={system.jobs_failed > 0 ? 'text-destructive' : undefined}>{system.jobs_failed}</span>
                        </Row>
                    </CardContent>
                </Card>
            </div>
        </AdminShell>
    );
}
