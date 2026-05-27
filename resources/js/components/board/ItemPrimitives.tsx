import { cn } from '@/lib/utils';
import { ChevronsUp, ChevronUp, Equal, ChevronDown, ChevronsDown } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { ItemTypeIcon, ItemTypeIconType } from '@/components/ItemTypeIcon';
import type { Label as LabelType, User } from '@/types';

// ── Priority ────────────────────────────────────────────────────────────────
export interface PriorityMeta {
    label: string;
    color: string;
    Icon: React.ComponentType<{ className?: string; style?: React.CSSProperties }>;
}

const PRIORITY_META: Record<string, PriorityMeta> = {
    highest: { label: 'Highest', color: '#E11D48', Icon: ChevronsUp },
    high: { label: 'High', color: '#F97316', Icon: ChevronUp },
    medium: { label: 'Medium', color: '#F59E0B', Icon: Equal },
    low: { label: 'Low', color: '#3B82F6', Icon: ChevronDown },
    lowest: { label: 'Lowest', color: '#94A3B8', Icon: ChevronsDown },
};

const PRIORITY_NONE: PriorityMeta = { label: 'None', color: '#CBD5E1', Icon: Equal };

export function priorityMeta(slug?: string | null): PriorityMeta {
    return PRIORITY_META[slug ?? ''] ?? PRIORITY_NONE;
}

/** Unified priority indicator used by the board card, the list view and the detail popup. */
export function PriorityIndicator({
    slug,
    showLabel = false,
    className,
}: {
    slug?: string | null;
    showLabel?: boolean;
    className?: string;
}) {
    const { label, color, Icon } = priorityMeta(slug);
    return (
        <span className={cn('inline-flex items-center gap-1.5', className)} title={`Priority: ${label}`}>
            <Icon className="size-4 shrink-0" style={{ color }} />
            {showLabel && <span className="text-sm">{label}</span>}
        </span>
    );
}

// ── Label chip ───────────────────────────────────────────────────────────────
/** Single soft pill used everywhere a label is rendered. */
export function LabelChip({ label, className }: { label: Pick<LabelType, 'name' | 'color'>; className?: string }) {
    const color = label.color ?? '#64748B';
    return (
        <span
            className={cn(
                'inline-flex items-center h-[18px] px-2 rounded-full text-[11px] font-medium leading-none whitespace-nowrap',
                className,
            )}
            style={{ backgroundColor: `${color}1A`, color, border: `1px solid ${color}33` }}
        >
            {label.name}
        </span>
    );
}

// ── Type badge ────────────────────────────────────────────────────────────────
export type TypeBadgeType = ItemTypeIconType & { name?: string };

/** Item-type icon in a soft tinted tile, optionally followed by the type name. */
export function TypeBadge({
    type,
    showLabel = true,
    className,
}: {
    type?: TypeBadgeType | null;
    showLabel?: boolean;
    className?: string;
}) {
    const tint = type?.color ?? '#64748B';
    return (
        <span className={cn('inline-flex items-center gap-1.5 min-w-0', className)}>
            <span
                className="inline-flex items-center justify-center size-[18px] rounded-md shrink-0"
                style={{ backgroundColor: `${tint}1A` }}
            >
                <ItemTypeIcon type={type} className="size-3.5" />
            </span>
            {showLabel && type?.name && (
                <span className="text-[11px] font-medium text-muted-foreground truncate">{type.name}</span>
            )}
        </span>
    );
}

// ── Assignee avatar ──────────────────────────────────────────────────────────
export function AssigneeAvatar({
    user,
    size = 18,
    className,
}: {
    user?: Pick<User, 'name' | 'avatar_display'> | null;
    size?: number;
    className?: string;
}) {
    if (!user) {
        return (
            <span
                className={cn('rounded-full border border-dashed border-muted-foreground/40 shrink-0', className)}
                style={{ width: size, height: size }}
                aria-hidden
            />
        );
    }
    return (
        <Avatar className={cn('ring-2 ring-card shrink-0', className)} style={{ width: size, height: size }}>
            {user.avatar_display && <AvatarImage src={user.avatar_display} alt={user.name} />}
            <AvatarFallback className="bg-brand-slate text-white font-bold" style={{ fontSize: Math.round(size * 0.42) }}>
                {user.name.charAt(0).toUpperCase()}
            </AvatarFallback>
        </Avatar>
    );
}

/** Accent color for an item: priority first, then item type, else null (no accent). */
export function itemAccentColor(item: {
    priority?: { slug?: string } | null;
    item_type?: { color?: string | null } | null;
}): string | null {
    if (item.priority?.slug) return priorityMeta(item.priority.slug).color;
    return item.item_type?.color ?? null;
}
