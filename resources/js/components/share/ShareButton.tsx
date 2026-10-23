import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import axios from 'axios';
import { formatDistanceToNow } from 'date-fns';
import { Check, Copy, Globe, Link2, Loader2, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface ShareLinkRow {
    id: number;
    url: string;
    created_by: string | null;
    created_at: string;
    expires_at: string | null;
    view_count: number;
    last_viewed_at: string | null;
}

/**
 * "Share" for a doc or issue: create a read-only link (optional expiry), copy it, see views,
 * revoke it. Only rendered for people who can edit the item.
 */
export default function ShareButton({
    type,
    id,
    label,
    size = 'sm',
    className,
}: {
    type: 'page' | 'item';
    id: string;
    label: string;
    size?: 'sm' | 'default';
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [links, setLinks] = useState<ShareLinkRow[] | null>(null);
    const [expiry, setExpiry] = useState('never');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copiedId, setCopiedId] = useState<number | null>(null);
    /** False when the user can't share this (no edit rights): the button isn't shown. */
    const [allowed, setAllowed] = useState(true);

    const load = useCallback(() => {
        axios
            .get(route('share-links.index'), { params: { type, id } })
            .then(({ data }) => setLinks(data.links))
            .catch(() => setAllowed(false));
    }, [type, id]);

    useEffect(load, [load]);

    const create = async () => {
        setBusy(true);
        setError(null);
        try {
            const { data } = await axios.post(route('share-links.store'), { type, id, expires_in_days: expiry === 'never' ? null : Number(expiry) });
            setLinks(data.links);
        } catch (e: any) {
            setError(e.response?.data?.message ?? 'Couldn’t create the link.');
        } finally {
            setBusy(false);
        }
    };

    const revoke = async (linkId: number) => {
        const { data } = await axios.delete(route('share-links.destroy', linkId));
        setLinks(data.links);
    };

    const copy = async (link: ShareLinkRow) => {
        await navigator.clipboard.writeText(link.url);
        setCopiedId(link.id);
        setTimeout(() => setCopiedId(null), 1500);
    };

    const shared = (links?.length ?? 0) > 0;

    if (!allowed) return null;

    return (
        <>
            <Button variant={shared ? 'secondary' : 'ghost'} size={size} className={className} onClick={() => setOpen(true)}>
                {shared ? <Globe className="size-3.5 text-primary" /> : <Link2 className="size-3.5" />}
                {shared ? 'Shared' : 'Share'}
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Share {label}</DialogTitle>
                        <DialogDescription>
                            Anyone with the link can read this {type === 'page' ? 'doc' : 'issue'} — nothing else: no comments, people or other content. Revoke it any time.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={expiry} onValueChange={setExpiry}>
                            <SelectTrigger className="w-44">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="never">Never expires</SelectItem>
                                <SelectItem value="1">Expires in 1 day</SelectItem>
                                <SelectItem value="7">Expires in 7 days</SelectItem>
                                <SelectItem value="30">Expires in 30 days</SelectItem>
                                <SelectItem value="90">Expires in 90 days</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button onClick={create} disabled={busy}>
                            {busy ? <Loader2 className="size-4 animate-spin" /> : <Link2 className="size-4" />} Create link
                        </Button>
                    </div>
                    {error && <p className="text-sm text-destructive">{error}</p>}

                    <div className="space-y-2">
                        {links === null && <Loader2 className="size-4 animate-spin text-muted-foreground" />}
                        {links?.length === 0 && <p className="text-sm text-muted-foreground">Not shared yet.</p>}
                        {links?.map((link) => (
                            <div key={link.id} className="rounded-lg border p-3 space-y-2">
                                <div className="flex items-center gap-2">
                                    <code className="flex-1 truncate text-xs">{link.url}</code>
                                    <Button size="sm" variant="outline" onClick={() => copy(link)}>
                                        {copiedId === link.id ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
                                        {copiedId === link.id ? 'Copied' : 'Copy'}
                                    </Button>
                                    <Button size="sm" variant="ghost" className="text-destructive hover:text-destructive" onClick={() => revoke(link.id)} aria-label="Revoke link">
                                        <Trash2 className="size-3.5" /> Revoke
                                    </Button>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {link.created_by ? `${link.created_by} · ` : ''}created {formatDistanceToNow(new Date(link.created_at), { addSuffix: true })} ·{' '}
                                    {link.expires_at ? `expires ${formatDistanceToNow(new Date(link.expires_at), { addSuffix: true })}` : 'no expiry'} · {link.view_count} view
                                    {link.view_count === 1 ? '' : 's'}
                                </p>
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
