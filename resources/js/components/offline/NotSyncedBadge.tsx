import { CloudOff } from 'lucide-react';
import { cn } from '@/lib/utils';

/** "Not synced yet" marker on a row / field changed on this device (BRAI-82). */
export default function NotSyncedBadge({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200',
                className,
            )}
        >
            <CloudOff className="h-3 w-3" aria-hidden />
            Not synced yet
        </span>
    );
}
