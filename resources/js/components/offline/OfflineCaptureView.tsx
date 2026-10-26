import { useState, type FormEvent } from 'react';
import { Lightbulb } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { captureOffline } from '@/lib/offline/actions';
import { useOfflineState } from '@/lib/offline/store';
import NotSyncedBadge from './NotSyncedBadge';

/** Quick capture from this device (BRAI-82): lands in the private captures inbox once synced. */
export default function OfflineCaptureView() {
    const { outbox } = useOfflineState();
    const waiting = outbox.filter((m) => m.type === 'capture.create');
    const [text, setText] = useState('');
    const [url, setUrl] = useState('');

    const save = async (e: FormEvent) => {
        e.preventDefault();
        if (!text.trim()) return;
        if (!(await captureOffline({ text: text.trim(), url: url.trim() || null }))) return;
        setText('');
        setUrl('');
        toast.success('Saved on this device — it syncs when you’re back online');
    };

    return (
        <div className="mx-auto max-w-2xl px-4 py-6 sm:px-6">
            <h1 className="flex items-center gap-2.5 text-2xl font-bold tracking-tight">
                <Lightbulb className="size-6 text-amber-500" /> Quick capture
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">Saved to your private captures. Organize them into cards, tasks or docs later.</p>

            <form onSubmit={save} className="mt-6 space-y-4 rounded-2xl border bg-card p-4">
                <div className="space-y-1.5">
                    <Label htmlFor="offline-capture-text">Idea</Label>
                    <Textarea id="offline-capture-text" rows={5} value={text} onChange={(e) => setText(e.target.value)} placeholder="What's on your mind?" maxLength={20000} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="offline-capture-url">Link (optional)</Label>
                    <Input id="offline-capture-url" type="url" inputMode="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://…" />
                </div>
                <Button type="submit" disabled={!text.trim()}>Save capture</Button>
            </form>

            {waiting.length > 0 && (
                <section className="mt-8" aria-label="Captures waiting to sync">
                    <h2 className="text-sm font-semibold">Waiting to sync</h2>
                    <ul className="mt-2 space-y-2">
                        {waiting.map((m) => (
                            <li key={m.id} className="flex items-start justify-between gap-3 rounded-xl border bg-card p-3 text-sm">
                                <span className="min-w-0 whitespace-pre-wrap break-words">{String(m.data.text ?? '')}</span>
                                <NotSyncedBadge />
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}
