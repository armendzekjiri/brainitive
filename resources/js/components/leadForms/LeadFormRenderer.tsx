import { CSSProperties, FormEvent, KeyboardEvent, ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, ArrowRight, Check, CheckCircle2, Loader2, Star } from 'lucide-react';
import {
    FONT_STACKS,
    LEAD_ICONS,
    LeadAnswers,
    LeadField,
    LeadFormDefinition,
    LeadFormSettings,
    LeadFormTheme,
    RADIUS,
    stepErrors,
    visibleAnswers,
    visibleSteps,
} from '@/lib/leadForms';
import { OperatorNotice } from '@/components/legal/OperatorNotice';
import type { PublicOperatorData } from '@/types';

export type SubmitResult =
    | { ok: true; thank_you_title?: string | null; thank_you_message?: string | null }
    | { ok: false; errors: Record<string, string>; message?: string };

interface Props {
    name: string;
    definition: LeadFormDefinition;
    theme: LeadFormTheme;
    settings: LeadFormSettings;
    organization?: string | null;
    /** Pre-filled answers (hidden fields from the link). */
    initialAnswers?: LeadAnswers;
    /** Builder: show this step (index in definition.steps) and reset when it changes. */
    previewStep?: number;
    /** Without it the form only shows the thank-you screen (builder preview). */
    onSubmit?: (answers: LeadAnswers, extra: { consent: boolean }) => Promise<SubmitResult>;
    onEvent?: (type: 'start' | 'step', step?: number) => void;
    /** Fill the whole viewport (public page) or the container (builder preview, embed). */
    fullPage?: boolean;
    /** The consent checkbox text (server-rendered, BRAI-156); falls back to the default text. */
    consentText?: string | null;
    /** Who runs the form (the workspace) — privacy link and operator line (BRAI-156). */
    operator?: PublicOperatorData | null;
}

/** Same text as LeadFormOptions::DEFAULT_CONSENT_TEXT (builder preview without a server text). */
export const DEFAULT_CONSENT_TEXT =
    'I agree that {organization} stores and uses my details to respond to my request. I can withdraw this consent at any time.';

const AUTOCOMPLETE: Record<string, string> = { name: 'name', email: 'email', phone: 'tel', company: 'organization' };

/** Black or white text on a background colour. */
export function onColor(hex: string): string {
    const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
    if (!m) return '#ffffff';
    const [r, g, b] = [m[1], m[2], m[3]].map((h) => parseInt(h, 16) / 255);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.6 ? '#0f172a' : '#ffffff';
}

/** A lucide icon by name, or the text itself (emoji). */
export function LeadOptionIcon({ icon, className, style }: { icon?: string | null; className?: string; style?: CSSProperties }) {
    if (!icon) return null;
    const Icon = LEAD_ICONS[icon];
    if (Icon) return <Icon className={className} style={style} aria-hidden />;
    return (
        <span className={className} style={{ ...style, lineHeight: 1 }} aria-hidden>
            {icon}
        </span>
    );
}

export default function LeadFormRenderer({
    name,
    definition,
    theme,
    settings,
    organization,
    initialAnswers,
    previewStep,
    onSubmit,
    onEvent,
    fullPage = false,
    consentText,
    operator,
}: Props) {
    const [answers, setAnswers] = useState<LeadAnswers>(initialAnswers ?? {});
    const [position, setPosition] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [consent, setConsent] = useState(false);
    const consentMode = settings.consent ?? 'off';
    const consentLabel =
        consentMode === 'off'
            ? null
            : consentText ??
              (settings.consent_text?.trim() || DEFAULT_CONSENT_TEXT).replace('{organization}', organization || 'the organisation running this form');
    const [done, setDone] = useState<{ title: string | null; message: string | null } | null>(null);
    const started = useRef(false);
    const reported = useRef(new Set<number>());
    const topRef = useRef<HTMLDivElement>(null);
    const advanceTimer = useRef<number>();

    const steps = useMemo(() => visibleSteps(definition, answers), [definition, answers]);
    const at = Math.max(0, Math.min(position, steps.length - 1));
    const current = steps[at];
    const isLast = at >= steps.length - 1;
    // Latest values for timers and async handlers (a pick advances 220 ms later)
    const live = useRef({ answers, position: at, consent: false });
    live.current = { answers, position: at, consent };

    useEffect(() => () => window.clearTimeout(advanceTimer.current), []);
    const isPreview = previewStep !== undefined;

    // Builder: jump to the step being edited
    useEffect(() => {
        if (previewStep === undefined) return;
        setDone(null);
        setErrors({});
        const at = visibleSteps(definition, answers).findIndex((s) => s.index === previewStep);
        setPosition(at >= 0 ? at : 0);
    }, [previewStep]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!current || !onEvent || isPreview) return;
        if (position > 0 && !reported.current.has(current.index)) {
            reported.current.add(current.index);
            onEvent('step', current.index);
        }
    }, [current, position, onEvent, isPreview]);

    const radius = RADIUS[theme.radius] ?? '14px';
    const primaryText = onColor(theme.primary);
    const vars = {
        '--lf-primary': theme.primary,
        '--lf-primary-soft': `${theme.primary}14`,
        '--lf-primary-mid': `${theme.primary}55`,
        '--lf-muted': `${theme.text}99`,
        '--lf-border': `${theme.text}22`,
        '--lf-radius': radius,
        '--lf-radius-sm': theme.radius === 'full' ? '14px' : radius,
        fontFamily: FONT_STACKS[theme.font] ?? FONT_STACKS.inter,
        color: theme.text,
    } as CSSProperties;

    const setAnswer = (field: LeadField, value: unknown) => {
        if (!started.current) {
            started.current = true;
            if (!isPreview) onEvent?.('start');
        }
        setAnswers((prev) => ({ ...prev, [field.id]: value }));
        live.current = { ...live.current, answers: { ...live.current.answers, [field.id]: value } };
        setErrors((prev) => {
            if (!prev[field.id]) return prev;
            const rest = { ...prev };
            delete rest[field.id];
            return rest;
        });
    };

    const scrollTop = () => topRef.current?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

    const next = async () => {
        window.clearTimeout(advanceTimer.current);
        // Recompute from the latest answers: an answer can reveal or hide questions and steps
        const now = live.current.answers;
        const stepsNow = visibleSteps(definition, now);
        const pos = Math.min(live.current.position, stepsNow.length - 1);
        const cur = stepsNow[pos];
        if (!cur) return;
        const stepErrs = stepErrors(cur.fields, now);
        if (Object.keys(stepErrs).length) {
            setErrors(stepErrs);
            return;
        }
        if (pos < stepsNow.length - 1) {
            setPosition(pos + 1);
            live.current = { ...live.current, position: pos + 1 };
            scrollTop();
            return;
        }
        if (consentMode === 'required' && !live.current.consent) {
            setErrors({ consent: 'Please confirm to send the form.' });
            return;
        }
        if (!onSubmit) {
            setDone({ title: settings.thank_you_title, message: settings.thank_you_message });
            return;
        }
        setSubmitting(true);
        setFormError(null);
        try {
            const result = await onSubmit(visibleAnswers(definition, now), { consent: consentMode !== 'off' && live.current.consent });
            if (result.ok) {
                setDone({ title: result.thank_you_title ?? settings.thank_you_title, message: result.thank_you_message ?? settings.thank_you_message });
            } else {
                const byField: Record<string, string> = {};
                for (const [key, msg] of Object.entries(result.errors)) byField[key.replace(/^answers\./, '')] = msg;
                setErrors(byField);
                const firstStep = stepsNow.findIndex((s) => s.fields.some((f) => byField[f.id]));
                if (firstStep >= 0) setPosition(firstStep);
                setFormError(result.message ?? (firstStep < 0 && !byField.consent ? 'Something went wrong. Please try again.' : null));
            }
        } catch {
            setFormError('Could not send the form. Check your connection and try again.');
        } finally {
            setSubmitting(false);
        }
    };

    const back = () => {
        setErrors({});
        window.clearTimeout(advanceTimer.current);
        setPosition(Math.max(0, at - 1));
        scrollTop();
    };

    // The only question of a step is a single choice: picking an answer moves on
    const pick = (field: LeadField, value: string) => {
        setAnswer(field, value);
        window.clearTimeout(advanceTimer.current);
        advanceTimer.current = window.setTimeout(() => {
            // only when, with this answer, the step still has just this one question
            const stepsNow = visibleSteps(definition, live.current.answers);
            const pos = Math.min(live.current.position, stepsNow.length - 1);
            const cur = stepsNow[pos];
            if (cur && pos < stepsNow.length - 1 && cur.fields.filter((f) => f.type !== 'hidden').length === 1 && cur.fields.some((f) => f.id === field.id)) void next();
        }, 220);
    };

    const onKeyDown = (e: KeyboardEvent<HTMLFormElement>) => {
        const target = e.target as HTMLElement;
        if (e.key === 'Enter' && target.tagName === 'INPUT' && (target as HTMLInputElement).type !== 'checkbox') {
            e.preventDefault();
            void next();
        }
    };

    const buttonStyle: CSSProperties =
        theme.button === 'outline'
            ? { border: `2px solid ${theme.primary}`, color: theme.primary, background: 'transparent', borderRadius: 'var(--lf-radius-sm)' }
            : { background: theme.primary, color: primaryText, borderRadius: 'var(--lf-radius-sm)' };

    const progress = steps.length > 1 ? ((position + (done ? 1 : 0)) / steps.length) * 100 : done ? 100 : 0;

    const body: ReactNode = done ? (
        <div className="flex flex-col items-center py-10 text-center">
            <div className="mb-5 flex size-16 items-center justify-center rounded-full" style={{ background: 'var(--lf-primary-soft)', color: theme.primary }}>
                <CheckCircle2 className="size-9" />
            </div>
            <h2 className="text-2xl font-semibold">{done.title || 'Thank you!'}</h2>
            {done.message && (
                <p className="mt-3 max-w-md whitespace-pre-line" style={{ color: 'var(--lf-muted)' }}>
                    {done.message}
                </p>
            )}
        </div>
    ) : current ? (
        <form
            onSubmit={(e: FormEvent) => {
                e.preventDefault();
                void next();
            }}
            onKeyDown={onKeyDown}
            noValidate
        >
            {position === 0 && (settings.intro_title || settings.intro_text) && (
                <div className="mb-6">
                    {settings.intro_title && <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">{settings.intro_title}</h1>}
                    {settings.intro_text && (
                        <p className="mt-2 whitespace-pre-line" style={{ color: 'var(--lf-muted)' }}>
                            {settings.intro_text}
                        </p>
                    )}
                </div>
            )}

            <div className="mb-5">
                <h2 className={position === 0 && settings.intro_title ? 'text-lg font-semibold' : 'text-xl font-semibold'}>{current.step.title}</h2>
                {current.step.description && (
                    <p className="mt-1 text-sm" style={{ color: 'var(--lf-muted)' }}>
                        {current.step.description}
                    </p>
                )}
            </div>

            <div className="space-y-5">
                {current.fields.map((field) => (
                    <FieldInput
                        key={field.id}
                        field={field}
                        value={answers[field.id]}
                        error={errors[field.id]}
                        onChange={(v) => setAnswer(field, v)}
                        onPick={(v) => pick(field, v)}
                        theme={theme}
                    />
                ))}
            </div>

            {isLast && consentLabel && (
                <div className="mt-6">
                    <label className="flex cursor-pointer items-start gap-2.5 text-sm" data-testid="lead-consent">
                        <input
                            type="checkbox"
                            checked={consent}
                            onChange={(e) => {
                                setConsent(e.target.checked);
                                setErrors((prev) => {
                                    const rest = { ...prev };
                                    delete rest.consent;
                                    return rest;
                                });
                            }}
                            className="mt-0.5 size-4 shrink-0"
                            style={{ accentColor: theme.primary }}
                            aria-invalid={!!errors.consent}
                        />
                        <span>
                            {consentLabel}
                            {consentMode === 'optional' && <span style={{ color: 'var(--lf-muted)' }}> (optional)</span>}
                            {operator?.privacy_url && (
                                <>
                                    {' '}
                                    <a href={operator.privacy_url} target="_blank" rel="noopener noreferrer nofollow" className="underline underline-offset-2">
                                        Privacy policy
                                    </a>
                                </>
                            )}
                        </span>
                    </label>
                    {errors.consent && <p className="mt-1.5 text-sm font-medium text-red-600">{errors.consent}</p>}
                </div>
            )}

            {formError && <p className="mt-5 text-sm font-medium text-red-600">{formError}</p>}

            <div className="mt-8 flex items-center gap-3">
                {position > 0 && (
                    <button type="button" onClick={back} className="inline-flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium" style={{ color: 'var(--lf-muted)' }}>
                        <ArrowLeft className="size-4" />
                        {settings.back_label || 'Back'}
                    </button>
                )}
                <button
                    type="submit"
                    disabled={submitting}
                    className="ml-auto inline-flex min-w-[8rem] items-center justify-center gap-2 px-6 py-3 text-sm font-semibold shadow-sm transition hover:opacity-90 disabled:opacity-60"
                    style={buttonStyle}
                >
                    {submitting && <Loader2 className="size-4 animate-spin" />}
                    {isLast ? settings.submit_label || 'Submit' : settings.next_label || 'Next'}
                    {!isLast && !submitting && <ArrowRight className="size-4" />}
                </button>
            </div>
        </form>
    ) : (
        <p style={{ color: 'var(--lf-muted)' }}>This form has no questions yet.</p>
    );

    const header = (
        <div ref={topRef}>
            {theme.logo_url && <img src={theme.logo_url} alt={organization || name} className="mb-6 h-10 max-w-[180px] object-contain" />}
            {theme.progress !== 'none' && steps.length > 1 && (
                <div className="mb-7" aria-hidden>
                    {theme.progress === 'bar' ? (
                        <div className="h-1.5 w-full overflow-hidden rounded-full" style={{ background: 'var(--lf-border)' }}>
                            <div className="h-full rounded-full transition-all duration-500" style={{ width: `${Math.max(progress, 4)}%`, background: theme.primary }} />
                        </div>
                    ) : (
                        <div className="flex items-center gap-1.5">
                            {steps.map((s, i) => {
                                const reached = !!done || i < position;
                                const active = !done && i === position;
                                return (
                                    <div key={s.step.id} className={`flex items-center gap-1.5 ${i < steps.length - 1 ? 'flex-1' : ''}`}>
                                        <span
                                            className="flex size-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold transition"
                                            style={reached || active ? { background: theme.primary, color: primaryText } : { background: 'var(--lf-border)', color: 'var(--lf-muted)' }}
                                        >
                                            {reached ? <Check className="size-3.5" /> : i + 1}
                                        </span>
                                        {i < steps.length - 1 && <span className="h-0.5 flex-1 rounded" style={{ background: reached ? theme.primary : 'var(--lf-border)' }} />}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            )}
        </div>
    );

    const footer = (
        <>
            {operator && <OperatorNotice operator={operator} className="mt-8 text-center" style={{ color: 'var(--lf-muted)' }} />}
            {settings.show_branding && (
                <p className="mt-4 text-center text-xs" style={{ color: 'var(--lf-muted)' }}>
                    Powered by <span className="font-semibold">Brainitive</span>
                </p>
            )}
        </>
    );

    const minH = fullPage ? 'min-h-screen' : 'min-h-full';

    if (theme.layout === 'split') {
        return (
            <div className={`${minH} grid md:grid-cols-2`} style={{ ...vars, background: theme.surface }}>
                <div
                    className="hidden md:block"
                    style={{ background: theme.cover_url ? `center / cover no-repeat url("${theme.cover_url}")` : `linear-gradient(135deg, ${theme.primary}, ${theme.primary}99)` }}
                />
                <div className="flex items-center justify-center px-6 py-12 sm:px-12">
                    <div className="w-full max-w-lg">
                        {header}
                        {body}
                        {footer}
                    </div>
                </div>
            </div>
        );
    }

    if (theme.layout === 'full') {
        return (
            <div className={`${minH} px-5 py-10 sm:px-10 sm:py-16`} style={{ ...vars, background: theme.surface }}>
                <div className="mx-auto w-full max-w-2xl">
                    {header}
                    {body}
                    {footer}
                </div>
            </div>
        );
    }

    return (
        <div className={`${minH} px-4 py-8 sm:py-14`} style={{ ...vars, background: theme.background }}>
            {theme.cover_url && (
                <div className="mx-auto -mb-12 h-40 w-full max-w-xl overflow-hidden sm:h-48" style={{ borderRadius: 'var(--lf-radius)' }}>
                    <img src={theme.cover_url} alt="" className="size-full object-cover" />
                </div>
            )}
            <div
                className="relative mx-auto w-full max-w-xl p-6 shadow-[0_10px_40px_-12px_rgba(15,23,42,0.18)] sm:p-9"
                style={{ background: theme.surface, borderRadius: 'var(--lf-radius)', border: '1px solid var(--lf-border)' }}
            >
                {header}
                {body}
            </div>
            {footer}
        </div>
    );
}

function FieldInput({
    field,
    value,
    error,
    onChange,
    onPick,
    theme,
}: {
    field: LeadField;
    value: unknown;
    error?: string;
    onChange: (value: unknown) => void;
    onPick: (value: string) => void;
    theme: LeadFormTheme;
}) {
    if (field.type === 'hidden') return null;

    const inputStyle = {
        background: theme.surface,
        color: theme.text,
        border: `1.5px solid ${error ? '#dc2626' : 'var(--lf-border)'}`,
        borderRadius: 'var(--lf-radius-sm)',
        '--tw-ring-color': 'var(--lf-primary-mid)',
    } as CSSProperties;
    const inputClass = 'w-full px-3.5 py-2.5 text-[15px] outline-none transition focus:ring-4 placeholder:opacity-50';
    const id = `lf-${field.id}`;

    // choice cards and ratings are groups, not one input: name them with aria-labelledby
    const isGroup = ['choice', 'multi_choice', 'rating'].includes(field.type);
    const labelText = (
        <>
            {field.label}
            {field.required && <span style={{ color: theme.primary }}> *</span>}
        </>
    );
    const label =
        field.type === 'consent' ? null : isGroup ? (
            <p id={`${id}-label`} className="mb-1.5 block text-sm font-medium">
                {labelText}
            </p>
        ) : (
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium">
                {labelText}
            </label>
        );
    const a11y = { 'aria-invalid': error ? true : undefined, 'aria-describedby': error ? `${id}-error` : undefined };
    const help = field.help && field.type !== 'consent' && (
        <p className="mt-1.5 text-xs" style={{ color: 'var(--lf-muted)' }}>
            {field.help}
        </p>
    );
    const err = error && (
        <p id={`${id}-error`} className="mt-1.5 text-xs font-medium text-red-600" role="alert">
            {error}
        </p>
    );

    let control: ReactNode;
    switch (field.type) {
        case 'long_text':
            control = <textarea id={id} {...a11y} rows={4} className={inputClass} style={inputStyle} placeholder={field.placeholder ?? ''} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} />;
            break;
        case 'select':
            control = (
                <select id={id} {...a11y} className={inputClass} style={inputStyle} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || null)}>
                    <option value="">{field.placeholder || 'Choose…'}</option>
                    {field.options.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.label}
                        </option>
                    ))}
                </select>
            );
            break;
        case 'choice':
        case 'multi_choice': {
            const multi = field.type === 'multi_choice';
            const picked: string[] = multi ? ((value as string[]) ?? []) : value ? [value as string] : [];
            const toggle = (optionId: string) => {
                if (!multi) return onPick(optionId);
                onChange(picked.includes(optionId) ? picked.filter((p) => p !== optionId) : [...picked, optionId]);
            };
            const cards = field.layout !== 'list';
            const hasIcons = field.options.some((o) => o.icon);
            control = (
                <div
                    role={multi ? 'group' : 'radiogroup'}
                    aria-labelledby={`${id}-label`}
                    {...a11y}
                    className={cards ? `grid gap-2.5 ${field.options.length > 4 ? 'grid-cols-2 sm:grid-cols-3' : 'grid-cols-2'}` : 'space-y-2'}
                >
                    {field.options.map((o) => {
                        const active = picked.includes(o.id);
                        return (
                            <button
                                key={o.id}
                                type="button"
                                role={multi ? 'checkbox' : 'radio'}
                                aria-checked={active}
                                onClick={() => toggle(o.id)}
                                className={
                                    cards
                                        ? `relative flex flex-col items-center justify-center gap-2 px-3 text-center text-sm font-medium transition hover:-translate-y-0.5 ${hasIcons ? 'py-5' : 'py-3.5'}`
                                        : 'flex w-full items-center gap-3 px-3.5 py-3 text-left text-sm font-medium transition'
                                }
                                style={{
                                    borderRadius: 'var(--lf-radius-sm)',
                                    border: `1.5px solid ${active ? theme.primary : error ? '#dc2626' : 'var(--lf-border)'}`,
                                    background: active ? 'var(--lf-primary-soft)' : theme.surface,
                                    boxShadow: active ? `0 0 0 3px ${theme.primary}22` : undefined,
                                }}
                            >
                                {cards ? (
                                    <>
                                        {active && (
                                            <span className="absolute right-2 top-2 flex size-4 items-center justify-center rounded-full" style={{ background: theme.primary, color: onColor(theme.primary) }}>
                                                <Check className="size-3" />
                                            </span>
                                        )}
                                        {o.icon && <LeadOptionIcon icon={o.icon} className="size-7 text-2xl" style={{ color: active ? theme.primary : 'var(--lf-muted)' }} />}
                                        <span>{o.label}</span>
                                    </>
                                ) : (
                                    <>
                                        <span
                                            className={`flex size-[18px] shrink-0 items-center justify-center ${multi ? 'rounded' : 'rounded-full'}`}
                                            style={{ border: `1.5px solid ${active ? theme.primary : 'var(--lf-border)'}`, background: active ? theme.primary : 'transparent', color: onColor(theme.primary) }}
                                        >
                                            {active && (multi ? <Check className="size-3" /> : <span className="size-1.5 rounded-full bg-current" />)}
                                        </span>
                                        {o.icon && <LeadOptionIcon icon={o.icon} className="size-4" style={{ color: active ? theme.primary : 'var(--lf-muted)' }} />}
                                        <span>{o.label}</span>
                                    </>
                                )}
                            </button>
                        );
                    })}
                </div>
            );
            break;
        }
        case 'rating': {
            const rating = Number(value) || 0;
            control = (
                <div className="flex gap-1.5" role="radiogroup" aria-labelledby={`${id}-label`} {...a11y}>
                    {[1, 2, 3, 4, 5].map((n) => (
                        <button key={n} type="button" role="radio" aria-checked={rating === n} aria-label={`${n} of 5`} onClick={() => onChange(n)} className="p-0.5 transition hover:scale-110">
                            <Star className="size-8" style={{ color: n <= rating ? theme.primary : 'var(--lf-border)' }} fill={n <= rating ? theme.primary : 'transparent'} />
                        </button>
                    ))}
                </div>
            );
            break;
        }
        case 'consent':
            control = (
                <label htmlFor={id} className="flex cursor-pointer items-start gap-3 text-sm">
                    <input id={id} {...a11y} type="checkbox" checked={!!value} onChange={(e) => onChange(e.target.checked)} className="mt-0.5 size-[18px] shrink-0" style={{ accentColor: theme.primary }} />
                    <span>
                        {field.label}
                        {field.required && <span style={{ color: theme.primary }}> *</span>}
                        {field.help && (
                            <span className="mt-0.5 block text-xs" style={{ color: 'var(--lf-muted)' }}>
                                {field.help}
                            </span>
                        )}
                    </span>
                </label>
            );
            break;
        default: {
            const type = field.type === 'email' ? 'email' : field.type === 'phone' ? 'tel' : field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text';
            const autoComplete = field.maps_to ? AUTOCOMPLETE[field.maps_to] : undefined;
            control = (
                <input
                    id={id}
                    {...a11y}
                    type={type}
                    inputMode={field.type === 'number' ? 'decimal' : undefined}
                    autoComplete={autoComplete}
                    min={field.type === 'number' && field.min != null ? field.min : undefined}
                    max={field.type === 'number' && field.max != null ? field.max : undefined}
                    className={inputClass}
                    style={inputStyle}
                    placeholder={field.placeholder ?? ''}
                    value={(value as string | number | undefined) ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
        }
    }

    return (
        <div>
            {label}
            {control}
            {help}
            {err}
        </div>
    );
}
