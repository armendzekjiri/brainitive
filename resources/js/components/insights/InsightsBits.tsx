import { useEffect, useRef, useState, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Building blocks of the Insights pages (BRAI-105). Charts are plain SVG / CSS with the validated
 * `.viz-root` colour roles from app.css (no chart library): `--viz-series-1` = the measured series
 * (completed), `--viz-reference` = the comparison (created).
 */

export function Section({ title, description, actions, children, className }: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('rounded-xl border bg-card p-4 text-card-foreground shadow-sm', className)} aria-label={title}>
            <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold">{title}</h2>
                    {description && <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>}
                </div>
                {actions}
            </div>
            {children}
        </section>
    );
}

export function StatTile({ label, value, hint, testId }: { label: string; value: ReactNode; hint?: ReactNode; testId?: string }) {
    return (
        <div className="rounded-xl border bg-card px-4 py-3 text-card-foreground shadow-sm" data-testid={testId}>
            <div className="text-xs font-medium text-muted-foreground">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums" data-testid={testId ? `${testId}-value` : undefined}>{value}</div>
            {hint && <div className="mt-0.5 text-xs text-muted-foreground">{hint}</div>}
        </div>
    );
}

export function Empty({ children }: { children: ReactNode }) {
    return <p className="rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">{children}</p>;
}

/** 1.5 → "1.5 h", 0 → "0 h". */
export function formatHours(hours: number): string {
    return `${Math.round(hours * 100) / 100} h`;
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    return `${value >= 10 ? value.toFixed(0) : value.toFixed(1)} ${units[unit]}`;
}

/** "3 days ago" / "just now" from an ISO time; "—" without one. */
export function timeAgo(iso: string | null): string {
    if (!iso) return '—';
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    const steps: [number, Intl.RelativeTimeFormatUnit][] = [[60, 'second'], [3600, 'minute'], [86400, 'hour'], [604800, 'day'], [2629800, 'week'], [31557600, 'month'], [Infinity, 'year']];
    let previous = 1;
    for (const [limit, unit] of steps) {
        if (Math.abs(seconds) < limit) return seconds < 45 && unit === 'second' ? 'just now' : rtf.format(-Math.round(seconds / previous), unit);
        previous = limit;
    }
    return '—';
}

export function fullTime(iso: string | null): string | undefined {
    return iso ? new Date(iso).toLocaleString() : undefined;
}

/** A horizontal bar for a table cell: `value / max` of the width. */
export function InlineBar({ value, max, reference = false }: { value: number; max: number; reference?: boolean }) {
    const width = max > 0 ? Math.max(value > 0 ? 3 : 0, (value / max) * 100) : 0;
    return (
        <div className="viz-root h-2 w-full min-w-16 rounded-full bg-muted" aria-hidden>
            <div className="h-2 rounded-full" style={{ width: `${width}%`, background: reference ? 'var(--viz-reference)' : 'var(--viz-series-1)' }} />
        </div>
    );
}

export function Legend({ items }: { items: { label: string; color: string }[] }) {
    return (
        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            {items.map((item) => (
                <span key={item.label} className="inline-flex items-center gap-1.5">
                    <span className="size-2 rounded-full" style={{ background: item.color }} />
                    {item.label}
                </span>
            ))}
        </div>
    );
}

const MARGIN = { top: 20, right: 12, bottom: 28, left: 36 };
const HEIGHT = 240;

function niceTicks(max: number): number[] {
    const step = max <= 5 ? 1 : Math.pow(10, Math.floor(Math.log10(max))) * (max / Math.pow(10, Math.floor(Math.log10(max))) > 5 ? 2 : 1);
    const top = Math.ceil(max / step) * step;
    const ticks: number[] = [];
    for (let t = 0; t <= top; t += step) ticks.push(t);
    return ticks;
}

/** Rounded-top column anchored to the baseline. */
function column(x: number, y: number, w: number, base: number): string {
    const h = base - y;
    if (h <= 0) return '';
    const r = Math.min(3, w / 2, h);
    return `M${x},${base} V${y + r} Q${x},${y} ${x + r},${y} H${x + w - r} Q${x + w},${y} ${x + w},${y + r} V${base} Z`;
}

/** "Oct 6" for a week's Monday (Y-m-d, no time zone shift). */
function weekLabel(day: string): string {
    const [y, m, d] = day.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' });
}

/** Created vs completed per week: grouped columns with a hover tooltip and a screen-reader table. */
export function WeeklyChart({ weeks }: { weeks: { week: string; created: number; completed: number }[] }) {
    const ref = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(640);
    const [hover, setHover] = useState<number | null>(null);
    useEffect(() => {
        if (!ref.current) return;
        const observer = new ResizeObserver(([entry]) => setWidth(Math.max(160, entry.contentRect.width)));
        observer.observe(ref.current);
        return () => observer.disconnect();
    }, []);

    const innerW = width - MARGIN.left - MARGIN.right;
    const innerH = HEIGHT - MARGIN.top - MARGIN.bottom;
    const ticks = niceTicks(Math.max(1, ...weeks.flatMap((w) => [w.created, w.completed])));
    const yMax = ticks[ticks.length - 1];
    const y = (v: number) => MARGIN.top + innerH - (v / yMax) * innerH;
    const base = MARGIN.top + innerH;
    const band = innerW / Math.max(1, weeks.length);
    const gap = band < 12 ? 0.5 : 2;
    const barW = Math.max(0.5, Math.min(18, (band - gap - (band < 12 ? 1 : 8)) / 2));
    const labelEvery = Math.ceil(weeks.length / Math.max(1, Math.floor(innerW / 56)));

    return (
        <div ref={ref} className="viz-root relative overflow-hidden">
            <svg width={width} height={HEIGHT} role="group" aria-label="Issues created and completed per week">
                {ticks.map((t) => (
                    <g key={t}>
                        <line x1={MARGIN.left} x2={width - MARGIN.right} y1={y(t)} y2={y(t)} stroke="var(--viz-grid)" strokeWidth={1} />
                        <text x={MARGIN.left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="fill-muted-foreground text-[11px]">{t}</text>
                    </g>
                ))}
                {weeks.map((w, i) => {
                    const left = MARGIN.left + band * i + (band - (barW * 2 + gap)) / 2;
                    return (
                        <g key={w.week} opacity={hover !== null && hover !== i ? 0.55 : 1}>
                            <path d={column(left, y(w.created), barW, base)} fill="var(--viz-reference)" />
                            <path d={column(left + barW + gap, y(w.completed), barW, base)} fill="var(--viz-series-1)" />
                            {i % labelEvery === 0 && (
                                <text x={MARGIN.left + band * i + band / 2} y={HEIGHT - 8} textAnchor="middle" className="fill-muted-foreground text-[11px]">{weekLabel(w.week)}</text>
                            )}
                            {/* hit target: the whole week band; hover, tap (pins) or keyboard focus shows the numbers */}
                            <rect
                                x={MARGIN.left + band * i} y={MARGIN.top} width={band} height={innerH} fill="transparent"
                                tabIndex={0} aria-label={`Week of ${weekLabel(w.week)}: ${w.created} created, ${w.completed} completed`}
                                className="outline-none focus-visible:stroke-[var(--viz-series-1)]"
                                onPointerEnter={(e) => e.pointerType === 'mouse' && setHover(i)}
                                onPointerLeave={(e) => e.pointerType === 'mouse' && setHover(null)}
                                onClick={() => setHover((h) => (h === i ? null : i))}
                                onFocus={() => setHover(i)}
                                onBlur={() => setHover(null)}
                            />
                        </g>
                    );
                })}
            </svg>
            {hover !== null && weeks[hover] && (
                <div
                    className="pointer-events-none absolute z-10 rounded-md border bg-popover px-3 py-2 text-xs shadow-md"
                    style={{ left: Math.min(MARGIN.left + band * hover + band / 2, width - 160), top: 0 }}
                >
                    <div className="font-medium">Week of {weekLabel(weeks[hover].week)}</div>
                    <div className="text-muted-foreground"><strong className="text-foreground">{weeks[hover].created}</strong> created</div>
                    <div className="text-muted-foreground"><strong className="text-foreground">{weeks[hover].completed}</strong> completed</div>
                </div>
            )}
            <table className="sr-only">
                <caption>Issues created and completed per week</caption>
                <thead><tr><th>Week starting</th><th>Created</th><th>Completed</th></tr></thead>
                <tbody>{weeks.map((w) => <tr key={w.week}><td>{w.week}</td><td>{w.created}</td><td>{w.completed}</td></tr>)}</tbody>
            </table>
        </div>
    );
}
