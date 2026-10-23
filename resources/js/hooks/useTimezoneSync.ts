import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { PageProps } from '@/types';

/**
 * Keeps `users.timezone` in step with the browser, so reminders and the morning task
 * summary fire at the user's local time. Sends at most once per page load, only on change.
 */
export function useTimezoneSync() {
    const user = usePage<PageProps>().props.auth?.user;

    useEffect(() => {
        if (!user) return;
        let zone: string | undefined;
        try {
            zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        } catch {
            return;
        }
        if (!zone || zone === user.timezone) return;
        // A zone the server rejects (rare browser aliases) is not retried on every page load.
        const failedKey = `tz-sync-failed:${zone}`;
        try {
            if (sessionStorage.getItem(failedKey)) return;
        } catch { /* storage blocked: just try */ }
        void window.axios.patch(route('profile.preferences'), { timezone: zone }).catch(() => {
            try { sessionStorage.setItem(failedKey, '1'); } catch { /* ignore */ }
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps -- once per user / zone
    }, [user?.id, user?.timezone]);
}
