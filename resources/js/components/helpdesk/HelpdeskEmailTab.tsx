import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { Helpdesk, Project, Workspace } from '@/types';

/**
 * Helpdesk settings → Email (v1.40.0). Two independent sections, one per ticket:
 * - "Connected mailbox" (IMAP + SMTP replies): ticket 3, in components/helpdesk/email/ImapMailboxSection.tsx
 * - "Forwarding address" (provider webhook): ticket 4, in components/helpdesk/email/ForwardingAddressSection.tsx
 * Each section loads and saves its own data. Contract: docs/ai/tasks/release-v1.40.0.md.
 */
export default function HelpdeskEmailTab({ helpdesk }: { workspace: Workspace; project: Project; helpdesk: Helpdesk }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Email</CardTitle>
                <CardDescription>Receive support email as tickets in “{helpdesk.name}” and answer in the same thread.</CardDescription>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">Coming in v1.40.0.</CardContent>
        </Card>
    );
}
