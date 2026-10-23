import { useEffect, useState } from 'react';
import { Eye, Loader2, MousePointerClick, Percent, Send } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Segmented } from './BuilderControls';

interface Analytics {
    days: number;
    views: number;
    starts: number;
    submits: number;
    conversion: number | null;
    leads: number;
    series: { date: string; views: number; submits: number }[];
    steps: { index: number; title: string; conditional: boolean; sessions: number }[];
}

export default function AnalyticsPanel({ routeParams }: { routeParams: [string, string, string] }) {
    const [days, setDays] = useState<'7' | '30' | '90'>('30');
    const [data, setData] = useState<Analytics | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        let cancelled = false;
        setError(false);
        window.axios
            .get(route('lead-forms.analytics', routeParams), { params: { days } })
            .then(({ data }) => !cancelled && setData(data))
            .catch(() => !cancelled && setError(true));
        return () => {
            cancelled = true;
        };
    }, [days, routeParams]);

    const max = Math.max(1, ...(data?.series.map((d) => d.views) ?? [1]));
    const firstStep = data?.steps[0]?.sessions || data?.starts || 0;

    return (
        <div className="mx-auto max-w-5xl space-y-6 p-6">
            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-base font-semibold">Analytics</h2>
                    <p className="text-sm text-muted-foreground">Unique visits — no cookies, no personal data.</p>
                </div>
                <div className="w-56">
                    <Segmented value={days} onChange={setDays} options={[{ value: '7', label: '7 days' }, { value: '30', label: '30 days' }, { value: '90', label: '90 days' }]} />
                </div>
            </div>

            {error && <p className="text-sm text-destructive">Could not load the analytics.</p>}
            {!data && !error && (
                <div className="flex justify-center py-16">
                    <Loader2 className="size-6 animate-spin text-muted-foreground" />
                </div>
            )}

            {data && (
                <>
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <Kpi icon={Eye} label="Views" value={data.views} />
                        <Kpi icon={MousePointerClick} label="Started" value={data.starts} hint={data.views ? `${Math.round((data.starts / data.views) * 100)}% of views` : undefined} />
                        <Kpi icon={Send} label="Submitted" value={data.submits} hint={`${data.leads} ${data.leads === 1 ? 'lead' : 'leads'} incl. webhooks`} />
                        <Kpi icon={Percent} label="Conversion" value={data.conversion === null ? '—' : `${data.conversion}%`} hint="submitted ÷ views" />
                    </div>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-4 text-sm">
                                Per day
                                <span className="flex items-center gap-1.5 text-xs font-normal text-muted-foreground">
                                    <span className="size-2 rounded-sm bg-primary/30" /> views
                                    <span className="ml-2 size-2 rounded-sm bg-primary" /> submitted
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex h-44 items-end gap-[3px]">
                                {data.series.map((d) => (
                                    <div key={d.date} className="group relative flex h-full flex-1 items-end" title={`${d.date}: ${d.views} views, ${d.submits} submitted`}>
                                        <div className="relative w-full rounded-t bg-primary/25 transition group-hover:bg-primary/40" style={{ height: `${(d.views / max) * 100}%`, minHeight: d.views ? 2 : 0 }}>
                                            <div className="absolute inset-x-0 bottom-0 rounded-t bg-primary" style={{ height: d.views ? `${(d.submits / d.views) * 100}%` : 0 }} />
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-1.5 flex justify-between text-[10px] text-muted-foreground">
                                <span>{data.series[0]?.date}</span>
                                <span>{data.series[data.series.length - 1]?.date}</span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">How far visitors get</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2.5">
                            {data.steps.map((s, i) => {
                                const pct = firstStep ? Math.round((s.sessions / firstStep) * 100) : 0;
                                // drop-off against the last step everyone sees; conditional steps are skipped by design
                                const prev = data.steps.slice(0, i).reverse().find((p) => !p.conditional)?.sessions ?? null;
                                const drop = !s.conditional && prev ? Math.round(((prev - s.sessions) / prev) * 100) : null;
                                return (
                                    <div key={s.index} className="flex items-center gap-3 text-sm">
                                        <span className="w-6 text-right text-xs text-muted-foreground">{i + 1}</span>
                                        <span className="w-48 truncate" title={s.conditional ? 'Shown only for some answers' : undefined}>
                                            {s.title}
                                            {s.conditional && <span className="ml-1.5 text-[10px] text-muted-foreground">if…</span>}
                                        </span>
                                        <div className="h-6 flex-1 overflow-hidden rounded bg-muted">
                                            <div className="h-full rounded bg-primary/70" style={{ width: `${pct}%` }} />
                                        </div>
                                        <span className="w-16 text-right tabular-nums">{s.sessions}</span>
                                        <span className={cn('w-20 text-right text-xs tabular-nums', drop && drop > 30 ? 'text-destructive' : 'text-muted-foreground')}>{drop !== null && drop > 0 ? `−${drop}%` : ''}</span>
                                    </div>
                                );
                            })}
                            <div className="flex items-center gap-3 border-t pt-2.5 text-sm font-medium">
                                <span className="w-6" />
                                <span className="w-48">Submitted</span>
                                <div className="h-6 flex-1 overflow-hidden rounded bg-muted">
                                    <div className="h-full rounded bg-emerald-500" style={{ width: `${firstStep ? Math.round((data.submits / firstStep) * 100) : 0}%` }} />
                                </div>
                                <span className="w-16 text-right tabular-nums">{data.submits}</span>
                                <span className="w-20" />
                            </div>
                        </CardContent>
                    </Card>
                </>
            )}
        </div>
    );
}

function Kpi({ icon: Icon, label, value, hint }: { icon: typeof Eye; label: string; value: number | string; hint?: string }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Icon className="size-3.5" />
                    {label}
                </div>
                <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
                {hint && <p className="mt-0.5 text-[11px] text-muted-foreground">{hint}</p>}
            </CardContent>
        </Card>
    );
}
