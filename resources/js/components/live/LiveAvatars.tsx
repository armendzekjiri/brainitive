/**
 * "Who's here" (BRAI-151): the colleagues looking at the same issue / board / plan right now, as a
 * compact avatar stack (ring in their live colour). You are left out. Hover / focus shows the names,
 * a tap opens the list (phones). Renders nothing while live is off or nobody else is here.
 */
import type { LiveMember, PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useLivePresence } from '@/lib/live';
import { cn } from '@/lib/utils';

const MAX_SHOWN = 4;

/** Only plain http(s) / same-origin image URLs (presence data is relayed by the realtime server). */
const safeAvatar = (url: string | null) => (url && /^(https?:\/\/|\/(?!\/))/i.test(url) ? url : undefined);

const initials = (name: string) => name.trim().split(/\s+/).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('') || '?';

function MemberAvatar({ member, className }: { member: LiveMember; className?: string }) {
    return (
        <Avatar className={cn('size-7 border-2 border-background', className)} style={{ boxShadow: `0 0 0 2px ${member.color}` }}>
            <AvatarImage src={safeAvatar(member.avatar)} alt="" />
            <AvatarFallback className="text-[10px] font-semibold text-white" style={{ backgroundColor: member.color }}>
                {initials(member.name)}
            </AvatarFallback>
        </Avatar>
    );
}

/** Presence of `channel` (e.g. `item.12`) — announces this tab, shows everyone else. */
export function LiveAvatars({ channel, label = 'Also here', className }: { channel: string | null; label?: string; className?: string }) {
    const me = usePage<PageProps>().props.auth?.user?.id;
    const others = useLivePresence(channel).filter((member) => member.id !== me);
    return <LiveAvatarStack members={others} label={label} className={className} />;
}

export function LiveAvatarStack({ members, label = 'Also here', className }: { members: LiveMember[]; label?: string; className?: string }) {
    if (members.length === 0) return null;
    const names = members.map((member) => member.name).join(', ');
    const shown = members.slice(0, MAX_SHOWN);
    const hidden = members.length - shown.length;

    return (
        <Popover>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn('flex shrink-0 items-center -space-x-1.5 rounded-full px-1 py-0.5 outline-none hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring', className)}
                    aria-label={`${label}: ${names}`}
                    title={`${label}: ${names}`}
                    data-testid="live-avatars"
                >
                    {shown.map((member) => <MemberAvatar key={member.id} member={member} />)}
                    {hidden > 0 && (
                        <span className="grid size-7 place-items-center rounded-full border-2 border-background bg-muted text-[10px] font-semibold text-muted-foreground">
                            +{hidden}
                        </span>
                    )}
                </button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-60 p-2">
                <p className="px-1 pb-1.5 text-xs font-medium text-muted-foreground">{label}</p>
                <ul className="space-y-1">
                    {members.map((member) => (
                        <li key={member.id} className="flex items-center gap-2 rounded-md px-1 py-1 text-sm">
                            <MemberAvatar member={member} className="size-6" />
                            <span className="truncate">{member.name}</span>
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}
