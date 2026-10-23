/**
 * Lead forms (BRAI-93): types, field catalog, option icons and the conditional logic — the same
 * rules as App\Support\LeadForms\LeadFormDefinition on the server (hidden steps / fields are
 * skipped and never validated).
 */
import {
    AlignLeft, AtSign, CheckSquare, ChevronDown, EyeOff, Hash, LayoutGrid, ListChecks, Type,
    Award, Banknote, BarChart3, Bath, Bed, Bike, BookOpen, Briefcase, Building, Building2, Calculator, Calendar,
    CalendarCheck, CalendarClock, CalendarDays, Camera, Car, Check, Circle, Clock, Code, Coffee, Cpu, Crown, Dog,
    DollarSign, Dumbbell, Euro, FilePlus, Gem, Gift, Globe, GraduationCap, Hammer, Handshake, Heart, House, Key,
    Laptop, Layers, LayoutDashboard, Leaf, LifeBuoy, Lightbulb, Mail, MapPin, Megaphone, MessageSquare, Monitor, Moon,
    Music, Package, Paintbrush, Palette, PenTool, Phone, PiggyBank, Plane, Presentation, RefreshCw, Rocket, Scale,
    Search, Settings, Share2, ShieldCheck, ShoppingCart, Shuffle, Smartphone, Smile, Sparkles, Sprout, Star,
    Stethoscope, Store, Sun, Sunrise, Tag, Target, ThumbsUp, Ticket, Trees, TrendingUp, Truck, User, Users, Utensils,
    Video, Wallet, Wrench, X, Zap,
    type LucideIcon,
} from 'lucide-react';

export type LeadFieldType =
    | 'short_text' | 'long_text' | 'email' | 'phone' | 'number' | 'date'
    | 'select' | 'choice' | 'multi_choice' | 'rating' | 'consent' | 'hidden';

export type LeadConditionOp = 'equals' | 'not_equals' | 'includes' | 'gt' | 'lt' | 'answered' | 'not_answered';

export interface LeadCondition {
    field: string;
    op: LeadConditionOp;
    value: string | null;
}

export interface LeadOption {
    id: string;
    label: string;
    icon?: string | null;
}

export interface LeadField {
    id: string;
    key: string;
    type: LeadFieldType;
    label: string;
    help?: string | null;
    placeholder?: string | null;
    required: boolean;
    maps_to?: 'name' | 'email' | 'phone' | 'company' | null;
    options: LeadOption[];
    layout?: 'cards' | 'list';
    min?: number | null;
    max?: number | null;
    visible_if?: LeadCondition | null;
}

export interface LeadStep {
    id: string;
    title: string;
    description?: string | null;
    visible_if?: LeadCondition | null;
    fields: LeadField[];
}

export interface LeadFormDefinition {
    version: number;
    steps: LeadStep[];
}

export interface LeadFormTheme {
    primary: string;
    background: string;
    surface: string;
    text: string;
    font: 'inter' | 'system' | 'serif' | 'rounded' | 'mono';
    radius: 'none' | 'md' | 'lg' | 'full';
    button: 'solid' | 'outline';
    layout: 'card' | 'split' | 'full';
    progress: 'bar' | 'steps' | 'none';
    logo_url: string | null;
    cover_url: string | null;
}

export interface LeadFormSettings {
    intro_title: string | null;
    intro_text: string | null;
    next_label: string;
    back_label: string;
    submit_label: string;
    after_submit: 'message' | 'redirect';
    thank_you_title: string | null;
    thank_you_message: string | null;
    redirect_url: string | null;
    confirmation_email?: boolean;
    confirmation_subject?: string | null;
    confirmation_body?: string | null;
    notify_user_ids?: number[];
    webhook_url?: string | null;
    show_branding: boolean;
}

export type LeadAnswers = Record<string, unknown>;

export const FIELD_TYPES: { type: LeadFieldType; label: string; description: string }[] = [
    { type: 'short_text', label: 'Short text', description: 'One line — names, cities, links' },
    { type: 'long_text', label: 'Long text', description: 'A paragraph or message' },
    { type: 'email', label: 'Email', description: 'Validated email address' },
    { type: 'phone', label: 'Phone', description: 'Phone number' },
    { type: 'number', label: 'Number', description: 'With optional min / max' },
    { type: 'date', label: 'Date', description: 'Date picker' },
    { type: 'choice', label: 'Choice cards', description: 'Pick one — cards with icons' },
    { type: 'multi_choice', label: 'Multiple choice', description: 'Pick several — cards with icons' },
    { type: 'select', label: 'Dropdown', description: 'Pick one from a list' },
    { type: 'rating', label: 'Rating', description: '1 to 5 stars' },
    { type: 'consent', label: 'Consent', description: 'Checkbox to agree' },
    { type: 'hidden', label: 'Hidden', description: 'Filled from the link, e.g. ?campaign=x' },
];

export const TYPE_ICONS: Record<LeadFieldType, LucideIcon> = {
    short_text: Type, long_text: AlignLeft, email: AtSign, phone: Phone, number: Hash, date: Calendar, select: ChevronDown,
    choice: LayoutGrid, multi_choice: ListChecks, rating: Star, consent: CheckSquare, hidden: EyeOff,
};

export const CHOICE_TYPES: LeadFieldType[] = ['select', 'choice', 'multi_choice'];

export const CONDITION_OPS: { op: LeadConditionOp; label: string; needsValue: boolean }[] = [
    { op: 'equals', label: 'is', needsValue: true },
    { op: 'not_equals', label: 'is not', needsValue: true },
    { op: 'includes', label: 'contains', needsValue: true },
    { op: 'gt', label: 'is greater than', needsValue: true },
    { op: 'lt', label: 'is less than', needsValue: true },
    { op: 'answered', label: 'is answered', needsValue: false },
    { op: 'not_answered', label: 'is not answered', needsValue: false },
];

/** Icons for choice options (stored by name). Anything else, e.g. an emoji, is shown as text. */
export const LEAD_ICONS: Record<string, LucideIcon> = {
    award: Award, banknote: Banknote, 'bar-chart-3': BarChart3, bath: Bath, bed: Bed, bike: Bike, 'book-open': BookOpen,
    briefcase: Briefcase, building: Building, 'building-2': Building2, calculator: Calculator, calendar: Calendar,
    'calendar-check': CalendarCheck, 'calendar-clock': CalendarClock, 'calendar-days': CalendarDays, camera: Camera,
    car: Car, check: Check, circle: Circle, clock: Clock, code: Code, coffee: Coffee, cpu: Cpu, crown: Crown, dog: Dog,
    'dollar-sign': DollarSign, dumbbell: Dumbbell, euro: Euro, 'file-plus': FilePlus, gem: Gem, gift: Gift,
    globe: Globe, 'graduation-cap': GraduationCap, hammer: Hammer, handshake: Handshake, heart: Heart, home: House,
    key: Key, laptop: Laptop, layers: Layers, 'layout-dashboard': LayoutDashboard, leaf: Leaf, 'life-buoy': LifeBuoy,
    lightbulb: Lightbulb, mail: Mail, 'map-pin': MapPin, megaphone: Megaphone, 'message-square': MessageSquare,
    monitor: Monitor, moon: Moon, music: Music, package: Package, paintbrush: Paintbrush, palette: Palette,
    'pen-tool': PenTool, phone: Phone, 'piggy-bank': PiggyBank, plane: Plane, presentation: Presentation,
    'refresh-cw': RefreshCw, rocket: Rocket, scale: Scale, search: Search, settings: Settings, 'share-2': Share2,
    'shield-check': ShieldCheck, 'shopping-cart': ShoppingCart, shuffle: Shuffle, smartphone: Smartphone, smile: Smile,
    sparkles: Sparkles, sprout: Sprout, star: Star, stethoscope: Stethoscope, store: Store, sun: Sun, sunrise: Sunrise,
    tag: Tag, target: Target, 'thumbs-up': ThumbsUp, ticket: Ticket, trees: Trees, 'trending-up': TrendingUp,
    truck: Truck, user: User, users: Users, utensils: Utensils, video: Video, wallet: Wallet, wrench: Wrench, x: X,
    zap: Zap,
};

export const FONT_STACKS: Record<LeadFormTheme['font'], string> = {
    inter: 'Inter, ui-sans-serif, system-ui, sans-serif',
    system: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif',
    serif: 'ui-serif, Georgia, Cambria, "Times New Roman", serif',
    rounded: '"Hanken Grotesk", ui-rounded, ui-sans-serif, system-ui, sans-serif',
    mono: '"JetBrains Mono", ui-monospace, SFMono-Regular, monospace',
};

export const RADIUS: Record<LeadFormTheme['radius'], string> = { none: '0px', md: '8px', lg: '14px', full: '24px' };

// ── logic (mirrors LeadFormDefinition::visibleFields) ────────────────────────

export function isAnswered(value: unknown): boolean {
    return !(value === null || value === undefined || value === '' || value === false || (Array.isArray(value) && value.length === 0));
}

function toNumber(v: unknown): number | null {
    if (typeof v === 'number') return v;
    if (typeof v === 'string' && v.trim() !== '' && !isNaN(Number(v))) return Number(v);
    return null;
}

export function passes(condition: LeadCondition | null | undefined, answers: LeadAnswers, visible: Set<string>): boolean {
    if (!condition) return true;
    const answer = visible.has(condition.field) ? answers[condition.field] : undefined;
    const answered = isAnswered(answer);
    const value = condition.value ?? '';

    switch (condition.op) {
        case 'answered':
            return answered;
        case 'not_answered':
            return !answered;
        case 'equals':
            return answered && (Array.isArray(answer) ? answer.includes(value) : String(answer) === String(value));
        case 'not_equals':
            return !answered || (Array.isArray(answer) ? !answer.includes(value) : String(answer) !== String(value));
        case 'includes':
            return answered && (Array.isArray(answer) ? answer.includes(value) : String(answer).toLowerCase().includes(String(value).toLowerCase()));
        case 'gt': {
            const a = toNumber(answer);
            const b = toNumber(value);
            return a !== null && b !== null && a > b;
        }
        case 'lt': {
            const a = toNumber(answer);
            const b = toNumber(value);
            return a !== null && b !== null && a < b;
        }
        default:
            return true;
    }
}

export interface VisibleStep {
    step: LeadStep;
    index: number;
    fields: LeadField[];
}

/** Steps (with their visible fields) a visitor goes through with these answers. */
export function visibleSteps(definition: LeadFormDefinition, answers: LeadAnswers): VisibleStep[] {
    const visible = new Set<string>();
    const out: VisibleStep[] = [];
    definition.steps.forEach((step, index) => {
        if (!passes(step.visible_if, answers, visible)) return;
        const fields: LeadField[] = [];
        for (const field of step.fields) {
            if (passes(field.visible_if, answers, visible)) {
                visible.add(field.id);
                fields.push(field);
            }
        }
        out.push({ step, index, fields });
    });
    return out;
}

/** Client-side check of one step (the server validates again). */
export function stepErrors(fields: LeadField[], answers: LeadAnswers): Record<string, string> {
    const errors: Record<string, string> = {};
    for (const field of fields) {
        if (field.type === 'hidden') continue;
        const value = answers[field.id];
        if (!isAnswered(value)) {
            if (field.required) errors[field.id] = field.type === 'consent' ? 'Please confirm this to continue.' : 'This question is required.';
            continue;
        }
        if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim())) errors[field.id] = 'Enter a valid email address.';
        if (field.type === 'phone' && !/^[0-9+()\-.\s/]{4,40}$/.test(String(value).trim())) errors[field.id] = 'Enter a valid phone number.';
        if (field.type === 'number') {
            const n = toNumber(value);
            if (n === null) errors[field.id] = 'Enter a number.';
            else if ((field.min != null && n < field.min) || (field.max != null && n > field.max)) {
                errors[field.id] = `Enter a number between ${field.min ?? '…'} and ${field.max ?? '…'}.`;
            }
        }
        if (field.type === 'multi_choice' && Array.isArray(value)) {
            if ((field.min != null && value.length < field.min) || (field.max != null && value.length > field.max)) {
                errors[field.id] = `Choose between ${field.min ?? 1} and ${field.max ?? field.options.length} options.`;
            }
        }
    }
    return errors;
}

/** Only the answers of visible fields are sent. */
export function visibleAnswers(definition: LeadFormDefinition, answers: LeadAnswers): LeadAnswers {
    const out: LeadAnswers = {};
    for (const { fields } of visibleSteps(definition, answers)) {
        for (const field of fields) {
            if (isAnswered(answers[field.id])) out[field.id] = answers[field.id];
        }
    }
    return out;
}

// ── builder helpers ──────────────────────────────────────────────────────────

export function newId(prefix: 's' | 'f' | 'o'): string {
    return `${prefix}_${Math.random().toString(36).slice(2, 10)}`;
}

export function newField(type: LeadFieldType): LeadField {
    const meta = FIELD_TYPES.find((t) => t.type === type);
    const isChoice = CHOICE_TYPES.includes(type);
    return {
        id: newId('f'),
        key: '',
        type,
        label: type === 'consent' ? 'I agree that my details are stored to answer my request.' : (meta?.label ?? 'Question'),
        help: null,
        placeholder: null,
        required: false,
        maps_to: null,
        options: isChoice
            ? [
                  { id: newId('o'), label: 'Option 1', icon: type === 'select' ? null : 'star' },
                  { id: newId('o'), label: 'Option 2', icon: type === 'select' ? null : 'heart' },
              ]
            : [],
        layout: type === 'choice' || type === 'multi_choice' ? 'cards' : 'list',
        min: null,
        max: null,
        visible_if: null,
    };
}

export function newStep(n: number): LeadStep {
    return { id: newId('s'), title: `Step ${n}`, description: null, visible_if: null, fields: [] };
}

/** Fields that come before a step / field — the only ones a condition may use. */
export function fieldsBefore(definition: LeadFormDefinition, stepIndex: number, fieldIndex?: number): LeadField[] {
    const out: LeadField[] = [];
    definition.steps.forEach((step, s) => {
        step.fields.forEach((field, f) => {
            if (s < stepIndex || (s === stepIndex && fieldIndex !== undefined && f < fieldIndex)) out.push(field);
        });
    });
    return out;
}

/** Readable "Shown when …" text for a condition. */
export function describeCondition(condition: LeadCondition, definition: LeadFormDefinition): string {
    const field = definition.steps.flatMap((s) => s.fields).find((f) => f.id === condition.field);
    const op = CONDITION_OPS.find((o) => o.op === condition.op);
    const value = field?.options.find((o) => o.id === condition.value)?.label ?? condition.value;
    return `${field?.label ?? 'a removed question'} ${op?.label ?? condition.op}${op?.needsValue ? ` “${value ?? ''}”` : ''}`;
}

// ── app (Pages/LeadForms/Show) ───────────────────────────────────────────────

export interface LeadFormPayload {
    id: number;
    uuid: string;
    name: string;
    slug: string;
    public_slug: string | null;
    status: 'draft' | 'published';
    definition: LeadFormDefinition;
    theme: LeadFormTheme;
    settings: LeadFormSettings;
    public_url: string | null;
    embed_url: string | null;
    inbound_url: string;
    has_inbound_token: boolean;
    webhook_secret: string | null;
    leads_count: number;
}

export interface LeadMember {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
}

export interface LeadCard {
    id: number;
    uuid: string;
    number: number;
    column_id: number;
    name: string | null;
    email: string | null;
    phone: string | null;
    company: string | null;
    source: 'form' | 'webhook';
    answers: { id: string; label: string; type: string; display: string }[];
    utm: Record<string, string> | null;
    referrer: string | null;
    assignee: LeadMember | null;
    created_at: string;
}

export interface LeadColumn {
    id: number;
    name: string;
    color: string | null;
    is_done: boolean;
    /** All leads in the column; `leads` holds the first 200 */
    total: number;
    leads: LeadCard[];
}

export interface LeadTemplateSummary {
    key: string;
    name: string;
    category: string;
    description: string;
    icon: string;
    primary: string;
    steps: { title: string; conditional: boolean; fields: { label: string; type: LeadFieldType }[] }[];
    columns: string[];
}

/** What a lead card is called: name, else email, company or its number. */
export function leadTitle(lead: LeadCard): string {
    return lead.name || lead.email || lead.company || `Lead #${lead.number}`;
}
