import { router } from '@inertiajs/react';
import { useState } from 'react';
import { formatDistanceToNow } from 'date-fns';
import { Plug } from 'lucide-react';
import { Button } from '@/components/ui/button';

export interface ConnectedApp {
    client_id: string;
    name: string;
    scopes: string[];
    last_issued: string | null;
    expires_at: string | null;
    tokens: number;
}

const SCOPE_LABELS: Record<string, string> = {
    'mcp:use': 'Read and write your content',
    capture: 'Save pages, links and notes',
};

/** Apps with access to this account (MCP connectors, the browser extension, the CLI) — revoke any of them. */
export default function ConnectedAppsForm({ apps, className = '' }: { apps: ConnectedApp[]; className?: string }) {
    const [busy, setBusy] = useState<string | null>(null);

    if (apps.length === 0) {
        return <p className={`text-sm text-muted-foreground ${className}`}>No apps are connected to your account.</p>;
    }

    const disconnect = (app: ConnectedApp) => {
        if (!window.confirm(`Disconnect ${app.name}? It loses access right away; connect it again to use it.`)) return;
        setBusy(app.client_id);
        router.delete(route('profile.connected-apps.destroy', app.client_id), { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <ul className={`divide-y rounded-lg border ${className}`}>
            {apps.map((app) => (
                <li key={app.client_id} className="flex items-center gap-3 px-4 py-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Plug className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{app.name}</p>
                        <p className="truncate text-xs text-muted-foreground">
                            {(app.scopes.length ? app.scopes.map((s) => SCOPE_LABELS[s] ?? s) : ['Full access (CLI)']).join(' · ')}
                            {app.last_issued && ` · connected ${formatDistanceToNow(new Date(app.last_issued), { addSuffix: true })}`}
                        </p>
                    </div>
                    <Button variant="outline" size="sm" disabled={busy === app.client_id} onClick={() => disconnect(app)}>
                        Disconnect
                    </Button>
                </li>
            ))}
        </ul>
    );
}
