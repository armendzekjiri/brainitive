import { Button } from '@/components/ui/button';
import PortalLayout from '@/Layouts/PortalLayout';
import type { FeedbackPortalData, PortalIdentityData } from '@/types';
import { Link, router } from '@inertiajs/react';
import { BellOff, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

interface Props {
    portal: FeedbackPortalData;
    post: { number: number; title: string; url: string };
    done: boolean;
    /** the signed URL to POST to */
    action: string;
    identity: PortalIdentityData | null;
}

/** The unfollow link from a status mail (signed; works without signing in). */
export default function FeedbackUnsubscribe({ portal, post, done, action, identity }: Props) {
    const [busy, setBusy] = useState(false);

    return (
        <PortalLayout title={`Unfollow · ${portal.title}`} brand={portal.title} subtitle="Feedback" identity={identity} width="max-w-md">
            <div className="space-y-4 rounded-2xl border bg-card p-6 text-center">
                {done ? (
                    <>
                        <CheckCircle2 className="mx-auto size-10 text-emerald-600 dark:text-emerald-400" />
                        <h1 className="text-xl font-semibold" data-testid="feedback-unfollowed">You no longer follow this idea</h1>
                        <p className="text-sm text-muted-foreground [overflow-wrap:anywhere]">“{post.title}” — we won’t email you about it any more.</p>
                    </>
                ) : (
                    <>
                        <BellOff className="mx-auto size-10 text-muted-foreground" />
                        <h1 className="text-xl font-semibold">Stop following this idea?</h1>
                        <p className="text-sm text-muted-foreground [overflow-wrap:anywhere]">“{post.title}”</p>
                        <Button
                            className="w-full"
                            disabled={busy}
                            onClick={() => router.post(action, {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                        >
                            Unfollow
                        </Button>
                    </>
                )}
                <Link href={post.url} className="block text-sm text-muted-foreground underline-offset-2 hover:underline">
                    View the idea
                </Link>
            </div>
        </PortalLayout>
    );
}
