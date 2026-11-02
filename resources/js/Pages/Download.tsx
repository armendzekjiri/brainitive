import { Head } from '@inertiajs/react';
import { ArrowLeft, Apple, Check, Download as DownloadIcon, Monitor, Terminal } from 'lucide-react';
import ApplicationLogo from '@/components/ApplicationLogo';
import { LegalFooterLinks } from '@/components/legal/LegalFooterLinks';
import { DOCS_URL } from '@/lib/docs';

/*
 * Download page of Brainitive Desktop (v1.49.0). Public. A tile gets an active button only when its
 * URL is set in config/desktop.php (DESKTOP_DOWNLOAD_*_URL); until then it says "Coming soon".
 */

type Platform = 'macos' | 'windows' | 'linux';

const PLATFORMS: { key: Platform; name: string; detail: string; icon: typeof Apple }[] = [
    { key: 'macos', name: 'macOS', detail: 'Apple silicon and Intel', icon: Apple },
    { key: 'windows', name: 'Windows', detail: 'Windows 10 and 11', icon: Monitor },
    { key: 'linux', name: 'Linux', detail: 'AppImage and .deb', icon: Terminal },
];

const HIGHLIGHTS = [
    'Quick capture from anywhere with a global shortcut',
    'My Day and the timer in your menu bar',
    'Captures kept offline and sent when you are back online',
    'Works with Brainitive Cloud or your own server',
];

export default function Download({ downloads }: { downloads: Record<Platform, string | null> }) {
    return (
        <>
            <Head title="Download Brainitive Desktop" />
            <div className="min-h-screen bg-background text-foreground">
                <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-12">
                    <a href="/" className="mb-8 inline-flex items-center gap-2 text-sm font-medium text-muted-foreground transition-colors hover:text-primary">
                        <ArrowLeft className="h-4 w-4" />
                        Back to Brainitive
                    </a>

                    <header>
                        <div className="inline-flex items-center gap-2 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300">
                            Beta · coming soon
                        </div>
                        <h1 className="mt-4 text-2xl font-bold tracking-tight sm:text-3xl" data-testid="download-title">
                            Brainitive Desktop
                        </h1>
                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                            Brainitive in your menu bar and one shortcut away. Sign in with your browser, on Brainitive Cloud or on your own server.
                        </p>
                    </header>

                    <ul className="mt-6 space-y-2">
                        {HIGHLIGHTS.map((text) => (
                            <li key={text} className="flex items-start gap-2.5 text-sm text-muted-foreground">
                                <Check className="mt-0.5 h-4 w-4 shrink-0 text-[#69A748]" strokeWidth={2.5} />
                                {text}
                            </li>
                        ))}
                    </ul>

                    <div className="mt-8 grid gap-4 sm:grid-cols-3">
                        {PLATFORMS.map((p) => {
                            const url = downloads[p.key];
                            return (
                                <section key={p.key} aria-label={p.name} data-testid={`download-${p.key}`} className="flex flex-col rounded-2xl border bg-card p-5">
                                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                        <p.icon className="h-5 w-5" />
                                    </div>
                                    <h2 className="mt-3 text-base font-bold">{p.name}</h2>
                                    <p className="mt-1 flex-1 text-xs text-muted-foreground">{p.detail}</p>
                                    {url ? (
                                        <a
                                            href={url}
                                            rel="noopener"
                                            className="mt-4 inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90"
                                        >
                                            <DownloadIcon className="h-4 w-4" /> Download
                                        </a>
                                    ) : (
                                        <button
                                            type="button"
                                            disabled
                                            className="mt-4 inline-flex cursor-not-allowed items-center justify-center rounded-xl border bg-muted px-4 py-2 text-sm font-semibold text-muted-foreground"
                                        >
                                            Coming soon
                                        </button>
                                    )}
                                </section>
                            );
                        })}
                    </div>

                    <p className="mt-6 text-sm text-muted-foreground">
                        Running your own server? Read how to prepare it in the{' '}
                        <a href={`${DOCS_URL}/guide/desktop-app`} className="text-primary hover:underline">
                            Desktop app guide
                        </a>
                        .
                    </p>

                    <footer className="mt-14 flex flex-col items-center gap-4 border-t pt-8 sm:flex-row sm:justify-between">
                        <a href="/" className="inline-flex items-center gap-2 text-sm font-semibold text-primary">
                            <ApplicationLogo className="h-5 w-auto" alt="" /> Home
                        </a>
                        <LegalFooterLinks className="text-sm text-muted-foreground" linkClassName="hover:text-foreground" />
                    </footer>
                </div>
            </div>
        </>
    );
}
