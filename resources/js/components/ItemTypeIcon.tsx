import { cn } from '@/lib/utils';
import { Bug, Layers, Square, CheckSquare, BookOpen, Zap, List } from 'lucide-react';

export interface ItemTypeIconType {
    icon?: string | null;
    icon_display?: string | null;
    slug?: string;
    color?: string | null;
}

interface ItemTypeIconProps {
    /** Full item type (icon, icon_display, slug) for custom emoji/image; falls back to slug. */
    type?: ItemTypeIconType | null;
    /** Fallback slug when type is not provided (e.g. for Select options). */
    slug?: string;
    className?: string;
}

/** Map DB icon names (from seeder / project settings) to Lucide icons with optional color. */
function iconNameToLucide(iconName: string | undefined, baseClass: string) {
    if (!iconName) return null;
    const name = iconName.toLowerCase().trim();
    const style = (color?: string) => (color ? cn(baseClass, color) : baseClass);
    switch (name) {
        case 'book-open':
            return <BookOpen className={style('text-green-600')} />;
        case 'check-square':
            return <CheckSquare className={style('text-primary')} />;
        case 'bug':
            return <Bug className={style('text-red-500')} />;
        case 'zap':
            return <Zap className={style('text-violet-500')} />;
        case 'list':
            return <List className={style('text-primary/60')} />;
        default:
            return null;
    }
}

function slugToLucideIcon(slug: string | undefined, baseClass: string) {
    switch (slug) {
        case 'bug':
            return <Bug className={cn(baseClass, 'text-red-500')} />;
        case 'story':
            return <BookOpen className={cn(baseClass, 'text-green-600')} />;
        case 'epic':
            return <Layers className={cn(baseClass, 'text-muted-foreground')} />;
        case 'subtask':
            return <Square className={cn(baseClass, 'text-primary/60')} />;
        case 'task':
        default:
            return <CheckSquare className={cn(baseClass, 'text-primary')} />;
    }
}

/** True if string looks like an emoji (short, no hyphen/slug). */
function looksLikeEmoji(s: string): boolean {
    if (!s || s.length > 4) return false;
    if (s.includes('-') || s.includes('_')) return false;
    return true;
}

/**
 * Renders item type icon: custom image/emoji from type.icon_display or type.icon, Lucide by icon name, or fallback by slug.
 */
export function ItemTypeIcon({ type, slug, className }: ItemTypeIconProps) {
    const baseClass = cn('size-4 shrink-0', className);
    const effectiveSlug = type?.slug ?? slug;

    if (type?.icon_display && (type.icon_display.startsWith('http') || type.icon_display.startsWith('/'))) {
        return (
            <img
                src={type.icon_display}
                alt=""
                className={cn('rounded object-cover', baseClass)}
            />
        );
    }

    const byIconName = iconNameToLucide(type?.icon ?? undefined, baseClass);
    if (byIconName) return byIconName;

    if (type?.icon && looksLikeEmoji(type.icon)) {
        return <span className={cn('inline-flex items-center justify-center text-base leading-none', baseClass)}>{type.icon}</span>;
    }

    return slugToLucideIcon(effectiveSlug, baseClass);
}
