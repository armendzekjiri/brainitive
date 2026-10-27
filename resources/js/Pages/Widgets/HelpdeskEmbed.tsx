import { OperatorNotice, SubmitPrivacyLine } from '@/components/legal/OperatorNotice';
import type { PublicOperatorData } from '@/types';
import EmbedLayout from '@/Layouts/EmbedLayout';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LexicalEditor } from '@/components/editor/LexicalEditor';
import { Textarea } from '@/components/ui/textarea';
import { Head } from '@inertiajs/react';
import { Loader2, Paperclip, Upload, X } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';
import { cn } from '@/lib/utils';
import { BLOCKED_UPLOAD_MESSAGE, isBlockedUpload } from '@/lib/blockedUploads';
import { safeHtmlProp } from '@/lib/sanitize';

type FormField = {
    id: number;
    name: string;
    label: string;
    type: string;
    options?: string[];
    is_required: boolean;
};

type DefaultEditable = {
    contact_name?: { label: string; is_required: boolean };
    description?: { label: string; is_required: boolean };
};

type Branding = {
    brand_color?: string;
    welcome_message?: string;
};

type FormTexts = {
    form_title: string;
    submit_label: string;
    message_placeholder: string;
    success_message: string;
    after_submit: 'portal' | 'message';
};

type FormConfig = {
    helpdesk_uuid: string;
    default_editable: DefaultEditable;
    attachments_enabled?: boolean;
    fields: FormField[];
    branding: Branding;
    /** Per-helpdesk form texts and subject field (BRAI-92). */
    texts?: FormTexts;
    subject?: { label: string; hidden: boolean };
};

type Reply = {
    id: number;
    body: string;
    created_at: string;
    from_contact: boolean;
};

type TicketData = {
    uuid: string;
    key: string;
    subject: string;
    description: string | null;
};

const defaultFormConfig: FormConfig = {
    helpdesk_uuid: '',
    default_editable: {},
    fields: [],
    branding: { welcome_message: 'How can we help you?' },
};

function isLongText(type: string): boolean {
    const t = (type || '').toLowerCase().replace(/[\s-]/g, '_');
    return t === 'textarea' || t === 'rich_text' || t === 'richtext';
}

function isRichTextType(type: string): boolean {
    const t = (type || '').toLowerCase().replace(/[\s-]/g, '_');
    return t === 'rich_text' || t === 'richtext';
}

function isSelectType(type: string): boolean {
    return (type || '').toLowerCase() === 'select';
}

function isRadioType(type: string): boolean {
    return (type || '').toLowerCase() === 'radio';
}

function isCheckboxesType(type: string): boolean {
    const t = (type || '').toLowerCase().replace(/[\s-]/g, '_');
    return t === 'checkboxes';
}

function isDateType(type: string): boolean {
    return (type || '').toLowerCase() === 'date';
}

const MAX_FILE_SIZE_MB = 10;
const MAX_FILE_SIZE_BYTES = MAX_FILE_SIZE_MB * 1024 * 1024;

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function HelpdeskEmbed({
    helpdesk_uuid,
    base_url,
    ticket_uuid: initialTicketUuid = null,
    token: initialToken = null,
    page,
    operator = null,
}: {
    helpdesk_uuid: string;
    base_url: string;
    ticket_uuid?: string | null;
    token?: string | null;
    /** Set on the shareable support page (/support/{slug}): full page with a branded header. */
    page?: { title: string; organization?: string | null; logo_url?: string | null; brand_color?: string };
    /** Who runs this helpdesk (BRAI-156): the workspace, with its privacy / imprint links. */
    operator?: PublicOperatorData | null;
}) {
    const baseUrl = base_url.replace(/\/$/, '');
    const [formConfig, setFormConfig] = useState<FormConfig | null>(null);
    const [ticketUuid, setTicketUuid] = useState<string | null>(initialTicketUuid);
    const [token, setToken] = useState<string | null>(initialToken);
    const [ticket, setTicket] = useState<TicketData | null>(null);
    const [replies, setReplies] = useState<Reply[]>([]);
    const [loading, setLoading] = useState(true);
    const [loadingTicket, setLoadingTicket] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [submitted, setSubmitted] = useState<string | null>(null);
    const [replySubmitting, setReplySubmitting] = useState(false);
    const [replyBody, setReplyBody] = useState('');
    const [descriptionHtml, setDescriptionHtml] = useState('');
    const [customRichTextHtml, setCustomRichTextHtml] = useState<Record<string, string>>({});
    const [attachments, setAttachments] = useState<File[]>([]);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const fetchFormConfig = useCallback(async () => {
        try {
            const res = await fetch(`${baseUrl}/widget/helpdesks/${helpdesk_uuid}/form`);
            const data = await res.json();
            setFormConfig(data);
        } catch {
            setFormConfig(defaultFormConfig);
        } finally {
            setLoading(false);
        }
    }, [baseUrl, helpdesk_uuid]);

    const fetchTicket = useCallback(async () => {
        if (!ticketUuid || !token) return;
        setLoadingTicket(true);
        setError(null);
        try {
            const res = await fetch(
                `${baseUrl}/widget/tickets/${ticketUuid}?token=${encodeURIComponent(token)}`
            );
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            setTicket(data.ticket);
            setReplies(data.replies || []);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Failed to load ticket');
        } finally {
            setLoadingTicket(false);
        }
    }, [baseUrl, ticketUuid, token]);

    useEffect(() => {
        fetchFormConfig();
    }, [fetchFormConfig]);

    useEffect(() => {
        if (ticketUuid && token) fetchTicket();
    }, [ticketUuid, token, fetchTicket]);

    const showTicketView = ticketUuid && token && (ticket !== null || loadingTicket);

    const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (!formConfig || submitting) return;

        const form = e.currentTarget;
        const de = formConfig.default_editable || {};
        const body: Record<string, string> = {
            // A hidden subject is filled in by the server
            subject: (form.querySelector('[name="subject"]') as HTMLInputElement | null)?.value ?? '',
            contact_email: (form.querySelector('[name="contact_email"]') as HTMLInputElement)?.value ?? '',
        };
        if (de.contact_name) body.contact_name = (form.querySelector('[name="contact_name"]') as HTMLInputElement)?.value ?? '';
        if (de.description) {
            const textOnly = descriptionHtml.replace(/<[^>]*>/g, '').trim();
            if (de.description.is_required && !textOnly) {
                setError('Please fill in the message.');
                setSubmitting(false);
                return;
            }
            body.description = descriptionHtml;
        }
        let validationFailed = false;
        (formConfig.fields || []).forEach((f) => {
            if (validationFailed) return;
            if (isCheckboxesType(f.type)) {
                const checked = form.querySelectorAll<HTMLInputElement>(`[name="field_${f.name}[]"]:checked`);
                if (checked.length > 0) {
                    body[`field_${f.name}`] = JSON.stringify(Array.from(checked).map((el) => el.value));
                }
            } else if (isRichTextType(f.type)) {
                const html = customRichTextHtml[f.name] ?? '';
                const textOnly = html.replace(/<[^>]*>/g, '').trim();
                if (f.is_required && !textOnly) {
                    setError(`Please fill in ${f.label}.`);
                    validationFailed = true;
                    return;
                }
                if (textOnly) body[`field_${f.name}`] = html;
            } else {
                // Radios and checkboxes only count when picked (querySelector alone returns the first radio)
                const el = form.querySelector(`[name="field_${f.name}"]:is(input[type=radio], input[type=checkbox]):checked, [name="field_${f.name}"]:not(input[type=radio]):not(input[type=checkbox])`) as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | null;
                if (el && el.value != null && String(el.value).trim() !== '') {
                    body[`field_${f.name}`] = String(el.value);
                }
            }
        });
        if (validationFailed) {
            setSubmitting(false);
            return;
        }

        setSubmitting(true);
        setError(null);
        try {
            const res = await fetch(`${baseUrl}/widget/helpdesks/${helpdesk_uuid}/tickets`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Submission failed');

            if (formConfig.attachments_enabled && attachments.length > 0) {
                for (const file of attachments) {
                    const fd = new FormData();
                    fd.append('file', file);
                    fd.append('access_token', data.access_token);
                    const upRes = await fetch(
                        `${baseUrl}/widget/helpdesks/${helpdesk_uuid}/tickets/${data.ticket_uuid}/attachments`,
                        { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: fd }
                    );
                    const upData = await upRes.json().catch(() => ({}));
                    if (!upRes.ok) {
                        const msg = (upData as { message?: string }).message;
                        if (upRes.status === 413) throw new Error('File too large. Maximum size is 10 MB per file.');
                        throw new Error(msg || `Failed to upload "${file.name}". Please try a smaller file.`);
                    }
                }
            }
            setAttachments([]);

            // Forms (registration, contact, sign-up…) end on a thank-you screen
            if (formConfig.texts?.after_submit === 'message') {
                setSubmitted(data.message || formConfig.texts.success_message);
                return;
            }

            // Inside an iframe (embedded on a customer site) show the ticket in the embed itself — the
            // portal page refuses to be framed by other sites. Full page: the same portal link as the email.
            window.location.href = window.self !== window.top
                ? `${baseUrl}/widget/helpdesks/${helpdesk_uuid}/embed?ticket=${encodeURIComponent(data.ticket_uuid)}&token=${encodeURIComponent(data.access_token)}`
                : `${baseUrl}/portal/tickets/${data.ticket_uuid}?token=${encodeURIComponent(data.access_token)}`;
            return;
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Something went wrong');
        } finally {
            setSubmitting(false);
        }
    };

    const addFiles = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = e.target.files;
        if (!files?.length) return;
        const next: File[] = [];
        for (const file of Array.from(files)) {
            if (isBlockedUpload(file.name)) {
                setError(`"${file.name}": ${BLOCKED_UPLOAD_MESSAGE}`);
                e.target.value = '';
                return;
            }
            if (file.size > MAX_FILE_SIZE_BYTES) {
                setError(`"${file.name}" is too large. Maximum size is ${MAX_FILE_SIZE_MB} MB per file.`);
                e.target.value = '';
                return;
            }
            next.push(file);
        }
        setError(null);
        setAttachments((prev) => [...prev, ...next]);
        e.target.value = '';
    };

    const onDrop = (e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
        const files = e.dataTransfer.files;
        if (!files?.length) return;
        const next: File[] = [];
        for (const file of Array.from(files)) {
            if (file.size > MAX_FILE_SIZE_BYTES) {
                setError(`"${file.name}" is too large. Maximum size is ${MAX_FILE_SIZE_MB} MB per file.`);
                return;
            }
            next.push(file);
        }
        setError(null);
        setAttachments((prev) => [...prev, ...next]);
    };

    const onDragOver = (e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
    };

    const removeAttachment = (index: number) => {
        setAttachments((prev) => prev.filter((_, i) => i !== index));
    };

    const handleReplySubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        const body = replyBody.trim();
        if (!body || !ticketUuid || !token || replySubmitting) return;

        setReplySubmitting(true);
        setError(null);
        try {
            const res = await fetch(
                `${baseUrl}/widget/helpdesks/${helpdesk_uuid}/tickets/${ticketUuid}/replies`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ body, access_token: token }),
                }
            );
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed to send');
            setReplyBody('');
            await fetchTicket();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Failed to send reply');
        } finally {
            setReplySubmitting(false);
        }
    };

    const welcomeMessage = formConfig?.branding?.welcome_message ?? 'How can we help you?';
    const texts = formConfig?.texts;
    const subjectField = formConfig?.subject ?? { label: 'Subject', hidden: false };

    return (
        <EmbedLayout wide={!!page}>
            <Head title={page ? `${page.title} — ${texts?.form_title ?? 'Support'}` : (texts?.form_title ?? 'Contact support')} />
            {page && (
                <header className="mb-6 flex items-center gap-3">
                    {page.logo_url ? (
                        <img src={page.logo_url} alt="" className="h-10 w-10 rounded-lg object-contain" />
                    ) : (
                        <div
                            className="flex h-10 w-10 items-center justify-center rounded-lg text-lg font-semibold text-white"
                            style={{ backgroundColor: page.brand_color || '#0E83DC' }}
                        >
                            {(page.organization || page.title).charAt(0).toUpperCase()}
                        </div>
                    )}
                    <div className="min-w-0">
                        <h1 className="truncate text-lg font-semibold">{page.title}</h1>
                        {page.organization && <p className="truncate text-sm text-muted-foreground">{page.organization}</p>}
                    </div>
                </header>
            )}
            {loading ? (
                <Card>
                    <CardContent className="flex flex-col items-center justify-center py-12">
                        <Loader2 className="h-7 w-7 animate-spin text-muted-foreground" />
                        <p className="mt-3 text-sm text-muted-foreground">Loading…</p>
                    </CardContent>
                </Card>
            ) : showTicketView ? (
                <>
                    {loadingTicket ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-12">
                                <Loader2 className="h-7 w-7 animate-spin text-muted-foreground" />
                                <p className="mt-3 text-sm text-muted-foreground">Loading ticket…</p>
                            </CardContent>
                        </Card>
                    ) : error && !ticket ? (
                        <Card>
                            <CardContent className="py-6">
                                <p className="text-sm text-destructive">{error}</p>
                            </CardContent>
                        </Card>
                    ) : ticket ? (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-lg">{ticket.subject}</CardTitle>
                                {ticket.description && (
                                    <div
                                        className="mt-3 rounded-md bg-muted/80 p-3 text-sm text-muted-foreground [&_p]:mb-1 [&_p:last-child]:mb-0 [&_a]:text-primary"
                                        dangerouslySetInnerHTML={safeHtmlProp(ticket.description)}
                                    />
                                )}
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {error && (
                                    <p className="text-sm text-destructive">{error}</p>
                                )}
                                <div className="space-y-3">
                                    {replies.map((r) => (
                                        <div
                                            key={r.id}
                                            className={cn(
                                                'rounded-lg border p-3 text-sm',
                                                r.from_contact
                                                    ? 'ml-5 border-primary/20 bg-primary/5'
                                                    : 'mr-5 bg-muted/60'
                                            )}
                                        >
                                            <p className="mb-1 text-xs text-muted-foreground">
                                                {new Date(r.created_at).toLocaleString()}
                                                {r.from_contact ? ' · You' : ' · Support'}
                                            </p>
                                            <div
                                                className="prose prose-sm max-w-none [&_p]:mb-1 [&_a]:text-primary"
                                                dangerouslySetInnerHTML={safeHtmlProp(r.body)}
                                            />
                                        </div>
                                    ))}
                                </div>
                                <form onSubmit={handleReplySubmit} className="space-y-3 border-t pt-4">
                                    <Label htmlFor="reply-body">Reply</Label>
                                    <Textarea
                                        id="reply-body"
                                        placeholder="Type your reply…"
                                        rows={3}
                                        value={replyBody}
                                        onChange={(e) => setReplyBody(e.target.value)}
                                        className="resize-y"
                                    />
                                    <Button type="submit" disabled={replySubmitting}>
                                        {replySubmitting ? (
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : null}
                                        Send reply
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    ) : null}
                </>
            ) : submitted !== null ? (
                <Card>
                    <CardHeader>
                        <CardTitle>{texts?.form_title ?? 'Thank you'}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p role="status" className="text-sm">{submitted}</p>
                    </CardContent>
                </Card>
            ) : (
                <Card>
                    <CardHeader>
                        <CardTitle>{texts?.form_title ?? 'Contact support'}</CardTitle>
                        <CardDescription>{welcomeMessage}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {error && (
                            <p className="mb-4 text-sm text-destructive">{error}</p>
                        )}
                        <form onSubmit={handleSubmit} className="space-y-5">
                            {formConfig?.default_editable?.contact_name && (
                                <div className="space-y-2">
                                    <Label htmlFor="contact_name">
                                        {formConfig.default_editable.contact_name.label}
                                        {formConfig.default_editable.contact_name.is_required && ' *'}
                                    </Label>
                                    <Input id="contact_name" name="contact_name" />
                                </div>
                            )}
                            <div className="space-y-2">
                                <Label htmlFor="contact_email">Email *</Label>
                                <Input
                                    id="contact_email"
                                    name="contact_email"
                                    type="email"
                                    required
                                />
                            </div>
                            {!subjectField.hidden && (
                                <div className="space-y-2">
                                    <Label htmlFor="subject">{subjectField.label} *</Label>
                                    <Input id="subject" name="subject" required />
                                </div>
                            )}
                            {formConfig?.default_editable?.description && (
                                <div className="space-y-2">
                                    <Label>
                                        {formConfig.default_editable.description.label}
                                        {formConfig.default_editable.description.is_required && ' *'}
                                    </Label>
                                    <LexicalEditor
                                        content={null}
                                        onChange={setDescriptionHtml}
                                        editable={true}
                                        placeholder={texts?.message_placeholder ?? 'Type your message…'}
                                        showToolbar={true}
                                        minHeight="120px"
                                        className="rounded-md border border-input shadow-sm min-h-[120px]"
                                    />
                                </div>
                            )}
                            {(formConfig?.fields ?? []).map((f) => (
                                <div key={f.id} className="space-y-2">
                                    <Label htmlFor={!isCheckboxesType(f.type) ? `field_${f.name}` : undefined}>
                                        {f.label}
                                        {f.is_required && !isCheckboxesType(f.type) && ' *'}
                                    </Label>
                                    {isSelectType(f.type) && f.options?.length ? (
                                        <select
                                            id={`field_${f.name}`}
                                            name={`field_${f.name}`}
                                            required={f.is_required}
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                        >
                                            <option value="">— Select —</option>
                                            {f.options.map((opt) => (
                                                <option key={opt} value={opt}>
                                                    {opt}
                                                </option>
                                            ))}
                                        </select>
                                    ) : isRadioType(f.type) && f.options?.length ? (
                                        <div className="flex flex-col gap-2" role="radiogroup" aria-required={f.is_required}>
                                            {f.options.map((opt) => (
                                                <label key={opt} className="flex items-center gap-2 text-sm cursor-pointer">
                                                    <input
                                                        type="radio"
                                                        name={`field_${f.name}`}
                                                        value={opt}
                                                        required={f.is_required}
                                                        className="size-4 rounded-full border-input text-primary focus:ring-ring"
                                                    />
                                                    <span>{opt}</span>
                                                </label>
                                            ))}
                                        </div>
                                    ) : isCheckboxesType(f.type) && f.options?.length ? (
                                        <div className="flex flex-col gap-2 rounded-md border border-input p-3 bg-muted/20">
                                            {f.options.map((opt) => (
                                                <label key={opt} className="flex items-center gap-2 text-sm cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        name={`field_${f.name}[]`}
                                                        value={opt}
                                                        className="size-4 rounded border-input text-primary focus:ring-ring"
                                                    />
                                                    <span>{opt}</span>
                                                </label>
                                            ))}
                                        </div>
                                    ) : isDateType(f.type) ? (
                                        <Input
                                            id={`field_${f.name}`}
                                            name={`field_${f.name}`}
                                            type="date"
                                            required={f.is_required}
                                        />
                                    ) : isRichTextType(f.type) ? (
                                        <div className="rounded-md border border-input overflow-hidden">
                                            <LexicalEditor
                                                content={null}
                                                onChange={(html) => setCustomRichTextHtml((prev) => ({ ...prev, [f.name]: html }))}
                                                editable={true}
                                                placeholder={`Enter ${f.label}…`}
                                                showToolbar={true}
                                                minHeight="120px"
                                                className="min-h-[120px]"
                                            />
                                        </div>
                                    ) : isLongText(f.type) ? (
                                        <Textarea
                                            id={`field_${f.name}`}
                                            name={`field_${f.name}`}
                                            rows={4}
                                            required={f.is_required}
                                        />
                                    ) : f.type === 'checkbox' ? (
                                        <label className="flex items-center gap-2 text-sm cursor-pointer">
                                            <input
                                                type="checkbox"
                                                name={`field_${f.name}`}
                                                value="1"
                                                className="size-4 rounded border-input text-primary focus:ring-ring"
                                            />
                                            <span className="text-muted-foreground">Yes</span>
                                        </label>
                                    ) : (
                                        <Input
                                            id={`field_${f.name}`}
                                            name={`field_${f.name}`}
                                            type={f.type === 'number' ? 'number' : 'text'}
                                            required={f.is_required}
                                        />
                                    )}
                                </div>
                            ))}
                            {formConfig?.attachments_enabled && (
                                <div className="space-y-2">
                                    <div>
                                        <Label className="text-sm font-medium">Attach files</Label>
                                        <p className="text-xs text-muted-foreground mt-0.5">
                                            Optional — drag and drop or click to upload. Max {MAX_FILE_SIZE_MB} MB per file.
                                        </p>
                                    </div>
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        multiple
                                        className="hidden"
                                        onChange={addFiles}
                                    />
                                    <div
                                        role="button"
                                        tabIndex={0}
                                        onClick={() => fileInputRef.current?.click()}
                                        onDrop={onDrop}
                                        onDragOver={onDragOver}
                                        onKeyDown={(e) => e.key === 'Enter' && fileInputRef.current?.click()}
                                        className={cn(
                                            'flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-6 transition-colors',
                                            'border-muted-foreground/25 bg-muted/30 hover:bg-muted/50 hover:border-primary/30',
                                            'cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
                                        )}
                                    >
                                        <Upload className="h-8 w-8 text-muted-foreground" />
                                        <span className="text-sm font-medium text-muted-foreground">
                                            Click or drag files here
                                        </span>
                                    </div>
                                    {attachments.length > 0 && (
                                        <ul className="space-y-2">
                                            {attachments.map((file, i) => (
                                                <li
                                                    key={i}
                                                    className="flex items-center gap-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                                                >
                                                    <Paperclip className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                    <span className="min-w-0 flex-1 truncate" title={file.name}>
                                                        {file.name}
                                                    </span>
                                                    <span className="shrink-0 text-xs text-muted-foreground">
                                                        {formatFileSize(file.size)}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={(e) => { e.preventDefault(); removeAttachment(i); }}
                                                        className="shrink-0 rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                                        aria-label="Remove file"
                                                    >
                                                        <X className="h-4 w-4" />
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            )}
                            <Button type="submit" disabled={submitting}>
                                {submitting ? (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                ) : null}
                                {texts?.submit_label ?? 'Submit'}
                            </Button>
                            <SubmitPrivacyLine operator={operator} />
                        </form>
                    </CardContent>
                </Card>
            )}
            {page && <OperatorNotice operator={operator} className="mt-8 text-center" />}
        </EmbedLayout>
    );
}
