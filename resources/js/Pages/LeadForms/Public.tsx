import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef } from 'react';
import LeadFormRenderer, { SubmitResult } from '@/components/leadForms/LeadFormRenderer';
import { LeadAnswers, LeadFormDefinition, LeadFormSettings, LeadFormTheme } from '@/lib/leadForms';

interface Props {
    form: {
        name: string;
        public_slug: string;
        definition: LeadFormDefinition;
        theme: LeadFormTheme;
        settings: LeadFormSettings;
        organization?: string | null;
    };
    embed: boolean;
    submitUrl: string;
    eventsUrl: string;
}

const UTM_KEYS = ['source', 'medium', 'campaign', 'term', 'content'] as const;

/** One random id per visit (tab), so analytics count people, not reloads. */
function visitId(slug: string): string {
    const key = `lf-session-${slug}`;
    try {
        const existing = sessionStorage.getItem(key);
        if (existing) return existing;
        const id = Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => b.toString(16).padStart(2, '0')).join('');
        sessionStorage.setItem(key, id);
        return id;
    } catch {
        return Math.random().toString(36).slice(2, 14);
    }
}

function flatten(errors: Record<string, string[] | string>): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [key, value] of Object.entries(errors)) out[key] = Array.isArray(value) ? value[0] : value;
    return out;
}

/**
 * The public lead form at /f/{slug} (and /f/{slug}/embed inside an iframe): no login, no app chrome.
 */
export default function Public({ form, embed, submitUrl, eventsUrl }: Props) {
    const sessionId = useMemo(() => visitId(form.public_slug), [form.public_slug]);
    const rootRef = useRef<HTMLDivElement>(null);
    const honeypotRef = useRef<HTMLInputElement>(null);
    const params = useMemo(() => new URLSearchParams(window.location.search), []);

    // Hidden questions are filled from the link: ?campaign=spring → the field with key "campaign"
    const initialAnswers = useMemo(() => {
        const out: LeadAnswers = {};
        for (const step of form.definition.steps) {
            for (const field of step.fields) {
                const value = params.get(field.key);
                if (field.type === 'hidden' && value) out[field.id] = value.slice(0, 500);
            }
        }
        return out;
    }, [form.definition, params]);

    const send = useCallback(
        (type: 'view' | 'start' | 'step', step?: number) => {
            void fetch(eventsUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ session_id: sessionId, type, step }),
                keepalive: true,
            }).catch(() => undefined);
        },
        [eventsUrl, sessionId],
    );

    useEffect(() => {
        send('view');
    }, [send]);

    // Embed: tell the host page how tall the form is (iframe auto-height snippet)
    useEffect(() => {
        const root = rootRef.current;
        if (!embed || !root || window.parent === window) return;
        const post = () => window.parent.postMessage({ type: 'brainitive:lead-form:height', slug: form.public_slug, height: root.scrollHeight }, '*');
        const observer = new ResizeObserver(post);
        observer.observe(root);
        post();
        return () => observer.disconnect();
    }, [embed, form.public_slug]);

    const submit = async (answers: LeadAnswers): Promise<SubmitResult> => {
        const utm: Record<string, string> = {};
        for (const key of UTM_KEYS) {
            const value = params.get(`utm_${key}`);
            if (value) utm[key] = value.slice(0, 200);
        }

        const response = await fetch(submitUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                answers,
                session_id: sessionId,
                utm: Object.keys(utm).length ? utm : null,
                referrer: document.referrer ? document.referrer.slice(0, 500) : null,
                website: honeypotRef.current?.value || null,
            }),
        });
        const data = await response.json().catch(() => ({}));

        if (response.status === 422) return { ok: false, errors: flatten(data.errors ?? {}) };
        if (response.status === 429) return { ok: false, errors: {}, message: 'Too many tries — please wait a minute and send again.' };
        if (!response.ok) return { ok: false, errors: {}, message: 'Something went wrong. Please try again.' };

        if (typeof data.redirect_url === 'string' && /^https?:\/\//i.test(data.redirect_url)) {
            window.open(data.redirect_url, embed ? '_top' : '_self');
        }
        return { ok: true, thank_you_title: data.thank_you_title, thank_you_message: data.thank_you_message };
    };

    return (
        <div ref={rootRef} className={embed ? '' : 'min-h-screen'}>
            <Head title={form.settings.intro_title || form.name} />
            {/* Honeypot: invisible to people, bots fill it in */}
            <input ref={honeypotRef} name="website" type="text" tabIndex={-1} autoComplete="off" aria-hidden className="absolute -left-[9999px] h-0 w-0 opacity-0" />
            <LeadFormRenderer
                name={form.name}
                definition={form.definition}
                theme={form.theme}
                settings={form.settings}
                organization={form.organization}
                initialAnswers={initialAnswers}
                onSubmit={submit}
                onEvent={(type, step) => send(type, step)}
                fullPage={!embed}
            />
        </div>
    );
}
