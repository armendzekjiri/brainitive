import { Head } from '@inertiajs/react';
import { ArrowLeft, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import ApplicationLogo from '@/components/ApplicationLogo';
import { LegalFooterLinks } from '@/components/legal/LegalFooterLinks';
import type { LegalPageData } from '@/types';
import { cn } from '@/lib/utils';

/**
 * Shell of the legal pages (BRAI-156): title, version + date, optional table of contents, and the
 * legal footer. Theme tokens only, so it works in light and dark mode and at phone width.
 *
 * The TEXTS on these pages are drafts written to an EU/German standard; they still have to be
 * confirmed by a qualified lawyer before they are relied on — see docs/legal/README.md.
 */
export function LegalLayout({
    title,
    icon: Icon,
    legal,
    intro,
    toc,
    children,
}: {
    title: string;
    icon: LucideIcon;
    legal: LegalPageData;
    intro?: ReactNode;
    toc?: { id: string; title: string }[];
    children: ReactNode;
}) {
    const updated = new Date(`${legal.document.updated}T00:00:00Z`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });

    return (
        <>
            <Head title={`${title} – ${legal.app_name}`} />
            <div className="min-h-screen bg-background text-foreground">
                <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-12">
                    <a href="/" className="mb-8 inline-flex items-center gap-2 text-sm font-medium text-muted-foreground transition-colors hover:text-primary">
                        <ArrowLeft className="h-4 w-4" />
                        Back to home
                    </a>

                    <header className="flex items-start gap-4">
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <Icon className="h-6 w-6" />
                        </div>
                        <div className="min-w-0">
                            <h1 className="text-2xl font-bold tracking-tight sm:text-3xl" data-testid="legal-title">
                                {title}
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground" data-testid="legal-version">
                                Version {legal.document.version} · Last updated {updated}
                            </p>
                        </div>
                    </header>

                    {intro && <div className="mt-8 space-y-3 text-sm leading-relaxed text-muted-foreground">{intro}</div>}

                    {toc && toc.length > 2 && (
                        <nav aria-label="Contents" className="mt-8 rounded-2xl border bg-card p-5">
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Contents</p>
                            <ol className="grid gap-1 text-sm sm:grid-cols-2">
                                {toc.map((t, i) => (
                                    <li key={t.id}>
                                        <a href={`#${t.id}`} className="text-primary hover:underline">
                                            {i + 1}. {t.title}
                                        </a>
                                    </li>
                                ))}
                            </ol>
                        </nav>
                    )}

                    <div className="mt-8 space-y-5">{children}</div>

                    <footer className="mt-14 flex flex-col items-center gap-4 border-t pt-8 sm:flex-row sm:justify-between">
                        <a href="/" className="inline-flex items-center gap-2 text-sm font-semibold text-primary">
                            <ApplicationLogo className="h-5 w-auto" alt="" /> Home
                        </a>
                        <LegalFooterLinks extended className="text-sm text-muted-foreground" linkClassName="hover:text-foreground" />
                    </footer>
                </div>
            </div>
        </>
    );
}

/** One numbered section; `id` is the anchor for the table of contents. */
export function LegalSection({ id, num, title, children }: { id: string; num?: number; title: string; children: ReactNode }) {
    return (
        <section id={id} className="scroll-mt-6 rounded-2xl border bg-card p-5 sm:p-6">
            <h2 className="text-base font-bold sm:text-lg">
                {num !== undefined && `${num}. `}
                {title}
            </h2>
            <div className="mt-3 space-y-3 text-sm leading-relaxed text-muted-foreground [&_a]:text-primary [&_a]:underline-offset-2 hover:[&_a]:underline [&_li]:ml-5 [&_li]:list-disc [&_strong]:text-foreground">
                {children}
            </div>
        </section>
    );
}

/** A responsive table (scrolls sideways on phones instead of breaking the page). */
export function LegalTable({ head, rows, className }: { head: string[]; rows: ReactNode[][]; className?: string }) {
    return (
        <div className={cn('-mx-1 overflow-x-auto', className)}>
            <table className="w-full min-w-[34rem] border-collapse text-left text-xs sm:text-sm">
                <thead>
                    <tr className="border-b">
                        {head.map((h) => (
                            <th key={h} className="px-2 py-2 font-semibold text-foreground">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i} className="border-b align-top last:border-0">
                            {row.map((cell, j) => (
                                <td key={j} className="px-2 py-2">
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** A fact the operator still has to confirm; visible on purpose (docs/legal/README.md). */
export function ToConfirm({ children }: { children: ReactNode }) {
    return (
        <span className="rounded bg-amber-500/15 px-1 py-0.5 text-amber-700 dark:text-amber-300" data-testid="to-confirm">
            [{children} — to be confirmed]
        </span>
    );
}

/** "the operator of Brainitive (an individual)" or the configured name. */
export function operatorName(legal: LegalPageData): string {
    return legal.operator.name ?? `the operator of ${legal.app_name}`;
}

/** Contact email as a mailto link, or a neutral phrase when none is configured. */
export function ContactEmail({ legal }: { legal: LegalPageData }) {
    if (!legal.operator.email) return <>the contact details of this installation's operator</>;
    return <a href={`mailto:${legal.operator.email}`}>{legal.operator.email}</a>;
}

/**
 * Self-hosted installs: our Cloud texts don't apply. Shows who runs this installation (if configured)
 * and links the operator's own document; never Brainitive Cloud's operator data.
 */
export function SelfHostedNotice({ legal, document, externalUrl }: { legal: LegalPageData; document: string; externalUrl: string | null }) {
    const op = legal.operator;
    return (
        <LegalSection id="installation" title="About this installation">
            <p data-testid="self-hosted-notice">
                This is a self-hosted installation of {legal.app_name}
                {legal.host ? <> at <strong>{legal.host}</strong></> : null}. It is run by{' '}
                {op.name ? <strong>{op.name}</strong> : 'its operator'}, who is responsible for it and for the personal data processed in it. The
                developers of the open-source {legal.app_name} software do not run this installation and receive no data from it.
            </p>
            {externalUrl ? (
                <p>
                    <a href={externalUrl} rel="noopener noreferrer" data-testid="operator-document">
                        Read the operator's {document}
                    </a>
                </p>
            ) : (
                <p>The operator of this installation has not linked a {document} here. Please contact them directly{op.email ? <> at <ContactEmail legal={legal} /></> : null}.</p>
            )}
        </LegalSection>
    );
}
