import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatDistanceToNow } from 'date-fns';
import { KeyRound, Plug } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { ApiKeySummary } from '@/types';

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

/**
 * Apps with access to this account (MCP connectors, the browser extension, the CLI) — revoke any of them —
 * and the active personal API keys (managed on the API keys page).
 */
export default function ConnectedAppsForm({ apps, apiKeys = [], className = '' }: { apps: ConnectedApp[]; apiKeys?: ApiKeySummary[]; className?: string }) {
    const [busy, setBusy] = useState<string | null>(null);

    if (apps.length === 0 && apiKeys.length === 0) {
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
            {apiKeys.map((key) => (
                <li key={`api-key-${key.id}`} className="flex items-center gap-3 px-4 py-3" data-testid="connected-api-key">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <KeyRound className="size-4" aria-hidden />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{key.name}</p>
                        <p className="truncate text-xs text-muted-foreground">
                            API key · <span className="font-mono">{key.prefix}…</span> ·{' '}
                            {key.last_used_at ? `last used ${formatDistanceToNow(new Date(key.last_used_at), { addSuffix: true })}` : 'never used'}
                        </p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('profile.api-keys.index')} aria-label={`Manage ${key.name}`}>
                            Manage
                        </Link>
                    </Button>
                </li>
            ))}
        </ul>
    );
}
