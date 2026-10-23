import { Button } from '@/components/ui/button';
import { PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

/** Turns the 08:00 "due today / overdue" summary for list tasks on or off (`users.task_digest`). */
export default function TaskDigestSetting({ className = '' }: { className?: string }) {
    const user = usePage<PageProps>().props.auth.user;
    const enabled = user.task_digest !== false;
    const [busy, setBusy] = useState(false);

    const toggle = () => {
        setBusy(true);
        router.patch(route('profile.preferences'), { task_digest: !enabled }, {
            preserveScroll: true,
            onSuccess: () => toast.success(enabled ? 'Morning task summary turned off' : 'Morning task summary turned on'),
            onError: () => toast.error('Could not save the setting.'),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className={className}>
            <div className="flex items-center justify-between gap-4">
                <div className="space-y-1">
                    <p className="text-sm font-medium">Morning task summary</p>
                    <p className="text-sm text-muted-foreground">
                        At 8:00 you get one notification with the list tasks that are due today or overdue. Task reminders you set
                        on a task are sent either way.
                    </p>
                    {user.timezone && <p className="text-xs text-muted-foreground">Times use your time zone: {user.timezone.replace(/_/g, ' ')}</p>}
                </div>
                <Button onClick={toggle} disabled={busy} variant={enabled ? 'outline' : 'default'}>
                    {busy ? 'Saving…' : enabled ? 'Turn off' : 'Turn on'}
                </Button>
            </div>
        </div>
    );
}
