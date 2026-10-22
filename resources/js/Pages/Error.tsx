import ApplicationLogo from '@/Components/ApplicationLogo';
import { Button } from '@/components/ui/button';
import type { PageProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, FileQuestion, Home, LayoutDashboard, LogIn, ServerCrash, ShieldX, Timer, Wrench } from 'lucide-react';

const MESSAGES: Record<number, { title: string; text: string; icon: typeof FileQuestion }> = {
    401: { title: 'Please sign in', text: 'You need to be signed in to see this page.', icon: LogIn },
    403: { title: 'No access', text: 'You don’t have permission to open this. Ask a workspace or project admin to invite you.', icon: ShieldX },
    404: { title: 'Page not found', text: 'This page doesn’t exist or was moved. Projects moved to another workspace have a new address.', icon: FileQuestion },
    405: { title: 'That didn’t work', text: 'This address can’t be opened like that. Go back and try again.', icon: FileQuestion },
    410: { title: 'Gone', text: 'This link is no longer available.', icon: FileQuestion },
    429: { title: 'Slow down a little', text: 'Too many requests in a short time. Wait a moment and try again.', icon: Timer },
    500: { title: 'Something went wrong', text: 'An unexpected error happened on our side. Try again in a moment; if it keeps happening, let us know.', icon: ServerCrash },
    503: { title: 'Back soon', text: 'Brainitive is being updated. This usually takes a minute.', icon: Wrench },
};

/**
 * Rendered by bootstrap/app.php for 401/403/404/405/410/429/500/503 (5xx only when debug is
 * off). Standalone (no AppLayout): shared props may be missing when the error happened early.
 */
export default function Error({ status }: { status: number }) {
    const { auth, currentWorkspace } = usePage<PageProps>().props as Partial<PageProps>;
    const signedIn = !!auth?.user;
    const message = MESSAGES[status] ?? MESSAGES[500];
    const Icon = message.icon;
    const canGoBack = typeof window !== 'undefined' && window.history.length > 1;

    return (
        <div className="min-h-screen bg-background text-foreground flex flex-col">
            <Head title={`${message.title} (${status})`} />
            <header className="px-6 py-5">
                <Link href={signedIn ? route('dashboard') : '/'} className="inline-flex items-center gap-2" aria-label="Brainitive">
                    <ApplicationLogo className="h-8 w-auto" />
                </Link>
            </header>

            <main className="flex flex-1 items-center justify-center px-6 pb-24">
                <div className="w-full max-w-md text-center">
                    <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                        <Icon className="h-8 w-8" />
                    </div>
                    <p className="text-sm font-semibold text-muted-foreground mb-2">Error {status}</p>
                    <h1 className="text-2xl sm:text-3xl font-bold mb-3">{message.title}</h1>
                    <p className="text-muted-foreground mb-8">{message.text}</p>

                    <div className="flex flex-wrap items-center justify-center gap-3">
                        {canGoBack && (
                            <Button variant="outline" onClick={() => window.history.back()}>
                                <ArrowLeft className="h-4 w-4" /> Go back
                            </Button>
                        )}
                        {signedIn ? (
                            currentWorkspace ? (
                                <Button asChild>
                                    <Link href={route('workspaces.show', currentWorkspace.slug)}>
                                        <LayoutDashboard className="h-4 w-4" /> Open {currentWorkspace.name}
                                    </Link>
                                </Button>
                            ) : (
                                <Button asChild>
                                    <Link href={route('dashboard')}>
                                        <LayoutDashboard className="h-4 w-4" /> Go to dashboard
                                    </Link>
                                </Button>
                            )
                        ) : (
                            <>
                                <Button variant={canGoBack ? 'outline' : 'default'} asChild>
                                    <a href="/">
                                        <Home className="h-4 w-4" /> Go home
                                    </a>
                                </Button>
                                <Button asChild>
                                    <a href={route('login')}>
                                        <LogIn className="h-4 w-4" /> Sign in
                                    </a>
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            </main>
        </div>
    );
}
