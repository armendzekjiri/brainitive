import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { Helpdesk, Project, Workspace } from '@/types';

/**
 * Helpdesk settings → SLA (v1.40.0, ticket 5): first-response and resolution targets per priority,
 * optional business hours. Contract: docs/ai/tasks/release-v1.40.0.md.
 */
export default function HelpdeskSlaTab({ helpdesk }: { workspace: Workspace; project: Project; helpdesk: Helpdesk }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Service levels</CardTitle>
                <CardDescription>Response and resolution targets for tickets in “{helpdesk.name}”.</CardDescription>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">Coming in v1.40.0.</CardContent>
        </Card>
    );
}
