/** Jira-style durations, mirroring App\Models\Item::formatDuration/parseDuration (1d = 8h, 1w = 5d). */
export const MINUTES_PER_DAY = 8 * 60;
export const MINUTES_PER_WEEK = 5 * MINUTES_PER_DAY;

const UNITS: [string, number][] = [['w', MINUTES_PER_WEEK], ['d', MINUTES_PER_DAY], ['h', 60], ['m', 1]];

export function formatDuration(minutes: number | null | undefined): string {
    if (!minutes || minutes <= 0) return '0m';
    const parts: string[] = [];
    let rest = minutes;
    for (const [unit, size] of UNITS) {
        if (rest >= size) {
            parts.push(`${Math.floor(rest / size)}${unit}`);
            rest %= size;
        }
    }
    return parts.join(' ');
}
