import { Head, Link, router } from '@inertiajs/react';
import { PageProps, Ticket, TicketReply } from '@/types';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { MessageSquare, Send, User, Headphones, LifeBuoy, FileText, Circle, Paperclip, X } from 'lucide-react';
import { useState, FormEvent, useRef } from 'react';
import { cn } from '@/lib/utils';
import { safeHtmlProp } from '@/lib/sanitize';

import { BLOCKED_UPLOAD_MESSAGE, isBlockedUpload } from '@/lib/blockedUploads';

const MAX_REPLY_FILE_SIZE_MB = 10;
const MAX_REPLY_FILE_SIZE_BYTES = MAX_REPLY_FILE_SIZE_MB * 1024 * 1024;

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

interface Props extends PageProps {
    ticket: Ticket & { helpdesk?: { id: number; uuid: string }; replies?: TicketReply[] };
    access_token: string;
    app_name: string;
    app_logo_url: string;
    attachments_enabled?: boolean;
}

function stripHtml(html: string): string {
    if (!html) return '';
    // DOMParser documents are inert: no scripts, no image loads (innerHTML on a div would fire onerror).
    return new DOMParser().parseFromString(html, 'text/html').body.textContent ?? '';
}

function isReplyBodyEmpty(html: string): boolean {
    return !stripHtml(html).trim();
}

export default function TicketsPublicShow({ ticket, access_token, app_name, app_logo_url, attachments_enabled = true }: Props) {
    const [replyBody, setReplyBody] = useState('');
    const [replyEditorKey, setReplyEditorKey] = useState(0);
    const [replyAttachments, setReplyAttachments] = useState<File[]>([]);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const replyFileInputRef = useRef<HTMLInputElement>(null);

    const replies = ticket.replies ?? [];
    const helpdeskUuid = ticket.helpdesk?.uuid;

    const addReplyFiles = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = e.target.files;
        if (!files?.length) return;
        const next: File[] = [];
        for (const file of Array.from(files)) {
            if (isBlockedUpload(file.name)) {
                setError(`"${file.name}": ${BLOCKED_UPLOAD_MESSAGE}`);
                e.target.value = '';
                return;
            }
            if (file.size > MAX_REPLY_FILE_SIZE_BYTES) {
                setError(`"${file.name}" is too large. Max ${MAX_REPLY_FILE_SIZE_MB} MB per file.`);
                e.target.value = '';
                return;
            }
            next.push(file);
        }
        setError(null);
        setReplyAttachments((prev) => [...prev, ...next]);
        e.target.value = '';
    };

    const removeReplyFile = (index: number) => {
        setReplyAttachments((prev) => prev.filter((_, i) => i !== index));
    };

    const handleSubmitReply = async (e: FormEvent) => {
        e.preventDefault();
        if (isReplyBodyEmpty(replyBody) || !helpdeskUuid) return;
        const body = replyBody.trim();
        setError(null);
        setSubmitting(true);
        try {
            const hasFiles = replyAttachments.length > 0;
            let res: Response;
            if (hasFiles) {
                const formData = new FormData();
                formData.append('body', body);
                formData.append('access_token', access_token);
                replyAttachments.forEach((file) => formData.append('file[]', file));
                res = await fetch(
                    `/widget/helpdesks/${helpdeskUuid}/tickets/${ticket.uuid}/replies`,
                    {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    }
                );
            } else {
                res = await fetch(
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
            }
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Failed to send reply');
            setReplyBody('');
            setReplyEditorKey((k) => k + 1);
            setReplyAttachments([]);
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
                                {ticket.column && (
                                    <Badge
                                        variant="secondary"
                                        className="gap-1 font-medium"
                                        style={
                                            ticket.column.color
                                                ? { backgroundColor: `${ticket.column.color}20`, color: ticket.column.color, borderColor: ticket.column.color }
                                                : undefined
                                        }
                                    >
                                        <Circle className="size-2.5 fill-current" />
                                        {ticket.column.name}
                                    </Badge>
                                )}
                            </div>
                            <CardDescription>
                                Support ticket{ticket.column ? ` · ${ticket.column.name}` : ''}
                            </CardDescription>
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
                                        dangerouslySetInnerHTML={safeHtmlProp(ticket.description)}
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
                                                            dangerouslySetInnerHTML={safeHtmlProp(reply.body || '')}
                                                        />
                                                        {reply.attachments && reply.attachments.length > 0 && (
                                                            <ul className="mt-2 flex flex-wrap gap-2">
                                                                {reply.attachments.map((att) => (
                                                                    <li key={att.id}>
                                                                        <a
                                                                            href={att.url}
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            className="inline-flex items-center gap-1.5 rounded border bg-muted/50 px-2 py-1 text-xs text-foreground hover:bg-muted"
                                                                        >
                                                                            <FileText className="size-3.5 shrink-0" />
                                                                            <span className="truncate max-w-[160px]">
                                                                                {att.original_filename}
                                                                            </span>
                                                                        </a>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        )}
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
                                    {attachments_enabled && <div className="space-y-2">
                                        <input
                                            ref={replyFileInputRef}
                                            type="file"
                                            className="hidden"
                                            multiple
                                            accept="*/*"
                                            onChange={addReplyFiles}
                                        />
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="gap-1.5"
                                                disabled={submitting}
                                                onClick={() => replyFileInputRef.current?.click()}
                                            >
                                                <Paperclip className="size-4" />
                                                Add attachment
                                            </Button>
                                            <span className="text-xs text-muted-foreground">
                                                Max {MAX_REPLY_FILE_SIZE_MB} MB per file
                                            </span>
                                        </div>
                                        {replyAttachments.length > 0 && (
                                            <ul className="flex flex-wrap gap-2">
                                                {replyAttachments.map((file, i) => (
                                                    <li
                                                        key={i}
                                                        className="flex items-center gap-1.5 rounded-md border bg-muted/50 px-2 py-1 text-sm"
                                                    >
                                                        <span className="truncate max-w-[180px]" title={file.name}>
                                                            {file.name}
                                                        </span>
                                                        <span className="text-muted-foreground text-xs">
                                                            ({formatFileSize(file.size)})
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={() => removeReplyFile(i)}
                                                            className="rounded p-0.5 hover:bg-muted text-muted-foreground hover:text-foreground"
                                                            aria-label="Remove file"
                                                        >
                                                            <X className="size-3.5" />
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>}
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
