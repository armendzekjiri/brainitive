import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { ArrowUpCircle, X } from 'lucide-react';
import type { PageProps } from '@/types';

/**
 * A thin strip under the top bar when a newer Brainitive release is out (BRAI-433). Only instance
 * admins of self-hosted installs get the `updateNotice` prop; dismissing hides it for this admin
 * until an even newer release comes out (stored server-side, per version). Instance admin →
 * Overview keeps showing the update either way.
 */
export function UpdateNoticeBanner() {
    const { updateNotice } = usePage<PageProps>().props;
    const [hiddenFor, setHiddenFor] = useState<string | null>(null);

    if (!updateNotice || hiddenFor === updateNotice.latest) return null;

    const dismiss = () => {
        // no page visit (an editor in the middle of typing stays as it is); the next visit gets null
        setHiddenFor(updateNotice.latest);
        axios.post(route('admin.update-notice.dismiss'), { version: updateNotice.latest }).catch(() => {
            setHiddenFor(null); // not saved: show it again rather than pretend
        });
    };

    return (
        <div
            role="status"
            aria-label="Update available"
            className="flex shrink-0 items-start gap-2 border-b border-sky-200 bg-sky-50 py-2 pl-[max(0.75rem,env(safe-area-inset-left))] pr-[max(0.5rem,env(safe-area-inset-right))] text-sm text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/40 dark:text-sky-200 md:items-center md:px-4"
        >
            <ArrowUpCircle className="mt-0.5 size-4 shrink-0 md:mt-0" />
            <p className="min-w-0 flex-1">
                <span className="font-medium">Brainitive v{updateNotice.latest} available</span>
                {updateNotice.current && <> — this instance runs v{updateNotice.current}.</>}{' '}
                {updateNotice.release_url && (
                    <>
                        <a href={updateNotice.release_url} target="_blank" rel="noopener noreferrer" className="font-medium underline underline-offset-2 hover:no-underline">
                            Release notes
                        </a>
                        {' · '}
                    </>
                )}
                <a href={updateNotice.upgrade_docs_url} target="_blank" rel="noopener noreferrer" className="font-medium underline underline-offset-2 hover:no-underline">
                    How to upgrade
                </a>
                {' · '}
                <Link href={route('admin.overview')} className="font-medium underline underline-offset-2 hover:no-underline">
                    Instance admin
                </Link>
            </p>
            <button
                type="button"
                onClick={dismiss}
                className="-my-1 shrink-0 rounded-sm p-1 text-sky-800/80 hover:text-sky-950 dark:text-sky-200/80 dark:hover:text-sky-50"
                aria-label="Dismiss update notice"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}
