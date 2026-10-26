import AdminShell from '@/components/admin/AdminShell';
import { relative } from '@/components/admin/format';
import Pagination from '@/components/admin/Pagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminAuditEntry, Paginated } from '@/types';

const ACTIONS: Record<string, string> = {
    'user.created': 'Created account',
    'user.password_reset_emailed': 'Emailed password reset link',
    'user.password_reset_link_created': 'Created password reset link',
    'user.email_verified': 'Marked email as verified',
    'user.verification_resent': 'Resent verification email',
    'user.suspended': 'Suspended',
    'user.unsuspended': 'Unsuspended',
    'user.admin_granted': 'Made instance admin',
    'user.admin_revoked': 'Removed instance admin',
    'user.deleted': 'Deleted account',
    'workspace.ownership_transferred': 'Transferred workspace',
    'workspace.deleted': 'Deleted workspace',
    'settings.updated': 'Updated settings',
    'settings.test_email_sent': 'Sent test email',
    'github_app.created': 'Created the GitHub App',
    'github_app.removed': 'Removed the GitHub App',
};

function details(meta: AdminAuditEntry['meta']): string {
    if (!meta) return '';
    return Object.entries(meta)
        .map(([key, value]) => `${key.replace(/_/g, ' ')}: ${typeof value === 'boolean' ? (value ? 'yes' : 'no') : String(value ?? '—')}`)
        .join(' · ');
}

export default function AuditLog({ entries }: { entries: Paginated<AdminAuditEntry> }) {
    return (
        <AdminShell title="Audit log">
            <div className="rounded-lg border overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>When</TableHead>
                            <TableHead>Admin</TableHead>
                            <TableHead>Action</TableHead>
                            <TableHead>Target</TableHead>
                            <TableHead>Details</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {entries.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={5} className="py-10 text-center text-muted-foreground">
                                    Nothing yet. Every action taken in this console is recorded here.
                                </TableCell>
                            </TableRow>
                        )}
                        {entries.data.map((entry) => (
                            <TableRow key={entry.id}>
                                <TableCell className="whitespace-nowrap text-sm text-muted-foreground" title={entry.created_at ?? undefined}>
                                    {relative(entry.created_at, '—')}
                                </TableCell>
                                <TableCell className="text-sm">{entry.actor_name}</TableCell>
                                <TableCell className="text-sm font-medium whitespace-nowrap">{ACTIONS[entry.action] ?? entry.action}</TableCell>
                                <TableCell className="text-sm">{entry.target_label ?? '—'}</TableCell>
                                <TableCell className="text-xs text-muted-foreground">{details(entry.meta)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <Pagination page={entries} noun="entries" />
        </AdminShell>
    );
}
