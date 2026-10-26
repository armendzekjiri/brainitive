import type { ImportStatus } from '@/types';

export const STATUS_LABELS: Record<ImportStatus, string> = {
    uploaded: 'Uploaded',
    previewed: 'Ready to import',
    queued: 'Queued',
    running: 'Importing…',
    completed: 'Done',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

export function statusTone(status: ImportStatus): string {
    switch (status) {
        case 'completed': return 'border-green-500/40 text-green-700 dark:text-green-400';
        case 'failed': return 'border-destructive/40 text-destructive';
        case 'running':
        case 'queued': return 'border-primary/40 text-primary';
        default: return '';
    }
}
