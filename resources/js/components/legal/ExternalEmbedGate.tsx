import { useState, type ReactNode } from 'react';
import { ExternalLink } from 'lucide-react';

const STORAGE_KEY = 'brainitive-embeds-allowed';

function allowedProviders(): string[] {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        const list = raw ? (JSON.parse(raw) as unknown) : [];
        return Array.isArray(list) ? list.filter((v): v is string => typeof v === 'string') : [];
    } catch {
        return [];
    }
}

function rememberProvider(id: string): void {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(new Set([...allowedProviders(), id]))));
    } catch {
        // storage blocked: the choice simply isn't remembered
    }
}

/**
 * Two-click embeds (BRAI-156): a YouTube / Figma / X embed only loads — and only then sends the
 * viewer's IP address to that service — after the viewer clicks. "Always load" remembers the
 * choice per service on this device (localStorage `brainitive-embeds-allowed`; see /cookies).
 */
export function ExternalEmbedGate({
    providerId,
    providerName,
    url,
    className,
    children,
}: {
    /** stable key, e.g. "youtube" */
    providerId: string;
    /** shown to the viewer, e.g. "YouTube (Google)" */
    providerName: string;
    /** the original link, so it can also be opened directly */
    url?: string | null;
    className?: string;
    children: ReactNode;
}) {
    const [allowed, setAllowed] = useState(() => allowedProviders().includes(providerId));

    if (allowed) return <>{children}</>;

    return (
        <div
            className={className ?? 'mx-auto my-2 max-w-2xl rounded-lg border border-dashed bg-muted/40 p-5 text-center'}
            contentEditable={false}
            data-testid="external-embed-gate"
        >
            <p className="text-sm font-medium text-foreground">Embedded content from {providerName}</p>
            <p className="mx-auto mt-1 max-w-md text-xs leading-relaxed text-muted-foreground">
                Loading it connects your browser to {providerName}, which receives your IP address and may set cookies.
            </p>
            <div className="mt-3 flex flex-wrap items-center justify-center gap-2">
                <button
                    type="button"
                    onClick={() => setAllowed(true)}
                    className="rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary/90"
                >
                    Load content
                </button>
                <button
                    type="button"
                    onClick={() => {
                        rememberProvider(providerId);
                        setAllowed(true);
                    }}
                    className="rounded-md border px-3 py-1.5 text-xs font-medium hover:bg-muted"
                >
                    Always load {providerName}
                </button>
                {url && /^https:\/\//i.test(url) && (
                    <a href={url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground">
                        Open on {providerName} <ExternalLink className="h-3 w-3" />
                    </a>
                )}
            </div>
        </div>
    );
}
