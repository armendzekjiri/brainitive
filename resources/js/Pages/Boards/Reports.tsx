import { PointerEvent, useEffect, useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { LineChart as LineChartIcon, Table2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { BoardHeader } from '@/components/board/BoardHeader';
import { StatusLozenge } from '@/components/board/ItemPrimitives';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Board, PageProps, Project, Workspace } from '@/types';
import { cn } from '@/lib/utils';

interface SprintOption {
    id: number;
    name: string;
    status: 'active' | 'completed';
    start_date?: string | null;
    end_date?: string | null;
}

interface Burndown {
    unit: 'points' | 'issues';
    total: number;
    days: { date: string; ideal: number; remaining: number | null }[];
}

interface VelocityRow {
    id: number;
    name: string;
    committed_points: number;
    completed_points: number;
    committed_issues: number;
    completed_issues: number;
}

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    board: Board;
    sprints: SprintOption[];
    selectedSprint: (SprintOption & { goal?: string | null }) | null;
    burndown: Burndown | null;
    velocity: VelocityRow[];
}

const MARGIN = { top: 16, right: 24, bottom: 32, left: 40 };
const CHART_HEIGHT = 280;
const BAR_WIDTH = 22;
const BAR_GAP = 2;

/** Container width for responsive SVG charts. */
function useWidth<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(640);
    useEffect(() => {
        if (!ref.current) return;
        const observer = new ResizeObserver(([entry]) => setWidth(Math.max(320, entry.contentRect.width)));
        observer.observe(ref.current);
        return () => observer.disconnect();
    }, []);
    return [ref, width] as const;
}

/** 0..max split into ~4 round steps. */
function niceTicks(max: number): number[] {
    if (max <= 0) return [0, 1];
    const rough = max / 4;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 5, 10].map((m) => m * magnitude).find((s) => s >= rough) ?? rough;
    const ticks: number[] = [];
    for (let v = 0; v <= max + step * 0.001; v += step) ticks.push(Math.round(v * 100) / 100);
    if (ticks[ticks.length - 1] < max) ticks.push(ticks[ticks.length - 1] + step);
    return ticks;
}

/** Column with a 4px rounded data-end, square at the baseline. */
function columnPath(x: number, y: number, w: number, base: number): string {
    const h = base - y;
    if (h <= 0) return '';
    const r = Math.min(4, h, w / 2);
    return `M${x},${base}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${base}Z`;
}

function Legend({ items }: { items: { label: string; color: string }[] }) {
    return (
        <div className="flex flex-wrap items-center gap-4 text-[12px] text-muted-foreground">
            {items.map((item) => (
                <span key={item.label} className="inline-flex items-center gap-1.5">
                    <span className="inline-block h-2.5 w-2.5 rounded-[2px]" style={{ background: item.color }} aria-hidden />
                    {item.label}
                </span>
            ))}
        </div>
    );
}

function ChartCard({ title, subtitle, legend, table, children }: { title: string; subtitle?: string; legend?: React.ReactNode; table: React.ReactNode; children: React.ReactNode }) {
    const [showTable, setShowTable] = useState(false);
    return (
        <section className="viz-root rounded-md border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3 mb-3">
                <div>
                    <h2 className="m-0 text-[15px] font-semibold text-foreground">{title}</h2>
                    {subtitle && <p className="m-0 mt-0.5 text-[13px] text-muted-foreground">{subtitle}</p>}
                </div>
                <div className="flex items-center gap-4">
                    {legend}
                    <button
                        type="button"
                        onClick={() => setShowTable((v) => !v)}
                        className="inline-flex items-center gap-1.5 rounded-[4px] px-2 h-7 text-[12px] font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-pressed={showTable}
                    >
                        {showTable ? <LineChartIcon className="size-3.5" /> : <Table2 className="size-3.5" />}
                        {showTable ? 'Chart' : 'Table'}
                    </button>
                </div>
            </div>
            {showTable ? table : children}
        </section>
    );
}

function BurndownChart({ burndown }: { burndown: Burndown }) {
    const [ref, width] = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<number | null>(null);
    const { days, unit } = burndown;
    const innerW = width - MARGIN.left - MARGIN.right;
    const innerH = CHART_HEIGHT - MARGIN.top - MARGIN.bottom;
    const max = Math.max(burndown.total, ...days.map((d) => d.remaining ?? 0), 1);
    const ticks = niceTicks(max);
    const yMax = ticks[ticks.length - 1];
    const x = (i: number) => MARGIN.left + (days.length <= 1 ? 0 : (i / (days.length - 1)) * innerW);
    const y = (v: number) => MARGIN.top + innerH - (v / yMax) * innerH;
    const actual = days.map((d, i) => ({ ...d, i })).filter((d) => d.remaining !== null) as { date: string; ideal: number; remaining: number; i: number }[];
    const last = actual[actual.length - 1];
    const labelEvery = Math.max(1, Math.ceil(days.length / Math.max(2, Math.floor(innerW / 70))));
    const unitLabel = unit === 'points' ? 'pts' : 'issues';

    const onMove = (e: PointerEvent<SVGRectElement>) => {
        const box = e.currentTarget.getBoundingClientRect();
        const ratio = (e.clientX - box.left) / box.width;
        setHover(Math.max(0, Math.min(days.length - 1, Math.round(ratio * (days.length - 1)))));
    };
    const hovered = hover !== null ? days[hover] : null;

    return (
        <div ref={ref} className="viz-root relative">
            <svg width={width} height={CHART_HEIGHT} role="img" aria-label={`Burndown: ${last ? `${last.remaining} ${unitLabel} remaining of ${burndown.total}` : 'no data yet'}`}>
                {ticks.map((t) => (
                    <g key={t}>
                        <line x1={MARGIN.left} x2={width - MARGIN.right} y1={y(t)} y2={y(t)} stroke="var(--viz-grid)" strokeWidth={1} />
                        <text x={MARGIN.left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="fill-muted-foreground text-[11px]">{t}</text>
                    </g>
                ))}
                {days.map((d, i) => (i % labelEvery === 0 || i === days.length - 1) && (
                    <text key={d.date} x={x(i)} y={CHART_HEIGHT - 10} textAnchor={i === 0 ? 'start' : i === days.length - 1 ? 'end' : 'middle'} className="fill-muted-foreground text-[11px]">
                        {format(new Date(d.date + 'T12:00:00'), 'MMM d')}
                    </text>
                ))}

                {/* Guideline (ideal) */}
                <polyline
                    points={days.map((d, i) => `${x(i)},${y(d.ideal)}`).join(' ')}
                    fill="none" stroke="var(--viz-reference)" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round"
                />
                {/* Remaining */}
                {actual.length > 0 && (
                    <polyline
                        points={actual.map((d) => `${x(d.i)},${y(d.remaining)}`).join(' ')}
                        fill="none" stroke="var(--viz-series-1)" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round"
                    />
                )}
                {last && (
                    <>
                        <circle cx={x(last.i)} cy={y(last.remaining)} r={5} fill="var(--viz-series-1)" stroke="var(--viz-surface)" strokeWidth={2} />
                        <text
                            x={x(last.i) + (last.i === days.length - 1 ? -10 : 10)}
                            y={y(last.remaining) - 10}
                            textAnchor={last.i === days.length - 1 ? 'end' : 'start'}
                            className="fill-foreground text-[12px] font-semibold"
                        >
                            {last.remaining} {unitLabel} left
                        </text>
                    </>
                )}

                {/* Crosshair */}
                {hovered && hover !== null && (
                    <g pointerEvents="none">
                        <line x1={x(hover)} x2={x(hover)} y1={MARGIN.top} y2={MARGIN.top + innerH} stroke="hsl(var(--muted-foreground))" strokeWidth={1} />
                        <circle cx={x(hover)} cy={y(hovered.ideal)} r={4} fill="var(--viz-reference)" stroke="var(--viz-surface)" strokeWidth={2} />
                        {hovered.remaining !== null && <circle cx={x(hover)} cy={y(hovered.remaining)} r={5} fill="var(--viz-series-1)" stroke="var(--viz-surface)" strokeWidth={2} />}
                    </g>
                )}
                <rect
                    x={MARGIN.left} y={MARGIN.top} width={innerW} height={innerH} fill="transparent"
                    onPointerMove={onMove} onPointerLeave={() => setHover(null)}
                />
            </svg>
            {hovered && hover !== null && (
                <div
                    className="pointer-events-none absolute z-10 rounded-md border border-border bg-popover px-3 py-2 text-[12px] shadow-md"
                    style={{ left: Math.min(x(hover) + 12, width - 170), top: MARGIN.top }}
                >
                    <div className="font-medium text-foreground mb-1">{format(new Date(hovered.date + 'T12:00:00'), 'EEE, MMM d')}</div>
                    <div className="flex items-center gap-2 text-muted-foreground">
                        <span className="h-2 w-2 rounded-full" style={{ background: 'var(--viz-series-1)' }} />
                        <strong className="text-foreground">{hovered.remaining ?? '—'}</strong> remaining
                    </div>
                    <div className="flex items-center gap-2 text-muted-foreground">
                        <span className="h-2 w-2 rounded-full" style={{ background: 'var(--viz-reference)' }} />
                        <strong className="text-foreground">{Math.round(hovered.ideal * 10) / 10}</strong> guideline
                    </div>
                </div>
            )}
        </div>
    );
}

function VelocityChart({ rows, unit }: { rows: VelocityRow[]; unit: 'points' | 'issues' }) {
    const [ref, width] = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<{ index: number; series: 'committed' | 'completed' } | null>(null);
    const value = (row: VelocityRow, series: 'committed' | 'completed') =>
        unit === 'points' ? row[`${series}_points`] : row[`${series}_issues`];
    const innerW = width - MARGIN.left - MARGIN.right;
    const innerH = CHART_HEIGHT - MARGIN.top - MARGIN.bottom;
    const max = Math.max(1, ...rows.flatMap((r) => [value(r, 'committed'), value(r, 'completed')]));
    const ticks = niceTicks(max);
    const yMax = ticks[ticks.length - 1];
    const y = (v: number) => MARGIN.top + innerH - (v / yMax) * innerH;
    const base = MARGIN.top + innerH;
    const band = innerW / rows.length;
    const groupW = BAR_WIDTH * 2 + BAR_GAP;

    return (
        <div ref={ref} className="viz-root relative">
            <svg width={width} height={CHART_HEIGHT} role="img" aria-label="Velocity: committed vs. completed per sprint">
                {ticks.map((t) => (
                    <g key={t}>
                        <line x1={MARGIN.left} x2={width - MARGIN.right} y1={y(t)} y2={y(t)} stroke="var(--viz-grid)" strokeWidth={1} />
                        <text x={MARGIN.left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="fill-muted-foreground text-[11px]">{t}</text>
                    </g>
                ))}
                {rows.map((row, index) => {
                    const left = MARGIN.left + band * index + (band - groupW) / 2;
                    return (
                        <g key={row.id}>
                            {(['committed', 'completed'] as const).map((series, s) => {
                                const v = value(row, series);
                                const bx = left + s * (BAR_WIDTH + BAR_GAP);
                                const active = hover?.index === index && hover.series === series;
                                return (
                                    <g key={series}>
                                        <path
                                            d={columnPath(bx, y(v), BAR_WIDTH, base)}
                                            fill={series === 'completed' ? 'var(--viz-series-1)' : 'var(--viz-reference)'}
                                            opacity={hover && !active ? 0.55 : 1}
                                        />
                                        <text x={bx + BAR_WIDTH / 2} y={y(v) - 6} textAnchor="middle" className="fill-foreground text-[11px] font-medium">{v}</text>
                                        {/* Hit target taller than the mark */}
                                        <rect
                                            x={bx - 1} y={MARGIN.top} width={BAR_WIDTH + 2} height={innerH} fill="transparent"
                                            onPointerEnter={() => setHover({ index, series })} onPointerLeave={() => setHover(null)}
                                        />
                                    </g>
                                );
                            })}
                            <text x={left + groupW / 2} y={CHART_HEIGHT - 10} textAnchor="middle" className="fill-muted-foreground text-[11px]">
                                {row.name.length > 16 ? row.name.slice(0, 15) + '…' : row.name}
                            </text>
                        </g>
                    );
                })}
            </svg>
            {hover && (
                <div
                    className="pointer-events-none absolute z-10 rounded-md border border-border bg-popover px-3 py-2 text-[12px] shadow-md"
                    style={{ left: Math.min(MARGIN.left + band * hover.index + band / 2, width - 170), top: MARGIN.top }}
                >
                    <div className="font-medium text-foreground">{rows[hover.index].name}</div>
                    <div className="text-muted-foreground">
                        <strong className="text-foreground">{value(rows[hover.index], hover.series)}</strong> {unit} {hover.series}
                    </div>
                </div>
            )}
        </div>
    );
}

function StatTile({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
    return (
        <div className="rounded-md border border-border bg-card px-4 py-3">
            <div className="text-[12px] font-medium text-muted-foreground">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums text-foreground">{value}</div>
            {hint && <div className="text-[12px] text-muted-foreground">{hint}</div>}
        </div>
    );
}

export default function ReportsPage({ workspace, project, board, sprints, selectedSprint, burndown, velocity }: Props) {
    const params = [workspace.slug, project.slug, board.slug];
    const velocityUnit: 'points' | 'issues' = velocity.some((r) => r.committed_points > 0 || r.completed_points > 0) ? 'points' : 'issues';
    const lastActual = useMemo(() => [...(burndown?.days ?? [])].reverse().find((d) => d.remaining !== null), [burndown]);
    const unitLabel = burndown?.unit === 'points' ? 'pts' : 'issues';
    const avgVelocity = velocity.length
        ? Math.round(velocity.reduce((sum, r) => sum + (velocityUnit === 'points' ? r.completed_points : r.completed_issues), 0) / velocity.length)
        : null;

    return (
        <AppLayout>
            <Head title={`Reports - ${board.name}`} />
            <div className="flex flex-col h-full overflow-hidden bg-card">
                <BoardHeader workspace={workspace} project={project} board={board} activeTab="reports" />

                <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                    {sprints.length === 0 ? (
                        <p className="rounded-md border border-dashed border-border px-4 py-10 text-center text-sm text-muted-foreground">
                            Start a sprint to see its burndown and your team's velocity here.
                        </p>
                    ) : (
                        <>
                            {/* Filter row */}
                            <div className="flex flex-wrap items-center gap-3">
                                <Select
                                    value={selectedSprint ? String(selectedSprint.id) : undefined}
                                    onValueChange={(id) => router.get(route('boards.reports', params), { sprint: id }, { preserveScroll: true })}
                                >
                                    <SelectTrigger className="h-8 w-64 text-[13px]"><SelectValue placeholder="Choose a sprint" /></SelectTrigger>
                                    <SelectContent>
                                        {sprints.map((sprint) => (
                                            <SelectItem key={sprint.id} value={String(sprint.id)}>
                                                {sprint.name}{sprint.status === 'active' ? ' (active)' : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {selectedSprint?.status === 'active' && <StatusLozenge name="Active" category="in_progress" />}
                                {selectedSprint?.start_date && selectedSprint.end_date && (
                                    <span className="text-[13px] text-muted-foreground">
                                        {format(new Date(selectedSprint.start_date), 'MMM d')} – {format(new Date(selectedSprint.end_date), 'MMM d, yyyy')}
                                    </span>
                                )}
                            </div>

                            {burndown && (
                                <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                                    <StatTile label="Scope" value={burndown.total} hint={unitLabel} />
                                    <StatTile label="Done" value={burndown.total - (lastActual?.remaining ?? burndown.total)} hint={unitLabel} />
                                    <StatTile label="Remaining" value={lastActual?.remaining ?? burndown.total} hint={unitLabel} />
                                    <StatTile label="Avg. velocity" value={avgVelocity ?? '—'} hint={avgVelocity !== null ? `${velocityUnit} per sprint · last ${velocity.length}` : 'after the first completed sprint'} />
                                </div>
                            )}

                            {burndown && (
                                <ChartCard
                                    title="Burndown"
                                    subtitle={`${selectedSprint?.name ?? ''} · remaining ${burndown.unit === 'points' ? 'story points' : 'issues (no story points in this sprint)'}`}
                                    legend={<Legend items={[{ label: 'Remaining', color: 'var(--viz-series-1)' }, { label: 'Guideline', color: 'var(--viz-reference)' }]} />}
                                    table={
                                        <table className="w-full text-[13px]">
                                            <thead className="text-left text-muted-foreground"><tr><th className="py-1.5 font-medium">Day</th><th className="font-medium">Remaining</th><th className="font-medium">Guideline</th></tr></thead>
                                            <tbody className="divide-y divide-border">
                                                {burndown.days.map((d) => (
                                                    <tr key={d.date}><td className="py-1.5">{format(new Date(d.date + 'T12:00:00'), 'EEE, MMM d')}</td><td className="tabular-nums">{d.remaining ?? '—'}</td><td className="tabular-nums">{Math.round(d.ideal * 10) / 10}</td></tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    }
                                >
                                    <BurndownChart burndown={burndown} />
                                </ChartCard>
                            )}

                            <ChartCard
                                title="Velocity"
                                subtitle={velocity.length ? `Committed vs. completed ${velocityUnit}, last ${velocity.length} sprint${velocity.length === 1 ? '' : 's'}` : 'Shows up after the first completed sprint'}
                                legend={velocity.length ? <Legend items={[{ label: 'Committed', color: 'var(--viz-reference)' }, { label: 'Completed', color: 'var(--viz-series-1)' }]} /> : undefined}
                                table={
                                    <table className="w-full text-[13px]">
                                        <thead className="text-left text-muted-foreground"><tr><th className="py-1.5 font-medium">Sprint</th><th className="font-medium">Committed</th><th className="font-medium">Completed</th></tr></thead>
                                        <tbody className="divide-y divide-border">
                                            {velocity.map((r) => (
                                                <tr key={r.id}>
                                                    <td className="py-1.5">{r.name}</td>
                                                    <td className="tabular-nums">{velocityUnit === 'points' ? r.committed_points : r.committed_issues}</td>
                                                    <td className="tabular-nums">{velocityUnit === 'points' ? r.completed_points : r.completed_issues}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                }
                            >
                                {velocity.length ? (
                                    <VelocityChart rows={velocity} unit={velocityUnit} />
                                ) : (
                                    <p className={cn('py-10 text-center text-sm text-muted-foreground')}>Complete a sprint to start tracking velocity.</p>
                                )}
                            </ChartCard>
                        </>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
