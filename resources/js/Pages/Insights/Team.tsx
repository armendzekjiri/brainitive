import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronRight } from 'lucide-react';
import InsightsLayout from '@/Layouts/InsightsLayout';
import { Empty, formatHours, fullTime, Section, timeAgo } from '@/components/insights/InsightsBits';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';
import type { InsightsPageProps, InsightsTeamMember } from '@/types';

type SortKey = 'name' | 'completed' | 'open' | 'overdue' | 'hours' | 'comments' | 'last_active_at';

const COLUMNS: { key: SortKey; label: string; numeric?: boolean }[] = [
    { key: 'name', label: 'Member' },
    { key: 'completed', label: 'Completed', numeric: true },
    { key: 'open', label: 'Open assigned', numeric: true },
    { key: 'overdue', label: 'Overdue', numeric: true },
    { key: 'hours', label: 'Hours', numeric: true },
    { key: 'comments', label: 'Comments', numeric: true },
    { key: 'last_active_at', label: 'Last active' },
];

/** Insights → Team (BRAI-105). Definitions: TeamInsights (PHP). */
export default function InsightsTeamPage(props: InsightsPageProps<{ members: InsightsTeamMember[] }>) {
    const { members } = props.insights;
    const [sort, setSort] = useState<{ key: SortKey; desc: boolean }>({ key: 'completed', desc: true });

    const rows = useMemo(() => {
        const value = (m: InsightsTeamMember) => (sort.key === 'name' ? m.name.toLowerCase() : sort.key === 'last_active_at' ? (m.last_active_at ?? '') : m[sort.key]);
        return [...members].sort((a, b) => {
            const [x, y] = [value(a), value(b)];
            const order = x < y ? -1 : x > y ? 1 : a.name.localeCompare(b.name);
            return sort.desc ? -order : order;
        });
    }, [members, sort]);

    return (
        <InsightsLayout tab="team" props={props}>
            <Section
                title="Team"
                description="Completed, hours and comments count in the period; open, overdue and last active are now. Last active = the later of the last sign-in and the latest issue activity in this workspace."
            >
                {members.length === 0 ? (
                    <Empty>No members.</Empty>
                ) : (
                    <div className="-mx-4 overflow-x-auto px-4">
                        <table className="w-full min-w-[720px] text-sm" aria-label="Team">
                            <thead>
                                <tr className="text-left text-xs text-muted-foreground">
                                    {COLUMNS.map((c) => (
                                        <th key={c.key} className={cn('pb-2 font-medium', c.numeric && 'text-right')} aria-sort={sort.key === c.key ? (sort.desc ? 'descending' : 'ascending') : 'none'}>
                                            <button
                                                type="button"
                                                className="inline-flex items-center gap-1 hover:text-foreground"
                                                onClick={() => setSort((s) => ({ key: c.key, desc: s.key === c.key ? !s.desc : c.key !== 'name' }))}
                                            >
                                                {c.label}
                                                {sort.key === c.key && (sort.desc ? <ArrowDown className="size-3" /> : <ArrowUp className="size-3" />)}
                                            </button>
                                        </th>
                                    ))}
                                    <th className="pb-2"><span className="sr-only">Profile</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((m) => (
                                    <tr key={m.id} className="border-t">
                                        <td className="py-2 pr-3">
                                            <div className="flex items-center gap-2">
                                                <Avatar className="size-7">
                                                    {m.avatar && <AvatarImage src={m.avatar} alt="" />}
                                                    <AvatarFallback className="text-[11px]">{m.name.slice(0, 2).toUpperCase()}</AvatarFallback>
                                                </Avatar>
                                                <div className="min-w-0">
                                                    <div className="truncate font-medium">{m.name}</div>
                                                    <div className="text-xs capitalize text-muted-foreground">{m.role}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="py-2 text-right tabular-nums">{m.completed}</td>
                                        <td className="py-2 text-right tabular-nums">{m.open}</td>
                                        <td className={cn('py-2 text-right tabular-nums', m.overdue > 0 && 'font-medium text-amber-700 dark:text-amber-400')}>{m.overdue}</td>
                                        <td className="py-2 text-right tabular-nums">{formatHours(m.hours)}</td>
                                        <td className="py-2 text-right tabular-nums">{m.comments}</td>
                                        <td className="py-2 pl-3 text-muted-foreground" title={fullTime(m.last_active_at)}>{timeAgo(m.last_active_at)}</td>
                                        <td className="py-2 text-right">
                                            <Link href={m.profile_url} className="inline-flex items-center text-xs text-primary hover:underline" aria-label={`Profile of ${m.name}`}>
                                                Profile <ChevronRight className="size-3.5" />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Section>
        </InsightsLayout>
    );
}
