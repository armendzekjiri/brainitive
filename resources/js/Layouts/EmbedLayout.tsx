import { PropsWithChildren } from 'react';

/**
 * Minimal layout for embeddable widgets (iframe). No nav, logo, or app chrome.
 */
export default function EmbedLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-muted/50 p-6">
            <div className="mx-auto max-w-[520px]">{children}</div>
        </div>
    );
}
