import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import type { PageProps } from '@/types';

/**
 * Shows the server's flash message (`success` / `error`) as a toast on the automation pages
 * (v1.40.0, BRAI-63). Keyed on the flash object, which is new on every visit, so the same message
 * twice in a row (switching a rule on, off, on) still shows each time.
 */
export function useFlashToast() {
    const { flash } = usePage<PageProps>().props;

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);
}
