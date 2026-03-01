import { Head, Link, router } from '@inertiajs/react';
import { PageProps, Ticket, TicketReply } from '@/types';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { MessageSquare, Send, User, Headphones, LifeBuoy, FileText } from 'lucide-react';
import { useState, FormEvent } from 'react';
import { cn } from '@/lib/utils';

interface Props extends PageProps {
    ticket: Ticket & { helpdesk?: { id: number; uuid: string }; replies?: TicketReply[] };
    access_token: string;
    app_name: string;
    app_logo_url: string;
}

function stripHtml(html: string): string {
    if (!html) return '';
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return tmp.textContent ?? tmp.innerText ?? '';
}

function isReplyBodyEmpty(html: string): boolean {
    return !stripHtml(html).trim();
}

export default function TicketsPublicShow({ ticket, access_token, app_name, app_logo_url }: Props) {
    const [replyBody, setReplyBody] = useState('');
    const [replyEditorKey, setReplyEditorKey] = useState(0);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const replies = ticket.replies ?? [];
    const helpdeskUuid = ticket.helpdesk?.uuid;

    const handleSubmitReply = async (e: FormEvent) => {
        e.preventDefault();
        if (isReplyBodyEmpty(replyBody) || !helpdeskUuid) return;
        const body = replyBody.trim();
        setError(null);
        setSubmitting(true);
        try {
            const res = await fetch(
                `/widget/helpdesks/${helpdeskUuid}/tickets/${ticket.uuid}/replies`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        body,
                        access_token: access_token,
                    }),
                }
            );
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Failed to send reply');
            setReplyBody('');
            setReplyEditorKey((k) => k + 1);
            router.reload();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Failed to send reply');
            setSubmitting(false);
        }
    };

    return (
        <>
            <Head title={`Ticket ${ticket.key} · ${app_name} Support`} />

            <div className="min-h-screen bg-muted/30 flex flex-col">
                {/* Header */}
                <header className="sticky top-0 z-10 border-b border-border/50 bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                    <div className="max-w-4xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between gap-4">
                        <Link href="/" className="flex items-center gap-3 shrink-0">
                            <img
                                src={app_logo_url}
                                alt={app_name}
                                className="h-8 w-auto object-contain"
                            />
                            <span className="font-semibold text-foreground hidden sm:inline">
                                {app_name}
                            </span>
                        </Link>
                        <Badge variant="secondary" className="gap-1.5 font-medium">
                            <LifeBuoy className="size-3.5" />
                            Support portal
                        </Badge>
                    </div>
                </header>

                {/* Main content */}
                <main className="flex-1 w-full max-w-4xl mx-auto px-4 py-8 sm:px-6">
                    <Card className="overflow-hidden">
                        <CardHeader className="space-y-1.5 pb-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <CardTitle className="text-xl font-semibold tracking-tight">
                                    {ticket.subject}
                                </CardTitle>
                                <Badge variant="outline" className="font-mono text-xs">
                                    {ticket.key}
                                </Badge>
                            </div>
                            <CardDescription>Support ticket</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {ticket.description && (
                                <div className="space-y-2">
                                    <h3 className="text-sm font-medium flex items-center gap-2 text-muted-foreground">
                                        <FileText className="size-4" />
                                        Description
                                    </h3>
                                    <div
                                        className={cn(
                                            'rounded-lg border bg-muted/30 p-4 text-sm',
                                            'prose prose-sm max-w-none dark:prose-invert prose-p:my-2 prose-ul:my-2 prose-ol:my-2'
                                        )}
                                        dangerouslySetInnerHTML={{ __html: ticket.description }}
                                    />
                                </div>
                            )}
                            {ticket.description && <Separator />}
                            <div className="space-y-3">
                                <h3 className="text-sm font-medium flex items-center gap-2 text-foreground">
                                    <MessageSquare className="size-4" />
                                    Conversation
                                </h3>
                                {replies.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No replies yet. Add a reply below.
                                    </p>
                                ) : (
                                    <ul className="space-y-3">
                                        {replies.map((reply) => {
                                            const fromContact = !!reply.ticket_contact_id;
                                            return (
                                                <li
                                                    key={reply.id}
                                                    className={`flex gap-3 ${fromContact ? 'flex-row-reverse' : ''}`}
                                                >
                                                    <div
                                                        className={cn(
                                                            'flex-1 rounded-lg border px-4 py-3 text-sm shadow-sm',
                                                            fromContact
                                                                ? 'bg-primary/5 border-primary/20 ml-8'
                                                                : 'bg-muted/50 border-border mr-8'
                                                        )}
                                                    >
                                                        <div className="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                                                            {fromContact ? (
                                                                <>
                                                                    <User className="size-3" />
                                                                    {reply.contact?.name || reply.contact?.email || 'You'}
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <Headphones className="size-3" />
                                                                    Support
                                                                </>
                                                            )}{' '}
                                                            ·{' '}
                                                            {new Date(
                                                                reply.created_at
                                                            ).toLocaleString()}
                                                        </div>
                                                        <div
                                                            className="prose prose-sm max-w-none dark:prose-invert whitespace-pre-wrap break-words"
                                                            dangerouslySetInnerHTML={{
                                                                __html: reply.body || '',
                                                            }}
                                                        />
                                                    </div>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </div>

                            <Separator className="my-6" />
                            <form
                                onSubmit={handleSubmitReply}
                                className="space-y-4"
                            >
                                <div className="space-y-2">
                                    <Label htmlFor="reply-body" className="text-sm font-medium">
                                        Reply
                                    </Label>
                                    <div
                                        className="rounded-lg border border-input bg-background shadow-sm transition-colors focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:border-primary"
                                        id="reply-body"
                                    >
                                        <RichLexicalEditor
                                            key={replyEditorKey}
                                            content={replyBody || null}
                                            onChange={(html) => setReplyBody(html)}
                                            placeholder="Type your message…"
                                            editable={!submitting}
                                            showToolbar={true}
                                            minHeight="120px"
                                            maxHeight="240px"
                                            contentKey={`public-reply-${replyEditorKey}`}
                                        />
                                    </div>
                                    {error && (
                                        <p className="text-sm font-medium text-destructive">
                                            {error}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={submitting || isReplyBodyEmpty(replyBody)}
                                    className="gap-2"
                                >
                                    <Send className="size-4" />
                                    {submitting ? 'Sending...' : 'Send reply'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </main>

                {/* Footer */}
                <footer className="mt-auto border-t border-[#C0C5C0]/30 bg-white/60 dark:bg-background/80 backdrop-blur-sm">
                    <div className="max-w-4xl mx-auto px-4 sm:px-6 py-10">
                        <div className="flex flex-col sm:flex-row items-center justify-between gap-6">
                            <Link href="/" className="flex items-center gap-2.5 shrink-0">
                                <img
                                    src={app_logo_url}
                                    alt=""
                                    className="h-7 w-auto object-contain"
                                />
                                <span className="text-sm font-semibold text-[#485E72]">
                                    {app_name}
                                </span>
                            </Link>
                            <nav className="flex flex-wrap items-center justify-center gap-6 text-sm font-medium">
                                <Link
                                    href={route('terms')}
                                    className="text-[#485E72]/70 hover:text-[#0E83DC] transition-colors"
                                >
                                    Terms
                                </Link>
                                <Link
                                    href={route('privacy')}
                                    className="text-[#485E72]/70 hover:text-[#0E83DC] transition-colors"
                                >
                                    Privacy
                                </Link>
                                <Link
                                    href={route('cookies')}
                                    className="text-[#485E72]/70 hover:text-[#0E83DC] transition-colors"
                                >
                                    Cookies
                                </Link>
                                <Link
                                    href={route('refund')}
                                    className="text-[#485E72]/70 hover:text-[#0E83DC] transition-colors"
                                >
                                    Refund
                                </Link>
                                <button
                                    type="button"
                                    onClick={() =>
                                        window.dispatchEvent(new CustomEvent('brainitive-open-cookie-settings'))
                                    }
                                    className="text-[#485E72]/70 hover:text-[#0E83DC] transition-colors"
                                >
                                    Cookie settings
                                </button>
                            </nav>
                        </div>
                        <div className="mt-8 pt-6 border-t border-[#C0C5C0]/20 flex flex-col sm:flex-row items-center justify-between gap-3">
                            <p className="text-xs text-[#485E72]/50">
                                &copy; {new Date().getFullYear()} {app_name}. All rights reserved.
                            </p>
                            <div className="flex items-center gap-1.5" aria-hidden>
                                <span className="w-2 h-2 rounded-full bg-[#0E83DC]" />
                                <span className="w-2 h-2 rounded-full bg-[#485E72]" />
                                <span className="w-2 h-2 rounded-full bg-[#69A748]" />
                                <span className="w-2 h-2 rounded-full bg-[#C0C5C0]" />
                            </div>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
