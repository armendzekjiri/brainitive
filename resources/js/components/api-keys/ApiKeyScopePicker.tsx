import { useMemo, useState } from 'react';
import { Search } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import type { ApiKeyOptions, ApiKeyScopeType } from '@/types';

interface ScopeItem {
    id: number;
    name: string;
    /** Its workspace blocks API keys. */
    blocked: boolean;
}

interface ScopeGroup {
    label: string | null;
    items: ScopeItem[];
}

/** Workspaces flat; projects under their workspace; boards under "Workspace · Project". */
function buildGroups(type: Exclude<ApiKeyScopeType, 'all'>, options: ApiKeyOptions): ScopeGroup[] {
    const workspaces = new Map(options.workspaces.map((w) => [w.id, w]));
    if (type === 'workspaces') {
        return [{ label: null, items: options.workspaces.map((w) => ({ id: w.id, name: w.name, blocked: !w.api_keys_enabled })) }];
    }

    const projects = new Map(options.projects.map((p) => [p.id, p]));
    const groups = new Map<string, ScopeGroup>();
    const add = (label: string, item: ScopeItem) => {
        const group = groups.get(label) ?? { label, items: [] };
        group.items.push(item);
        groups.set(label, group);
    };

    if (type === 'projects') {
        for (const p of options.projects) {
            const ws = workspaces.get(p.workspace_id);
            add(ws?.name ?? 'Other', { id: p.id, name: p.name, blocked: ws ? !ws.api_keys_enabled : false });
        }
    } else {
        for (const b of options.boards) {
            const project = projects.get(b.project_id);
            const ws = project ? workspaces.get(project.workspace_id) : undefined;
            add([ws?.name, project?.name].filter(Boolean).join(' · ') || 'Other', { id: b.id, name: b.name, blocked: ws ? !ws.api_keys_enabled : false });
        }
    }

    return [...groups.values()];
}

const EMPTY: Record<Exclude<ApiKeyScopeType, 'all'>, string> = {
    workspaces: 'You are not a member of any workspace.',
    projects: 'You have no projects yet.',
    boards: 'You have no boards yet.',
};

/** Checkbox list of the workspaces / projects / boards a new API key may reach (filterable when long). */
export function ApiKeyScopePicker({
    type,
    options,
    value,
    onChange,
}: {
    type: Exclude<ApiKeyScopeType, 'all'>;
    options: ApiKeyOptions;
    value: number[];
    onChange: (ids: number[]) => void;
}) {
    const [query, setQuery] = useState('');
    const groups = useMemo(() => buildGroups(type, options), [type, options]);
    const total = groups.reduce((n, g) => n + g.items.length, 0);

    const q = query.trim().toLowerCase();
    const visible = q
        ? groups
              .map((g) => ({ ...g, items: g.items.filter((i) => i.name.toLowerCase().includes(q) || (g.label ?? '').toLowerCase().includes(q)) }))
              .filter((g) => g.items.length > 0)
        : groups;

    const toggle = (id: number, checked: boolean) => {
        onChange(checked ? [...value, id] : value.filter((v) => v !== id));
    };

    if (total === 0) {
        return <p className="text-sm text-muted-foreground">{EMPTY[type]}</p>;
    }

    return (
        <div className="space-y-2">
            {total > 8 && (
                <div className="relative">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={`Filter ${type}…`}
                        aria-label={`Filter ${type}`}
                        className="h-8 pl-8"
                    />
                </div>
            )}
            <div className="max-h-60 overflow-y-auto rounded-md border p-2">
                {visible.length === 0 && <p className="px-1 py-2 text-sm text-muted-foreground">Nothing matches “{query}”.</p>}
                {visible.map((group) => (
                    <div key={group.label ?? '_'} className="py-1">
                        {group.label && <p className="truncate px-1 pb-1 text-xs font-medium text-muted-foreground">{group.label}</p>}
                        <ul className="space-y-0.5">
                            {group.items.map((item) => (
                                <li key={item.id}>
                                    <label
                                        className={`flex min-w-0 items-center gap-2 rounded px-1 py-1 text-sm ${item.blocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-muted'}`}
                                    >
                                        <input
                                            type="checkbox"
                                            className="size-4 shrink-0 accent-primary"
                                            aria-label={item.name}
                                            checked={value.includes(item.id)}
                                            disabled={item.blocked}
                                            onChange={(e) => toggle(item.id, e.target.checked)}
                                        />
                                        <span className="min-w-0 flex-1 truncate">{item.name}</span>
                                        {item.blocked && (
                                            <Badge variant="outline" className="shrink-0 text-[10px] font-medium text-muted-foreground">
                                                API keys off
                                            </Badge>
                                        )}
                                    </label>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            {value.length > 0 && <p className="text-xs text-muted-foreground">{value.length} selected</p>}
        </div>
    );
}
