import { useEffect, useRef } from 'react';
import { Check, Copy, KeyRound, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { ApiKeyRow } from '@/types';

async function copy(text: string, what: string) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${what} copied`);
    } catch {
        toast.error('Could not copy. Select the text and copy it by hand.');
    }
}

/**
 * The secret of a just-created (or regenerated) API key. It exists only in this one response:
 * never stored anywhere on the client. "Done" hides it for good.
 */
export function ApiKeySecret({ apiKey, secret, apiBaseUrl, onDone }: { apiKey: ApiKeyRow; secret: string; apiBaseUrl: string; onDone: () => void }) {
    const ref = useRef<HTMLDivElement>(null);
    // the key never appears in the command: not in shell history, and not in curl's arguments (process lists) — the
    // header comes on stdin through a bash here-string (curl -H @-, curl 7.55+)
    const curl = `curl -H @- ${apiBaseUrl}/me <<<"Authorization: Bearer $BRAINITIVE_API_KEY"`;

    useEffect(() => {
        ref.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [secret]);

    return (
        <div ref={ref} className="scroll-mt-20">
            <Card className="border-primary/50 bg-primary/5" role="region" aria-label="New API key created">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-lg">
                        <KeyRound className="size-5 text-primary" aria-hidden />
                        <span className="min-w-0 break-words">Your new key: {apiKey.name}</span>
                    </CardTitle>
                    <CardDescription className="flex items-start gap-2 font-medium text-foreground">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden />
                        Copy it now — you won't see it again. Store it like a password.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Input
                            readOnly
                            value={secret}
                            aria-label="Your new API key"
                            data-testid="api-key-secret"
                            className="min-w-0 flex-1 bg-background font-mono text-xs sm:text-sm"
                            onFocus={(e) => e.currentTarget.select()}
                            spellCheck={false}
                            autoComplete="off"
                        />
                        <Button type="button" onClick={() => copy(secret, 'Key')} className="shrink-0">
                            <Copy className="size-4" aria-hidden />
                            Copy
                        </Button>
                    </div>

                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-sm font-medium">Try it</p>
                            <Button type="button" variant="ghost" size="sm" onClick={() => copy(curl, 'Command')}>
                                <Copy className="size-4" aria-hidden />
                                Copy command
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Keep the key out of commands and scripts. In bash, run{' '}
                            <code className="font-mono">read -rs BRAINITIVE_API_KEY</code> and paste it (nothing is echoed or saved in your
                            history), or use your CI's secret settings. The example passes the header on stdin, so the key isn't in curl's
                            arguments either (visible to other users in process lists):
                        </p>
                        <pre className="overflow-x-auto rounded-md border bg-muted p-3 font-mono text-xs">
                            <code>{curl}</code>
                        </pre>
                    </div>

                    <div className="flex justify-end">
                        <Button type="button" variant="outline" onClick={onDone}>
                            <Check className="size-4" aria-hidden />
                            Done
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
