import { PropsWithChildren } from 'react';

/**
 * Minimal layout for embeddable widgets (iframe). No nav, logo, or app chrome.
 */
export default function EmbedLayout({ children, wide = false }: PropsWithChildren<{ wide?: boolean }>) {
    return (
        <div className={wide ? 'min-h-screen bg-muted/50 px-4 py-10 sm:py-16' : 'min-h-screen bg-muted/50 p-6'}>
            <div className={wide ? 'mx-auto max-w-[640px]' : 'mx-auto max-w-[520px]'}>{children}</div>
        </div>
    );
}
