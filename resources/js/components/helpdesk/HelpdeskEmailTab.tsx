import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import ForwardingAddressSection from '@/components/helpdesk/email/ForwardingAddressSection';
import ImapMailboxSection from '@/components/helpdesk/email/ImapMailboxSection';
import type { Helpdesk, Project, Workspace } from '@/types';

/**
 * Helpdesk settings → Email (v1.40.0). Two independent sections, one per ticket:
 * - "Connected mailbox" (IMAP + SMTP replies): ticket 3, in components/helpdesk/email/ImapMailboxSection.tsx
 * - "Forwarding address" (provider webhook): ticket 4, in components/helpdesk/email/ForwardingAddressSection.tsx
 * Each section loads and saves its own data. Contract: docs/ai/tasks/release-v1.40.0.md.
 */
export default function HelpdeskEmailTab({ workspace, project, helpdesk }: { workspace: Workspace; project: Project; helpdesk: Helpdesk }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Email</CardTitle>
                <CardDescription>Receive support email as tickets in “{helpdesk.name}” and answer in the same thread.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-8">
                <ImapMailboxSection workspace={workspace} project={project} helpdesk={helpdesk} />
                <ForwardingAddressSection workspace={workspace} project={project} helpdesk={helpdesk} />
            </CardContent>
        </Card>
    );
}
