import { usePage } from '@inertiajs/react';
import type { LegalLinks } from '@/types';
import { cn } from '@/lib/utils';

/**
 * Imprint · Privacy · Terms · Cookies · Sub-processors (BRAI-156), from the shared `legalLinks`
 * prop (LegalInfo::links). Documents an install doesn't have (self-hosted: imprint without operator
 * data, the Cloud-only sub-processor list / DPA / refund policy) are left out.
 */
export function LegalFooterLinks({
    className,
    linkClassName,
    extended = false,
}: {
    className?: string;
    linkClassName?: string;
    /** also the DPA and refund policy (legal pages' own footer) */
    extended?: boolean;
}) {
    const links = (usePage().props as { legalLinks?: LegalLinks }).legalLinks;
    if (!links) return null;

    const items: [string, string | null][] = [
        ['Imprint', links.imprint],
        ['Privacy', links.privacy],
        ['Terms', links.terms],
        ['Cookies', links.cookies],
        ['Sub-processors', links.subprocessors],
        ...(extended ? ([['DPA', links.dpa], ['Refund policy', links.refund]] as [string, string | null][]) : []),
    ];

    return (
        <nav aria-label="Legal" className={cn('flex flex-wrap items-center justify-center gap-x-4 gap-y-1', className)} data-testid="legal-footer-links">
            {items
                .filter((item): item is [string, string] => !!item[1])
                .map(([label, href]) => (
                    <a key={label} href={href} className={cn('hover:underline underline-offset-2', linkClassName)}>
                        {label}
                    </a>
                ))}
        </nav>
    );
}
