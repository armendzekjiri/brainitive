import { formatDistanceToNow } from 'date-fns';

export function relative(date: string | null | undefined, fallback = 'Never'): string {
    return date ? formatDistanceToNow(new Date(date), { addSuffix: true }) : fallback;
}

export function bytes(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }
    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

export function plural(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}
