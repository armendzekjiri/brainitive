import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import type { PageProps } from '@/types';

export interface WriteCallbacks {
    /** After a successful save (props are already reloaded). */
    onDone?: () => void;
    /** After a validation error (the first message is toasted). */
    onFail?: () => void;
    /** Request finished either way — clear "busy" here. */
    onFinish?: () => void;
}

/** Upper bound of any weekly amount, mirrors WorkloadPlanner::MAX_MINUTES. */
export const MAX_WEEK_MINUTES = 168 * 60;

/**
 * Inertia writes of the workload page: keep scroll + component state, toast the server's
 * message, and show the first validation error (e.g. "changed by someone else").
 * Callers disable their buttons until onFinish: every write carries what the planner saw
 * (`expected_minutes`), so a second click would be refused as a conflict.
 */
export function workloadWrite(method: 'put' | 'delete', url: string, data: Record<string, unknown>, callbacks: WriteCallbacks = {}) {
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page: { props: unknown }) => {
            const message = (page.props as PageProps).flash?.success;
            if (message) toast.success(message);
            callbacks.onDone?.();
        },
        onError: (errors: Record<string, string>) => {
            toast.error(Object.values(errors)[0] ?? 'Could not save.');
            callbacks.onFail?.();
        },
        onFinish: () => callbacks.onFinish?.(),
    };
    if (method === 'delete') {
        router.delete(url, options);
    } else {
        router.put(url, data as never, options);
    }
}
