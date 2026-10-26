import ApplicationLogo from '@/components/ApplicationLogo';
import { Button } from '@/components/ui/button';
import type { PortalIdentityData } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { LogIn, LogOut } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Shell for pages people outside the workspace see (v1.42.0): the feedback portal (BRAI-60) and the
 * client portal (BRAI-73). No app chrome; a brand line, the viewer (or "Sign in"), and noindex.
 * Contract: docs/ai/tasks/release-v1.42.0.md.
 */
export default function PortalLayout({
    title,
    brand,
    subtitle,
    identity,
    returnTo,
    actions,
    width = 'max-w-4xl',
    children,
}: {
    /** <title> */
    title: string;
    /** the portal's name in the header (e.g. the project), next to the Brainitive logo */
    brand?: string | null;
    subtitle?: string | null;
    /** PortalAuth::shared() — null when nobody is signed in */
    identity: PortalIdentityData | null;
    /** where "Sign in" / "Sign out" come back to; defaults to the current path */
    returnTo?: string;
    /** extra header buttons (left of the viewer) */
    actions?: ReactNode;
    width?: string;
    children: ReactNode;
}) {
    const back = returnTo ?? (typeof window !== 'undefined' ? window.location.pathname : '/');

    return (
        <div className="min-h-screen bg-background text-foreground flex flex-col">
            <Head title={title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <header className="border-b">
                <div className={`mx-auto ${width} px-4 sm:px-6 py-3 flex items-center gap-3`}>
                    <a href="/" aria-label="Brainitive" className="shrink-0">
                        <ApplicationLogo className="h-7 w-auto" />
                    </a>
                    {brand && (
                        <div className="min-w-0 border-l pl-3">
                            <p className="truncate font-semibold leading-tight" data-testid="portal-brand">{brand}</p>
                            {subtitle && <p className="truncate text-xs text-muted-foreground">{subtitle}</p>}
                        </div>
                    )}
                    <div className="ml-auto flex items-center gap-2">
                        {actions}
                        {identity ? (
                            <div className="flex items-center gap-2" data-testid="portal-identity">
                                <span className="hidden sm:block text-right leading-tight">
                                    <span className="block text-sm font-medium">{identity.name}</span>
                                    <span className="block text-xs text-muted-foreground">{identity.email}</span>
                                </span>
                                {!identity.is_member && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.post(route('guest.logout'), { return_to: back })}
                                        aria-label="Sign out"
                                    >
                                        <LogOut className="size-4" />
                                        <span className="hidden sm:inline">Sign out</span>
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('guest.login', { return_to: back })}>
                                    <LogIn className="size-4" />
                                    Sign in
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            </header>
            <main className={`mx-auto w-full ${width} flex-1 px-4 sm:px-6 py-8`}>{children}</main>
            <footer className="py-6 text-center text-xs text-muted-foreground">
                Powered by{' '}
                <a href="/" className="underline-offset-2 hover:underline">
                    Brainitive
                </a>
            </footer>
        </div>
    );
}
