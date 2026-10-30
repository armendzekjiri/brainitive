import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import ApplicationLogo from '@/components/ApplicationLogo';
import {
    LayoutDashboard,
    FolderKanban,
    FileText,
    PenLine,
    Palette,
    Users,
    Share2,
    Zap,
    ChevronRight,
    Check,
    ArrowRight,
    Sparkles,
    Globe,
    Shield,
    Menu,
    X,
    Moon,
    Sun,
    Bookmark,
    Layers,
    LifeBuoy,
    Bell,
    CreditCard,
    ShieldCheck,
    Scale,
    Puzzle,
    Download,
    MousePointerClick,
    ListChecks,
    CalendarClock,
    CalendarDays,
    Briefcase,
    Code2,
    Eye,
    CircleUser,
    Bot,
    KeyRound,
    Search,
    GanttChart,
    MonitorPlay,
    Inbox,
    Timer,
    Command,
    ListFilter,
    SlidersHorizontal,
    LayoutTemplate,
    Rocket,
    Network,
    Link2,
    Headset,
    ClipboardList,
    Magnet,
    GitBranch,
    Gauge,
    HeartPulse,
    NotebookPen,
    ChartColumn,
    Webhook,
    PanelLeft,
    Workflow,
    Repeat,
    MailOpen,
    PackageOpen,
    Archive,
    Clock,
    FileSpreadsheet,
    ReceiptText,
    Lightbulb,
    BadgeCheck,
    Video,
    CalendarSync,
    WifiOff,
    MailCheck,
    UsersRound,
    Terminal,
    type LucideIcon,
} from 'lucide-react';
import { useTheme } from '@/hooks/useTheme';
import { Button } from '@/components/ui/button';
import { LegalFooterLinks } from '@/components/legal/LegalFooterLinks';
import { TermsNotice } from '@/components/legal/TermsNotice';
import GitHubStarButton from '@/components/GitHubStarButton';
import { DOCS_URL, SELF_HOSTING_DOCS_URL } from '@/lib/docs';

/*
 * Brand palette
 * Blue:      #0E83DC
 * Dark Slate:#485E72
 * Green:     #69A748
 * Light Gray:#C0C5C0
 */

/* ─── Scroll-reveal hook ─── */
function useScrollReveal() {
    const ref = useRef<HTMLDivElement>(null);
    // In standalone PWA mode the IntersectionObserver can silently fail to fire
    // on initial load (viewport timing), causing a permanent white screen.
    const isStandalone = typeof window !== 'undefined' &&
        (window.matchMedia?.('(display-mode: standalone)').matches || (window.navigator as any).standalone);
    const [visible, setVisible] = useState(isStandalone);

    useEffect(() => {
        if (visible) return;

        // Fallback: guarantee content appears even if the observer never fires
        const timer = setTimeout(() => setVisible(true), 1200);

        const el = ref.current;
        if (!el) return () => clearTimeout(timer);
        const obs = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setVisible(true);
                    obs.disconnect();
                    clearTimeout(timer);
                }
            },
            { threshold: 0.15 },
        );
        obs.observe(el);
        return () => { obs.disconnect(); clearTimeout(timer); };
    }, [visible]);

    return { ref, visible };
}

function RevealSection({
    children,
    className = '',
    delay = 0,
}: {
    children: React.ReactNode;
    className?: string;
    delay?: number;
}) {
    const { ref, visible } = useScrollReveal();
    return (
        <div
            ref={ref}
            className={`transition-all duration-700 ease-out ${visible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'} ${className}`}
            style={{ transitionDelay: `${delay}ms` }}
        >
            {children}
        </div>
    );
}

const REPO_URL = 'https://github.com/armendzekjiri/brainitive';
const SELF_HOSTING_URL = SELF_HOSTING_DOCS_URL;

type Billing = 'monthly' | 'yearly';

/**
 * Brainitive Cloud tiers; prices per user and month in EUR (yearly = billed once a year).
 * Planned prices only: Cloud is free during early access and nothing is billed yet.
 */
const CLOUD_PLANS: {
    name: string;
    description: string;
    price: Record<Billing, number>;
    perUser: boolean;
    features: string[];
    cta: string;
    highlighted?: boolean;
}[] = [
    {
        name: 'Free',
        description: 'For small teams getting started.',
        price: { monthly: 0, yearly: 0 },
        perUser: false,
        features: [
            'Up to 5 members',
            'All features: boards, Scrum, docs, whiteboards, Gantt plans',
            'Unlimited projects, pages and boards',
            '1 GB file storage',
            'Community support',
        ],
        cta: 'Join early access',
    },
    {
        name: 'Team',
        description: 'For teams that run their projects on Brainitive.',
        price: { monthly: 6, yearly: 5 },
        perUser: true,
        features: [
            'Unlimited members and guests by role',
            'Everything in Free',
            '100 GB file storage',
            'Email support (within 2 business days)',
        ],
        cta: 'Join early access',
        highlighted: true,
    },
    {
        name: 'Business',
        description: 'For companies with compliance needs.',
        price: { monthly: 12, yearly: 10 },
        perUser: true,
        features: [
            'Everything in Team',
            'Priority support (next business day)',
            'Data processing agreement (DPA / AVV)',
            '99.9% uptime SLA',
            'Daily backups with 30-day restore',
        ],
        cta: 'Join early access',
    },
];

function formatEuro(value: number): string {
    return value === 0 ? '0' : Number.isInteger(value) ? String(value) : value.toFixed(2);
}

function PricingSection() {
    const [billing, setBilling] = useState<Billing>('yearly');

    return (
        <section id="pricing" className="relative z-10 px-6 py-24 sm:py-32">
            <div className="max-w-6xl mx-auto">
                <RevealSection className="text-center mb-12">
                    <div className="inline-flex items-center gap-2 rounded-full border border-[#0E83DC]/20 bg-[#0E83DC]/5 px-4 py-1.5 text-xs font-semibold text-[#0E83DC] mb-4">
                        <Sparkles className="h-3.5 w-3.5" />
                        Pricing
                    </div>
                    <h2 className="text-3xl sm:text-4xl font-bold text-[#485E72] dark:text-slate-100 mb-4">
                        Open source. Pay only for the cloud.
                    </h2>
                    <p className="text-[#485E72]/60 dark:text-slate-400 max-w-2xl mx-auto text-lg">
                        Every feature is free to self-host. Brainitive Cloud runs it for you &mdash;
                        updates, backups and support included.
                    </p>

                    <div className="mt-8 mx-auto max-w-2xl rounded-2xl border border-[#69A748]/30 bg-[#69A748]/[0.07] px-5 py-4 text-left sm:flex sm:items-start sm:gap-3">
                        <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-[#69A748] px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white">
                            <Sparkles className="h-3 w-3" />
                            Early access
                        </span>
                        <p className="mt-2 sm:mt-0 text-sm text-[#485E72]/80 dark:text-slate-300">
                            <strong className="text-[#485E72] dark:text-slate-100">Brainitive Cloud is free while we're in early access</strong> &mdash;
                            no plan limits, no credit card. The prices below are planned for later. We'll announce them at
                            least 30 days ahead, and you only pay if you choose a paid plan.
                        </p>
                    </div>

                    <div className="mt-8 inline-flex items-center rounded-xl border border-border/70 bg-card/70 p-1" role="radiogroup" aria-label="Billing period">
                        {(['monthly', 'yearly'] as Billing[]).map((period) => (
                            <button
                                key={period}
                                type="button"
                                role="radio"
                                aria-checked={billing === period}
                                onClick={() => setBilling(period)}
                                className={`rounded-lg px-4 py-2 text-sm font-semibold transition-all ${billing === period ? 'bg-[#0E83DC] text-white shadow' : 'text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC]'}`}
                            >
                                {period === 'monthly' ? 'Monthly' : 'Yearly'}
                                {period === 'yearly' && (
                                    <span className={`ml-2 rounded-full px-1.5 py-0.5 text-[10px] font-bold ${billing === 'yearly' ? 'bg-white/20' : 'bg-[#69A748]/15 text-[#69A748]'}`}>
                                        −17%
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                </RevealSection>

                {/* Cloud tiers */}
                <div className="grid gap-6 md:grid-cols-3">
                    {CLOUD_PLANS.map((plan, i) => (
                        <RevealSection key={plan.name} delay={i * 80}>
                            <div
                                className={`relative h-full rounded-2xl border p-6 flex flex-col transition-all duration-300 hover:-translate-y-1 ${plan.highlighted
                                    ? 'border-[#0E83DC]/40 bg-card shadow-xl shadow-[#0E83DC]/10 ring-1 ring-[#0E83DC]/20'
                                    : 'border-border/60 bg-card/70 backdrop-blur-sm hover:bg-card hover:shadow-lg hover:shadow-[#485E72]/[0.06] dark:hover:shadow-black/20'
                                    }`}
                            >
                                {plan.highlighted && (
                                    <span className="absolute -top-3 left-6 rounded-full bg-[#0E83DC] px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-white shadow">
                                        Most popular
                                    </span>
                                )}
                                <div className="text-xs font-semibold uppercase tracking-wider text-[#0E83DC]">Cloud</div>
                                <h3 className="mt-1 font-bold text-xl text-[#485E72] dark:text-slate-100">{plan.name}</h3>
                                <p className="mt-1 text-sm text-[#485E72]/60 dark:text-slate-400">{plan.description}</p>
                                {plan.perUser && (
                                    <span className="mt-4 self-start rounded-full bg-[#69A748]/15 px-2.5 py-0.5 text-[11px] font-bold text-[#69A748]">
                                        Free during early access
                                    </span>
                                )}
                                <div className={`${plan.perUser ? 'mt-2' : 'mt-5'} flex items-baseline gap-1.5`}>
                                    <span className="text-4xl font-bold text-[#485E72] dark:text-slate-100">€{formatEuro(plan.price[billing])}</span>
                                    <span className="text-sm text-[#485E72]/60 dark:text-slate-400">
                                        {plan.perUser ? `per user / month${billing === 'yearly' ? ', billed yearly' : ''}, later` : 'forever'}
                                    </span>
                                </div>
                                <ul className="mt-6 space-y-2.5 flex-1">
                                    {plan.features.map((feature) => (
                                        <li key={feature} className="flex items-start gap-2.5 text-sm text-[#485E72]/75 dark:text-slate-300">
                                            <Check className="h-4 w-4 shrink-0 mt-0.5 text-[#69A748]" strokeWidth={2.5} />
                                            {feature}
                                        </li>
                                    ))}
                                </ul>
                                <Link
                                    href={route('register')}
                                    className={`mt-6 inline-flex items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold transition-all ${plan.highlighted
                                        ? 'bg-[#0E83DC] text-white hover:bg-[#0b6fbf] shadow-lg shadow-[#0E83DC]/25'
                                        : 'border-2 border-border/80 text-[#485E72] dark:text-slate-100 hover:border-[#0E83DC]/40 hover:text-[#0E83DC]'
                                        }`}
                                >
                                    {plan.cta}
                                    <ChevronRight className="h-4 w-4" />
                                </Link>
                            </div>
                        </RevealSection>
                    ))}
                </div>

                {/* Self-hosted + Enterprise */}
                <div className="mt-6 grid gap-6 md:grid-cols-2">
                    <RevealSection delay={240}>
                        <div className="h-full rounded-2xl border border-border/60 bg-card/70 backdrop-blur-sm p-6 sm:p-7 flex flex-col">
                            <div className="flex items-center justify-between gap-4">
                                <div>
                                    <div className="text-xs font-semibold uppercase tracking-wider text-[#69A748]">Open source</div>
                                    <h3 className="mt-1 font-bold text-xl text-[#485E72] dark:text-slate-100">Self-hosted</h3>
                                </div>
                                <div className="text-right">
                                    <div className="text-3xl font-bold text-[#485E72] dark:text-slate-100">€0</div>
                                    <div className="text-xs text-[#485E72]/60 dark:text-slate-400">AGPL-3.0</div>
                                </div>
                            </div>
                            <p className="mt-3 text-sm text-[#485E72]/60 dark:text-slate-400 flex-1">
                                Every feature, unlimited users, on your own server. One Docker command to start,
                                community support on GitHub.
                            </p>
                            <div className="mt-5 flex flex-wrap gap-3">
                                <a href={SELF_HOSTING_URL} target="_blank" rel="noopener" className="inline-flex items-center gap-2 rounded-xl bg-[#485E72] dark:bg-slate-100 px-4 py-2.5 text-sm font-bold text-white dark:text-slate-900 hover:opacity-90 transition-opacity">
                                    Self-host guide
                                    <ChevronRight className="h-4 w-4" />
                                </a>
                                <GitHubStarButton className="self-center text-sm" />
                            </div>
                        </div>
                    </RevealSection>

                    <RevealSection delay={320}>
                        <a
                            href="mailto:hello@brainitive.app?subject=Brainitive%20Enterprise"
                            className="group h-full rounded-2xl border-2 border-border/70 bg-card/70 backdrop-blur-sm p-6 sm:p-7 flex flex-col transition-all duration-300 hover:border-[#0E83DC]/30 hover:bg-card hover:shadow-lg hover:shadow-[#485E72]/[0.06] dark:hover:shadow-black/20"
                        >
                            <div className="flex items-center justify-between gap-4">
                                <div>
                                    <div className="text-xs font-semibold uppercase tracking-wider text-[#485E72]/70 dark:text-slate-400">Cloud or on-premise</div>
                                    <h3 className="mt-1 font-bold text-xl text-[#485E72] dark:text-slate-100">Enterprise</h3>
                                </div>
                                <div className="text-right text-sm font-semibold text-[#485E72]/70 dark:text-slate-300">Custom</div>
                            </div>
                            <ul className="mt-3 grid gap-1.5 text-sm text-[#485E72]/70 dark:text-slate-300 sm:grid-cols-2 flex-1">
                                {['Commercial licence (no AGPL obligations)', 'Dedicated instance or on-premise', 'SAML SSO & SCIM on request', 'Custom SLA & onboarding'].map((item) => (
                                    <li key={item} className="flex items-start gap-2">
                                        <Check className="h-4 w-4 shrink-0 mt-0.5 text-[#69A748]" strokeWidth={2.5} />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                            <span className="mt-5 inline-flex items-center gap-2 self-start rounded-xl border-2 border-border/80 px-4 py-2.5 text-sm font-bold text-[#485E72] dark:text-slate-100 group-hover:border-[#0E83DC]/40 group-hover:text-[#0E83DC] transition-all">
                                Contact us
                                <ChevronRight className="h-4 w-4" />
                            </span>
                        </a>
                    </RevealSection>
                </div>
            </div>
        </section>
    );
}

/**
 * "New in recent releases": the biggest features of the latest releases, shown larger at the top
 * of #features. With a new release, put its headline feature first and keep 2 (at most 3) cards —
 * the oldest drops out here but keeps its card in `featureGroups`. `version` is the release that
 * shipped it (CHANGELOG in HelpModal.tsx). Only advertise what is built and released.
 */
const SPOTLIGHT: {
    key: string;
    version: string;
    icon: LucideIcon;
    color: string;
    title: string;
    description: string;
    points: string[];
    /** keyboard shortcut shown as a key cap next to the title */
    shortcut?: string;
}[] = [
    {
        key: 'cli',
        version: 'v1.47',
        icon: Terminal,
        color: '#485E72',
        title: 'Brainitive in your terminal',
        description:
            'One command installs the CLI on macOS, Linux and Windows. Capture a thought, create an issue with a screenshot, add tasks, save snippets, search — and let AI agents use your workspace.',
        points: [
            'curl -fsSL brainitive.app/install.sh | sh',
            'brainitive capture "ask Dana about Q4"',
            'brainitive mcp: your account for Claude Code, Cursor & co.',
        ],
    },
    {
        key: 'branded-email',
        version: 'v1.44',
        icon: MailCheck,
        color: '#69A748',
        title: 'Emails that look like Brainitive',
        description:
            'Every email — notifications, invitations, helpdesk replies, portals — in one clean, branded layout that works on phones and in dark mode.',
        points: [
            '“Manage notifications” in every member email',
            'Helpdesk customers see your helpdesk’s name',
            'Test web push on each of your devices',
        ],
    },
    {
        key: 'privacy',
        version: 'v1.44',
        icon: ShieldCheck,
        color: '#0E83DC',
        title: 'Privacy by default',
        description:
            'No third-party fonts or trackers in your browser, a clear privacy policy, imprint and data processing agreement — and your public pages name you as the operator.',
        points: [
            'Your privacy & imprint links on support pages and forms',
            'Optional consent checkbox on lead forms',
            'Data processing agreement for teams',
        ],
    },
];

function WhatsNew() {
    return (
        <RevealSection className="mb-16 sm:mb-20">
            <section aria-labelledby="whats-new-title">
                <div className="flex flex-wrap items-center gap-3 mb-6">
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-[#69A748] px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white">
                        <Sparkles className="h-3 w-3" />
                        New
                    </span>
                    <h3 id="whats-new-title" className="text-lg sm:text-xl font-bold text-[#485E72] dark:text-slate-100 leading-tight">
                        New in recent releases
                    </h3>
                </div>
                <div className={`grid gap-5 ${SPOTLIGHT.length > 2 ? 'md:grid-cols-2 lg:grid-cols-3' : 'md:grid-cols-2'}`}>
                    {SPOTLIGHT.map((s) => (
                        <article
                            key={s.key}
                            className="relative h-full overflow-hidden rounded-2xl border border-border/70 bg-card p-6 sm:p-7 shadow-lg shadow-[#485E72]/[0.06] dark:shadow-black/20 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl"
                        >
                            <div
                                aria-hidden
                                className="pointer-events-none absolute -top-20 -right-20 h-56 w-56 rounded-full blur-3xl"
                                style={{ backgroundColor: `${s.color}1f` }}
                            />
                            <div className="relative flex items-start justify-between gap-4">
                                <div
                                    className="flex h-12 w-12 items-center justify-center rounded-xl shrink-0"
                                    style={{ backgroundColor: `${s.color}14`, color: s.color }}
                                >
                                    <s.icon className="h-6 w-6" strokeWidth={1.8} />
                                </div>
                                <span className="rounded-full border border-border/70 bg-background/60 px-2.5 py-0.5 text-[11px] font-semibold text-[#485E72]/70 dark:text-slate-400">
                                    {s.version}
                                </span>
                            </div>
                            <div className="relative mt-4 flex items-center gap-2.5">
                                <h4 className="text-xl font-bold text-[#485E72] dark:text-slate-100 leading-tight">{s.title}</h4>
                                {s.shortcut && (
                                    <kbd
                                        title={`Press ${s.shortcut}`}
                                        className="inline-flex h-7 min-w-7 items-center justify-center rounded-md border border-border border-b-2 bg-background px-2 font-mono text-sm font-bold text-[#0E83DC] shadow-sm"
                                    >
                                        {s.shortcut}
                                    </kbd>
                                )}
                            </div>
                            <p className="relative mt-2 text-sm leading-relaxed text-[#485E72]/70 dark:text-slate-300">{s.description}</p>
                            <ul className="relative mt-4 space-y-2">
                                {s.points.map((point) => (
                                    <li key={point} className="flex items-start gap-2.5 text-sm text-[#485E72]/75 dark:text-slate-300">
                                        <Check className="h-4 w-4 shrink-0 mt-0.5 text-[#69A748]" strokeWidth={2.5} />
                                        {point}
                                    </li>
                                ))}
                            </ul>
                        </article>
                    ))}
                </div>
            </section>
        </RevealSection>
    );
}

/** Product screenshots (light + dark) for the "See it in action" section. */
const TOUR = [
    {
        key: 'plans',
        label: 'Gantt timelines',
        icon: GanttChart,
        title: 'See the whole project on one timeline',
        points: ['Phases, milestones and dependencies', 'Drag bars, paste rows straight from Excel', 'Rows linked to board issues stay in sync'],
        image: '/images/screenshots/plans.jpg',
        imageDark: '/images/screenshots/plans-dark.jpg',
    },
    {
        key: 'boards',
        label: 'Boards & Scrum',
        icon: FolderKanban,
        title: 'Run the work on Kanban or Scrum boards',
        points: ['Sprints, backlog, burndown and velocity', 'WIP limits, quick filters, time tracking', 'Full history on every issue'],
        image: '/images/screenshots/board.jpg',
        imageDark: '/images/screenshots/board-dark.jpg',
    },
    {
        key: 'docs',
        label: 'Docs & wiki',
        icon: FileText,
        title: 'Write it down in calm, focused docs',
        points: ['Floating toolbar and slash commands', 'Drafts stay private until you publish', 'Every version kept — restore any time'],
        image: '/images/screenshots/docs.jpg',
        imageDark: '/images/screenshots/docs-dark.jpg',
    },
    {
        key: 'whiteboards',
        label: 'Whiteboards',
        icon: Palette,
        title: 'Workshop on a whiteboard, keep the outcome',
        points: ['Retro, roadmap, story map and more templates', 'Live issue cards on the canvas', 'Turn sticky notes into issues in one click'],
        image: '/images/screenshots/whiteboard.jpg',
        imageDark: '/images/screenshots/whiteboard.jpg',
    },
] as const;

function ProductTour() {
    const [active, setActive] = useState<(typeof TOUR)[number]['key']>('plans');
    const current = TOUR.find((t) => t.key === active) ?? TOUR[0];

    return (
        <section id="tour" className="relative z-10 py-20 sm:py-28">
            <div className="max-w-6xl mx-auto px-6">
                <RevealSection className="text-center mb-10">
                    <div className="inline-flex items-center gap-2 rounded-full border border-[#0E83DC]/20 bg-[#0E83DC]/5 px-4 py-1.5 text-xs font-semibold text-[#0E83DC] mb-4">
                        <MonitorPlay className="h-3.5 w-3.5" />
                        See it in action
                    </div>
                    <h2 className="text-3xl sm:text-4xl font-bold text-[#485E72] dark:text-slate-100 mb-4">
                        From the first workshop to the last release
                    </h2>
                    <p className="text-[#485E72]/60 dark:text-slate-400 max-w-xl mx-auto text-lg">
                        Plan on a timeline, run sprints on boards, write it down in docs &mdash; all connected.
                    </p>
                </RevealSection>

                <RevealSection>
                    <div className="flex flex-wrap justify-center gap-2 mb-8" role="tablist" aria-label="Product tour">
                        {TOUR.map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                role="tab"
                                aria-selected={active === tab.key}
                                aria-controls="tour-panel"
                                onClick={() => setActive(tab.key)}
                                className={`inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-all ${active === tab.key
                                    ? 'bg-[#0E83DC] text-white shadow-lg shadow-[#0E83DC]/25'
                                    : 'bg-card/70 text-[#485E72]/70 dark:text-slate-300 border border-border/60 hover:text-[#0E83DC] hover:border-[#0E83DC]/30'
                                    }`}
                            >
                                <tab.icon className="h-4 w-4" />
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    <div id="tour-panel" role="tabpanel" className="grid lg:grid-cols-[1fr_280px] gap-8 items-center">
                        {/* Browser frame */}
                        <div className="rounded-2xl border border-border/70 bg-card shadow-2xl shadow-[#485E72]/15 dark:shadow-black/40 overflow-hidden">
                            <div className="flex items-center gap-1.5 border-b border-border/60 bg-muted/50 px-4 py-2.5">
                                <span className="h-2.5 w-2.5 rounded-full bg-[#ff5f57]" />
                                <span className="h-2.5 w-2.5 rounded-full bg-[#febc2e]" />
                                <span className="h-2.5 w-2.5 rounded-full bg-[#28c840]" />
                                <span className="ml-3 truncate rounded-md bg-background/80 px-3 py-0.5 text-[11px] text-[#485E72]/60 dark:text-slate-400">
                                    www.brainitive.app &middot; {current.label}
                                </span>
                            </div>
                            <div className="relative aspect-[1440/860] bg-background">
                                {TOUR.map((tab) => (
                                    <div key={tab.key} className={`absolute inset-0 transition-opacity duration-500 ${active === tab.key ? 'opacity-100' : 'opacity-0 pointer-events-none'}`} aria-hidden={active !== tab.key}>
                                        <img src={tab.image} alt={`${tab.label} in Brainitive`} loading="lazy" decoding="async" width={1440} height={860} className="h-full w-full object-cover object-top dark:hidden" />
                                        <img src={tab.imageDark} alt={`${tab.label} in Brainitive`} loading="lazy" decoding="async" width={1440} height={860} className="hidden h-full w-full object-cover object-top dark:block" />
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div>
                            <h3 className="text-xl font-bold text-[#485E72] dark:text-slate-100 mb-4">{current.title}</h3>
                            <ul className="space-y-3">
                                {current.points.map((point) => (
                                    <li key={point} className="flex items-start gap-3 text-[#485E72]/70 dark:text-slate-300">
                                        <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#69A748]/15 text-[#69A748]">
                                            <Check className="h-3 w-3" strokeWidth={3} />
                                        </span>
                                        <span className="text-sm leading-relaxed">{point}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </RevealSection>
            </div>
        </section>
    );
}

const EXTENSION_DOWNLOAD = 'https://github.com/armendzekjiri/brainitive/releases/latest/download';
/** The Chrome Web Store listing (Edge installs Chrome Web Store extensions too). */
export const CHROME_STORE_URL = 'https://chromewebstore.google.com/detail/brainitive/mnnkcbjdgphgiiapdfegpgdmjekofmjn';

/** Browser extension: what it does, the Chrome Web Store listing, and the Firefox download until it's on AMO. */
function ExtensionSection() {
    const features = [
        { icon: MousePointerClick, title: 'Issue from any page', text: 'Link, selected text and a screenshot of an area, in two clicks.' },
        { icon: Bookmark, title: 'Bookmarks & docs', text: 'Save links, clip an article as a clean doc, or send the page to your capture inbox.' },
        { icon: Code2, title: 'Snippets', text: 'Right-click selected code: saved with its language.' },
        { icon: LayoutDashboard, title: 'My Work alongside', text: 'Your assigned issues and tasks in the side panel; “bt” searches from the address bar.' },
    ];

    return (
        <section id="extension" className="relative z-10 px-6 py-24 sm:py-28">
            <RevealSection className="max-w-6xl mx-auto grid lg:grid-cols-[1.1fr_1fr] gap-10 items-center">
                <div>
                    <div className="inline-flex items-center gap-2 rounded-full border border-[#0E83DC]/20 bg-[#0E83DC]/5 px-4 py-1.5 text-xs font-semibold text-[#0E83DC] mb-4">
                        <Puzzle className="h-3.5 w-3.5" />
                        Browser extension
                    </div>
                    <h2 className="text-3xl sm:text-4xl font-bold text-[#485E72] dark:text-slate-100 mb-4">Save anything from your browser</h2>
                    <p className="text-[#485E72]/60 dark:text-slate-400 text-lg mb-8 max-w-xl">
                        For Chrome, Edge and Firefox. Works with Brainitive Cloud and your own server: you sign in on your instance, and the extension can only save things and search titles.
                    </p>
                    <div className="grid sm:grid-cols-2 gap-4">
                        {features.map((f) => (
                            <div key={f.title} className="flex gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#0E83DC]/10 text-[#0E83DC]">
                                    <f.icon className="h-4 w-4" />
                                </div>
                                <div>
                                    <p className="font-semibold text-[#485E72] dark:text-slate-100 text-sm">{f.title}</p>
                                    <p className="text-sm text-[#485E72]/60 dark:text-slate-400">{f.text}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="bg-white/70 dark:bg-slate-900/60 backdrop-blur-sm border border-[#C0C5C0]/30 dark:border-slate-700/60 rounded-2xl p-6 sm:p-8">
                    <p className="font-semibold text-[#485E72] dark:text-slate-100 mb-1">Get the extension</p>
                    <p className="text-sm text-[#485E72]/60 dark:text-slate-400 mb-5">Free in the Chrome Web Store — it works in Microsoft Edge too.</p>
                    <a
                        href={CHROME_STORE_URL}
                        target="_blank"
                        rel="noopener"
                        className="inline-flex items-center gap-2 rounded-xl bg-[#0E83DC] px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-[#0E83DC]/20 hover:bg-[#0b6fbf]"
                    >
                        <Puzzle className="h-4 w-4" /> Add to Chrome
                    </a>
                    <p className="mt-2 text-xs text-[#485E72]/60 dark:text-slate-400">Chrome, Edge, Brave and other Chromium browsers · then pin Brainitive from the puzzle icon</p>

                    <div className="mt-6 border-t border-[#C0C5C0]/30 dark:border-slate-700/60 pt-5">
                        <p className="text-sm font-semibold text-[#485E72] dark:text-slate-100 mb-1">Firefox</p>
                        <p className="text-sm text-[#485E72]/60 dark:text-slate-400 mb-3">
                            Until it’s on Firefox Add-ons: download it, open <code className="rounded bg-[#485E72]/10 px-1">about:debugging</code> → This Firefox → <strong>Load Temporary Add-on</strong> and pick the zip.
                        </p>
                        <a href={`${EXTENSION_DOWNLOAD}/brainitive-extension-firefox.zip`} className="inline-flex items-center gap-2 rounded-xl border border-[#C0C5C0]/60 dark:border-slate-600 px-4 py-2 text-sm font-semibold text-[#485E72] dark:text-slate-200 hover:bg-white dark:hover:bg-slate-800">
                            <Download className="h-4 w-4" /> Firefox download
                        </a>
                    </div>
                </div>
            </RevealSection>
        </section>
    );
}

/** The CLI (v1.47.0): one-line install, the everyday commands, and the MCP bridge for local AI tools. */
function CliSection() {
    const lines: { prompt?: boolean; text: string }[] = [
        { prompt: true, text: 'curl -fsSL https://brainitive.app/install.sh | sh' },
        { text: 'Installed brainitive 1.47.0 to /usr/local/bin/brainitive' },
        { prompt: true, text: 'brainitive login' },
        { text: 'Signed in to https://brainitive.app. 1 workspace(s).' },
        { prompt: true, text: 'brainitive capture "ask Dana about the Q4 budget"' },
        { text: 'Captured ask Dana about the Q4 budget  (Captures)' },
        { prompt: true, text: 'brainitive issue create Crash on save --type Bug --screenshot shot.png' },
        { text: 'Created SHOP-42 with screenshot  (Acme / Shop / Main Board)' },
        { prompt: true, text: 'claude mcp add brainitive -- brainitive mcp' },
    ];
    const features = [
        { icon: Download, title: 'One command to install', text: 'macOS, Linux and Windows. A single file, nothing else to set up — Cloud or your own server.' },
        { icon: Inbox, title: 'Capture from anywhere', text: 'A thought, a link, your clipboard or a file goes straight to your inbox to sort later.' },
        { icon: Code2, title: 'Issues, tasks, snippets, docs', text: 'Create issues with a screenshot, add tasks, save snippets and bookmarks, publish Markdown as a doc, search everything.' },
        { icon: Bot, title: 'AI agents included', text: '“brainitive mcp” gives Claude Code, Cursor, Codex and co. your whole workspace — and every server tool is one command away.' },
    ];

    return (
        <section id="cli" className="relative z-10 px-6 py-24 sm:py-28">
            <RevealSection className="max-w-6xl mx-auto grid lg:grid-cols-[1fr_1.1fr] gap-10 items-center">
                <div className="rounded-2xl border border-[#C0C5C0]/30 dark:border-slate-700/60 bg-[#1f2a36] text-slate-100 shadow-xl shadow-[#485E72]/10 overflow-hidden order-2 lg:order-1">
                    <div className="flex items-center gap-1.5 px-4 py-2.5 border-b border-white/10 bg-white/5">
                        <span className="h-2.5 w-2.5 rounded-full bg-[#ff5f57]" />
                        <span className="h-2.5 w-2.5 rounded-full bg-[#febc2e]" />
                        <span className="h-2.5 w-2.5 rounded-full bg-[#28c840]" />
                        <span className="ml-3 text-xs text-slate-400">Terminal</span>
                    </div>
                    <pre className="p-5 text-[13px] leading-6 overflow-x-auto font-mono">
                        {lines.map((l, i) => (
                            <div key={i} className={l.prompt ? 'text-white' : 'text-slate-400'}>
                                {l.prompt ? <span className="text-[#69A748]">$ </span> : '  '}
                                {l.text}
                            </div>
                        ))}
                    </pre>
                </div>
                <div className="order-1 lg:order-2">
                    <div className="inline-flex items-center gap-2 rounded-full border border-[#485E72]/20 bg-[#485E72]/5 px-4 py-1.5 text-xs font-semibold text-[#485E72] dark:text-slate-300 mb-4">
                        <Terminal className="h-3.5 w-3.5" />
                        Command line
                    </div>
                    <h2 className="text-3xl sm:text-4xl font-bold text-[#485E72] dark:text-slate-100 mb-4">Brainitive in your terminal</h2>
                    <p className="text-[#485E72]/60 dark:text-slate-400 text-lg mb-8 max-w-xl">
                        For people who live in a terminal — and for the AI agents that do. Sign in through your browser once; the CLI then talks to the same server your connectors use.
                    </p>
                    <div className="grid sm:grid-cols-2 gap-4">
                        {features.map((f) => (
                            <div key={f.title} className="flex gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#485E72]/10 text-[#485E72] dark:text-slate-300">
                                    <f.icon className="h-4 w-4" />
                                </div>
                                <div>
                                    <p className="font-semibold text-[#485E72] dark:text-slate-100 text-sm">{f.title}</p>
                                    <p className="text-sm text-[#485E72]/60 dark:text-slate-400">{f.text}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                    <a
                        href={`${DOCS_URL}/integrations/cli`}
                        className="mt-8 inline-flex items-center gap-2 rounded-xl border border-[#C0C5C0]/60 dark:border-slate-600 px-4 py-2 text-sm font-semibold text-[#485E72] dark:text-slate-200 hover:bg-white dark:hover:bg-slate-800"
                    >
                        CLI docs <ArrowRight className="h-4 w-4" />
                    </a>
                </div>
            </RevealSection>
        </section>
    );
}

export default function Welcome({
    auth,
    canLoginWithGoogle,
    canLoginWithGitHub,
    canLoginWithMicrosoft,
}: PageProps<{
    laravelVersion?: string;
    phpVersion?: string;
    canLoginWithGoogle?: boolean;
    canLoginWithGitHub?: boolean;
    canLoginWithMicrosoft?: boolean;
}>) {
    const [scrolled, setScrolled] = useState(false);
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const { resolvedTheme, toggleTheme } = useTheme();

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 20);
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    /*
     * Feature cards, grouped. Keep groups at 6 cards (3 also works) so the 3-column grid stays
     * even; when one gets too long, split it into a new group rather than a 7th card.
     * A release adds a card for each new module to the matching group with `isNew: true` (green
     * "New" pill) and removes `isNew` from cards a few releases old. Only advertise what is built.
     * Group colors alternate brand blue / green (slate #485E72 is unreadable in dark mode).
     */
    const featureGroups: {
        title: string;
        tagline: string;
        icon: LucideIcon;
        color: string;
        items: { icon: LucideIcon; title: string; description: string; isNew?: boolean }[];
    }[] = [
        {
            title: 'Plan & organize',
            tagline: 'Keep every team, project, and task moving.',
            icon: FolderKanban,
            color: '#0E83DC',
            items: [
                {
                    icon: LayoutTemplate,
                    title: 'Project templates',
                    description:
                        'Start from 20 templates — software, marketing, HR, sales and more — or save your own project as one.',
                    isNew: true,
                },
                {
                    icon: FolderKanban,
                    title: 'Boards, Kanban & Scrum',
                    description:
                        'Kanban or Scrum with sprints, backlog and burndown — plus swimlanes, WIP limits, history and time tracking.',
                },
                {
                    icon: ListFilter,
                    title: 'Saved board views',
                    description:
                        'Save filters, layout and sorting as a named view — keep it to yourself or share it with the board.',
                    isNew: true,
                },
                {
                    icon: SlidersHorizontal,
                    title: 'Custom fields',
                    description:
                        'Add text, number, date, yes/no or option fields to issues — then filter, sort and show them as columns.',
                    isNew: true,
                },
                {
                    icon: ListChecks,
                    title: 'Bulk edit',
                    description:
                        'Select issues in the list view and change status, assignee, priority, sprint, due date or labels in one go.',
                    isNew: true,
                },
                {
                    icon: PanelLeft,
                    title: 'Your sidebar, your way',
                    description:
                        'Hide what you don’t use, choose what each project shows and pin the boards, lists and docs you open every day.',
                    isNew: true,
                },
                {
                    icon: Repeat,
                    title: 'Recurring issues',
                    description:
                        'Weekly reviews, monthly reports, yearly renewals — issues that create themselves on schedule, with assignee and due date.',
                    isNew: true,
                },
                {
                    icon: GanttChart,
                    title: 'Gantt timelines',
                    description:
                        'Phases, milestones and dependencies on a timeline. Start in a workshop, then link rows to real issues.',
                },
                {
                    icon: Rocket,
                    title: 'Releases & release notes',
                    description:
                        'Plan fix versions with live progress, then publish release notes to a public page and changelog.',
                    isNew: true,
                },
            ],
        },
        {
            title: 'Capture & focus',
            tagline: 'Catch every idea, then focus on what matters today.',
            icon: Inbox,
            color: '#69A748',
            items: [
                {
                    icon: Inbox,
                    title: 'Quick capture',
                    description:
                        'Press C anywhere to drop an idea into your private inbox, then turn it into the right thing later.',
                    isNew: true,
                },
                {
                    icon: Sun,
                    title: 'My Day & focus timer',
                    description:
                        'Pick today’s tasks from boards and lists, then run a focus timer that logs the time on the issue.',
                    isNew: true,
                },
                {
                    icon: Briefcase,
                    title: 'My Work',
                    description:
                        'One view of everything assigned to you, across all workspaces and projects.',
                },
                {
                    icon: ListChecks,
                    title: 'Task lists',
                    description:
                        'To-do lists for the work that doesn’t need a full board — due dates, repeats, and reminders before it’s due.',
                },
                {
                    icon: CalendarDays,
                    title: 'Calendar sync',
                    description:
                        'Google, Outlook / Microsoft 365, iCloud and any CalDAV calendar — or a read-only ICS link — next to your work; reschedule in place.',
                    isNew: true,
                },
                {
                    icon: CalendarClock,
                    title: 'Booking pages',
                    description:
                        'Share a link and let people book a time with you: your hours minus what’s in your calendars, your own meeting types, invites with Meet or Teams.',
                    isNew: true,
                },
                {
                    icon: CalendarSync,
                    title: 'Calendar feed',
                    description:
                        'Your due dates, tasks, My Day and focus sessions in Apple Calendar, Google Calendar or Outlook through a private link.',
                    isNew: true,
                },
                {
                    icon: Command,
                    title: 'Command palette',
                    description:
                        'Press ⌘K to search everything, jump back to recent items and run commands from the keyboard.',
                    isNew: true,
                },
            ],
        },
        {
            title: 'Create & write',
            tagline: 'Documents, diagrams, and reusable content.',
            icon: FileText,
            color: '#0E83DC',
            items: [
                {
                    icon: UsersRound,
                    title: 'Live co-editing',
                    description:
                        'Write a document together in real time — everyone’s cursor and changes appear instantly, and it keeps working offline.',
                    isNew: true,
                },
                {
                    icon: FileText,
                    title: 'Wiki & docs',
                    description:
                        'Calm, distraction-free pages with drafts, publishing, version history and restore.',
                },
                {
                    icon: PenLine,
                    title: 'Rich editors',
                    description:
                        'Tables, code blocks, math, embeds, and slash commands at your fingertips.',
                },
                {
                    icon: Palette,
                    title: 'Whiteboards',
                    description:
                        'Excalidraw with templates, autosave and history — turn sticky notes into board issues.',
                },
                {
                    icon: Link2,
                    title: 'Smart links',
                    description:
                        'Paste a link to an issue, doc, ticket or plan and it shows live — status, due date and assignee — as a chip or a card.',
                    isNew: true,
                },
                {
                    icon: BadgeCheck,
                    title: 'Document review',
                    description:
                        'Ask teammates to review a draft: comments on selected text, approve or request changes, and optional review before publish.',
                    isNew: true,
                },
                {
                    icon: Network,
                    title: 'Related content & backlinks',
                    description:
                        'Link issues, docs, whiteboards, tickets and plans — and see what references each one.',
                    isNew: true,
                },
                {
                    icon: Code2,
                    title: 'Snippets',
                    description:
                        'Reusable text and code snippets with search — quick-insert anywhere.',
                },
                {
                    icon: Bookmark,
                    title: 'Bookmarks',
                    description:
                        'Save links and resources into collections, with previews fetched for you.',
                },
            ],
        },
        {
            title: 'Collaborate & share',
            tagline: 'Bring the right people in, control what they see.',
            icon: Users,
            color: '#69A748',
            items: [
                {
                    icon: LayoutDashboard,
                    title: 'Workspaces',
                    description:
                        'Group teams and projects into dedicated workspaces with their own members.',
                },
                {
                    icon: Users,
                    title: 'Roles & permissions',
                    description:
                        'Admin, editor, and viewer roles so the right people get the right access.',
                },
                {
                    icon: Eye,
                    title: 'Watchers',
                    description:
                        'Follow items, pages, lists, and tickets to stay notified of every change.',
                },
                {
                    icon: Share2,
                    title: 'Publish & share',
                    description:
                        'Keep content internal or generate public links for pages, snippets, and bookmarks.',
                },
                {
                    icon: Video,
                    title: 'Meetings: Google Meet & Teams',
                    description:
                        'Schedule a meeting from a card, doc or the calendar — invitations from your own calendar, Join right on the card, notes in one click.',
                    isNew: true,
                },
                {
                    icon: Link2,
                    title: 'Share links',
                    description:
                        'Share a single doc or issue read-only with anyone — links can expire and be revoked any time.',
                    isNew: true,
                },
                {
                    icon: CircleUser,
                    title: 'Profiles & avatars',
                    description:
                        'Profile photos for members, shown on cards, comments, and assignments.',
                },
            ],
        },
        {
            title: 'Customers, leads & billing',
            tagline: 'Support customers, win new ones and bill them — on the boards you already use.',
            icon: Headset,
            color: '#0E83DC',
            items: [
                {
                    icon: Briefcase,
                    title: 'Client portal',
                    description:
                        'Share chosen doc versions and plan snapshots with clients; they approve or request changes on the exact version and comment.',
                    isNew: true,
                },
                {
                    icon: Lightbulb,
                    title: 'Feedback portal',
                    description:
                        'A public page where customers post ideas, vote and follow your roadmap — you moderate, merge duplicates and turn ideas into cards.',
                    isNew: true,
                },
                {
                    icon: LifeBuoy,
                    title: 'Helpdesk portal',
                    description:
                        'An embeddable support widget, portal and support page for customer tickets and replies.',
                },
                {
                    icon: ClipboardList,
                    title: 'Helpdesk templates',
                    description:
                        '17 templates — IT helpdesk, bug reports, returns, registrations and more — each with its own columns and fields.',
                    isNew: true,
                },
                {
                    icon: MailOpen,
                    title: 'Email to helpdesk',
                    description:
                        'Connect your support mailbox or forward to Brainitive: emails become tickets and replies stay in the same thread.',
                    isNew: true,
                },
                {
                    icon: Timer,
                    title: 'Helpdesk SLAs',
                    description:
                        'First-response and resolution targets per priority, business hours, warnings before a breach and breach reports.',
                    isNew: true,
                },
                {
                    icon: Magnet,
                    title: 'Lead forms',
                    description:
                        'Branded multi-step forms with logic, analytics and webhooks — every lead lands as a card.',
                    isNew: true,
                },
                {
                    icon: Clock,
                    title: 'Time tracking',
                    description:
                        'Log time on tickets with an activity — from the issue, the timer or your weekly timesheet.',
                    isNew: true,
                },
                {
                    icon: FileSpreadsheet,
                    title: 'Team time overview',
                    description:
                        'Last month’s hours by project, ticket, person or activity, with billable share — export to Excel.',
                    isNew: true,
                },
                {
                    icon: ReceiptText,
                    title: 'Client billing',
                    description:
                        'Your own activities and rates, VAT net or gross, PDF reports or invoices sent to the client — nothing billed twice.',
                    isNew: true,
                },
            ],
        },
        {
            title: 'Insight & planning',
            tagline: 'Know where a project stands and who has room — from real work, not guesses.',
            icon: HeartPulse,
            color: '#69A748',
            items: [
                {
                    icon: HeartPulse,
                    title: 'Project health',
                    description:
                        'Overdue, due soon, blocked and unassigned work at a glance — every number links to its issues. Save dated status updates.',
                    isNew: true,
                },
                {
                    icon: Gauge,
                    title: 'Workload planning',
                    description:
                        'Weekly capacity per person next to planned hours from real issues, with overload shown in hours.',
                    isNew: true,
                },
                {
                    icon: NotebookPen,
                    title: 'Meeting notes → issues',
                    description:
                        'AI suggests the action items with the sentence each came from — review, edit, then create only the ones you want.',
                    isNew: true,
                },
                {
                    icon: ChartColumn,
                    title: 'Admin insights',
                    description:
                        'Throughput, cycle time, team activity, project health, helpdesk and usage across the whole workspace.',
                    isNew: true,
                },
            ],
        },
        {
            title: 'Connect & automate',
            tagline: 'Plug in AI and GitHub, stay in the loop, sign in your way.',
            icon: Bot,
            color: '#0E83DC',
            items: [
                {
                    icon: Bot,
                    title: 'AI agents (MCP)',
                    description:
                        'Connect ChatGPT or Claude over MCP to work with issues, docs, sprints and plans — or hand it a Jira export and let it set up the whole project: columns, types, fields, members and cards.',
                    isNew: true,
                },
                {
                    icon: Terminal,
                    title: 'Command line (CLI)',
                    description:
                        'Install with one command on macOS, Linux or Windows. Capture, issues with screenshots, tasks, snippets, docs and search from the terminal; an MCP bridge for AI tools on your machine.',
                    isNew: true,
                },
                {
                    icon: Sparkles,
                    title: 'In-app AI',
                    description:
                        'Bring your own AI provider for writing in docs and issues — with your project as context.',
                },
                {
                    icon: GitBranch,
                    title: 'GitHub development',
                    description:
                        'Connect GitHub in one click; branches, commits, pull requests and deployments show up on the issue — and a merge can move the card.',
                    isNew: true,
                },
                {
                    icon: Bell,
                    title: 'Notifications & push',
                    description:
                        'A grouped inbox for comments, mentions, due dates, sprints and assignments — choose in-app, push or email per category.',
                    isNew: true,
                },
                {
                    icon: Webhook,
                    title: 'Outgoing webhooks',
                    description:
                        'Send signed events — issues, pages, sprints, tickets, leads, time and invoices — to your own systems, with a delivery log.',
                    isNew: true,
                },
                {
                    icon: Workflow,
                    title: 'Workflow automation',
                    description:
                        'Rules per project: when something happens and it matches, assign, move, set fields, comment or notify — with a run log.',
                    isNew: true,
                },
                {
                    icon: PackageOpen,
                    title: 'Import from Trello, Jira & Notion',
                    description:
                        'Upload an export, check the preview, map statuses, types and people — your boards, issues and docs arrive ready to work on.',
                    isNew: true,
                },
                {
                    icon: Archive,
                    title: 'Project export & restore',
                    description:
                        'Download a whole project — boards, docs, plans and files — as one archive, and restore it here or on another Brainitive.',
                    isNew: true,
                },
                {
                    icon: KeyRound,
                    title: 'Social login',
                    description:
                        'Sign in with email, Google, GitHub or Microsoft — whatever your team prefers.',
                    isNew: true,
                },
                {
                    icon: Search,
                    title: 'Find anything',
                    description:
                        'One ranked, typo-tolerant search across issues, docs, comments, tickets, tasks, plans, snippets and files, with filters — also in ⌘K.',
                    isNew: true,
                },
                {
                    icon: WifiOff,
                    title: 'Offline mode',
                    description:
                        'My Day, task lists, issues and quick capture keep working without internet and sync when you’re back — conflicts shown, never overwritten.',
                    isNew: true,
                },
                {
                    icon: Download,
                    title: 'Install as an app',
                    description:
                        'Install Brainitive on any device — desktop or phone — straight from the browser.',
                },
            ],
        },
    ];

    const stats = [
        { value: '100%', label: 'Open source' },
        { value: '∞', label: 'Projects & pages' },
        { value: '<50ms', label: 'UI response time' },
        { value: '99.9%', label: 'Uptime SLA' },
    ];

    return (
        <>
            <Head title="Brainitive – Workspaces, Boards & Wiki" />
            <div className="min-h-screen overflow-x-hidden bg-background text-[#485E72] dark:text-slate-100">
                {/* ═══ Ambient background blobs ═══ */}
                <div className="fixed inset-0 pointer-events-none overflow-hidden">
                    <div
                        className="absolute -top-32 -left-32 w-[700px] h-[700px] rounded-full bg-[#0E83DC]/[0.06] blur-[120px] animate-pulse-slow"
                    />
                    <div
                        className="absolute top-1/3 -right-48 w-[600px] h-[600px] rounded-full bg-[#69A748]/[0.05] blur-[100px] animate-pulse-slow"
                        style={{ animationDelay: '2s' }}
                    />
                    <div
                        className="absolute -bottom-40 left-1/3 w-[500px] h-[500px] rounded-full bg-[#0E83DC]/[0.04] blur-[100px] animate-pulse-slow"
                        style={{ animationDelay: '4s' }}
                    />
                </div>

                {/* ═══ Sticky Nav ═══ */}
                <nav
                    className={`fixed top-0 left-0 right-0 z-50 transition-all duration-300 ${scrolled
                            ? 'bg-card/80 backdrop-blur-xl shadow-[0_1px_3px_rgba(72,94,114,0.08)] dark:shadow-[0_1px_3px_rgba(0,0,0,0.3)] border-b border-border/70'
                            : 'bg-transparent'
                        }`}
                >
                    <div className="max-w-6xl mx-auto flex items-center justify-between px-6 py-4">
                        <Link
                            href="/"
                            className="flex items-center gap-2.5 group"
                        >
                            <ApplicationLogo
                                className="h-9 w-auto object-contain transition-transform group-hover:scale-105"
                                alt="Brainitive"
                                priority
                            />
                        </Link>

                        {/* Desktop links */}
                        <div className="hidden md:flex items-center gap-5">
                            <a
                                href="#features"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC] transition-colors"
                            >
                                <Layers className="h-3.5 w-3.5" />
                                Features
                            </a>
                            <a
                                href="#tour"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC] transition-colors"
                            >
                                <MonitorPlay className="h-3.5 w-3.5" />
                                Tour
                            </a>
                            <a
                                href="#pricing"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC] transition-colors"
                            >
                                <CreditCard className="h-3.5 w-3.5" />
                                Pricing
                            </a>
                            <Link
                                href="/privacy"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC] transition-colors"
                            >
                                <ShieldCheck className="h-3.5 w-3.5" />
                                Privacy
                            </Link>
                            <Link
                                href="/terms"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC] transition-colors"
                            >
                                <Scale className="h-3.5 w-3.5" />
                                Terms
                            </Link>
                        </div>

                        <div className="flex items-center gap-3">
                            <GitHubStarButton className="hidden sm:inline-flex" />
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC]"
                                onClick={toggleTheme}
                                title={resolvedTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                            >
                                {resolvedTheme === 'dark' ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
                            </Button>
                            {auth?.user ? (
                                <Link
                                    href={route('dashboard')}
                                    className="inline-flex items-center gap-2 rounded-xl bg-[#0E83DC] px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-[#0E83DC]/20 hover:bg-[#0b6fbf] transition-all hover:shadow-xl hover:shadow-[#0E83DC]/25"
                                >
                                    Dashboard
                                    <ArrowRight className="h-4 w-4" />
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={route('login')}
                                        className="hidden sm:inline text-sm font-semibold text-[#485E72] dark:text-slate-100 hover:text-[#0E83DC] transition-colors"
                                    >
                                        Log in
                                    </Link>
                                    <Link
                                        href={route('register')}
                                        className="inline-flex items-center gap-2 rounded-xl bg-[#0E83DC] px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-[#0E83DC]/20 hover:bg-[#0b6fbf] transition-all hover:shadow-xl hover:shadow-[#0E83DC]/25"
                                    >
                                        Get started
                                        <ChevronRight className="h-4 w-4" />
                                    </Link>
                                </>
                            )}

                            {/* Mobile menu toggle */}
                            <button
                                type="button"
                                className="md:hidden p-2 text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC] transition-colors"
                                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                                aria-label="Toggle menu"
                            >
                                {mobileMenuOpen ? (
                                    <X className="h-5 w-5" />
                                ) : (
                                    <Menu className="h-5 w-5" />
                                )}
                            </button>
                        </div>
                    </div>

                    {/* Mobile dropdown */}
                    {mobileMenuOpen && (
                        <div className="md:hidden bg-card/95 backdrop-blur-xl border-b border-border/60 px-6 py-4 space-y-3">
                            <a
                                href="#features"
                                onClick={() => setMobileMenuOpen(false)}
                                className="flex items-center gap-2 text-sm font-medium text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC]"
                            >
                                <Layers className="h-3.5 w-3.5" />
                                Features
                            </a>
                            <a
                                href="#tour"
                                onClick={() => setMobileMenuOpen(false)}
                                className="flex items-center gap-2 text-sm font-medium text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC]"
                            >
                                <MonitorPlay className="h-3.5 w-3.5" />
                                Tour
                            </a>
                            <a
                                href="#pricing"
                                onClick={() => setMobileMenuOpen(false)}
                                className="flex items-center gap-2 text-sm font-medium text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC]"
                            >
                                <CreditCard className="h-3.5 w-3.5" />
                                Pricing
                            </a>
                            <Link
                                href="/privacy"
                                className="flex items-center gap-2 text-sm font-medium text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC]"
                            >
                                <ShieldCheck className="h-3.5 w-3.5" />
                                Privacy
                            </Link>
                            <Link
                                href="/terms"
                                className="flex items-center gap-2 text-sm font-medium text-[#485E72]/70 dark:text-slate-300 hover:text-[#0E83DC]"
                            >
                                <Scale className="h-3.5 w-3.5" />
                                Terms
                            </Link>
                            {!auth?.user && (
                                <Link
                                    href={route('login')}
                                    className="block text-sm font-medium text-[#485E72] dark:text-slate-100 hover:text-[#0E83DC]"
                                >
                                    Log in
                                </Link>
                            )}
                        </div>
                    )}
                </nav>

                {/* ═══ Hero ═══ */}
                <header className="relative z-10 pt-32 sm:pt-40 pb-20 sm:pb-28 px-6">
                    <div className="max-w-4xl mx-auto text-center">
                        {/* Badge */}
                        <div className="inline-flex items-center gap-2 rounded-full border border-[#0E83DC]/20 bg-card/70 backdrop-blur-sm px-4 py-1.5 text-xs font-semibold text-[#0E83DC] mb-8 animate-fade-in shadow-sm">
                            <Sparkles className="h-3.5 w-3.5" />
                            Open source &middot; All-in-one workspace
                        </div>
                        <div className="-mt-4 mb-8 flex justify-center animate-fade-in">
                            <GitHubStarButton variant="badge" />
                        </div>



                        <h1 className="text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-bold tracking-tight text-[#485E72] dark:text-slate-100 animate-fade-in-up leading-[1.1]">
                            Your workspace for{' '}
                            <span className="relative inline-block">
                                <span className="bg-gradient-to-r from-[#0E83DC] to-[#69A748] bg-clip-text text-transparent">
                                    projects, boards
                                </span>
                                <svg
                                    viewBox="0 0 200 12"
                                    preserveAspectRatio="none"
                                    className="absolute -bottom-1 left-0 w-full h-3"
                                    aria-hidden
                                >
                                    <path
                                        d="M2 8 Q 25 2, 50 8 T 100 8 T 150 8 T 198 8"
                                        stroke="#0E83DC"
                                        strokeWidth="2.5"
                                        fill="none"
                                        strokeLinecap="round"
                                        strokeOpacity="0.4"
                                    />
                                </svg>
                            </span>{' '}
                            <span className="bg-gradient-to-r from-[#69A748] to-[#0E83DC] bg-clip-text text-transparent">
                                &amp; docs
                            </span>
                        </h1>

                        <p
                            className="mt-6 text-lg sm:text-xl text-[#485E72]/70 dark:text-slate-300 max-w-2xl mx-auto animate-fade-in-up leading-relaxed"
                            style={{ animationDelay: '0.15s' }}
                        >
                            Boards, sprints, docs, whiteboards and Gantt timelines &mdash;
                            one app for the whole project, from the first workshop to the
                            last release.
                        </p>

                        <div
                            className="mt-10 flex flex-col items-center justify-center gap-4 animate-fade-in-up"
                            style={{ animationDelay: '0.25s' }}
                        >
                            {!auth?.user && (
                                <>
                                    <div className="flex flex-wrap items-center justify-center gap-4">
                                        <Link
                                            href={route('register')}
                                            className="group inline-flex items-center gap-2 rounded-2xl bg-[#0E83DC] px-7 py-4 text-base font-bold text-white shadow-xl shadow-[#0E83DC]/25 hover:bg-[#0b6fbf] transition-all hover:scale-[1.02] hover:shadow-2xl hover:shadow-[#0E83DC]/30"
                                        >
                                            Start for free
                                            <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
                                        </Link>
                                        <Link
                                            href={route('login')}
                                            className="inline-flex items-center rounded-2xl border-2 border-border bg-card/60 backdrop-blur-sm px-7 py-4 text-base font-bold text-[#485E72] dark:text-slate-100 hover:border-[#0E83DC]/40 hover:bg-card transition-all"
                                        >
                                            Log in
                                        </Link>
                                    </div>
                                    {(canLoginWithGoogle || canLoginWithGitHub || canLoginWithMicrosoft) && (
                                        <>
                                            <div className="flex items-center gap-3 w-full max-w-xs">
                                                <span className="flex-1 h-px bg-border" />
                                                <span className="text-sm font-medium text-[#485E72]/60 dark:text-slate-400">
                                                    or
                                                </span>
                                                <span className="flex-1 h-px bg-border" />
                                            </div>
                                            <div className="flex flex-wrap items-center justify-center gap-3">
                                                {canLoginWithGoogle && (
                                                    <a
                                                        href={route('login.google')}
                                                        className="inline-flex items-center justify-center gap-3 rounded-2xl border-2 border-border/80 bg-card px-7 py-4 text-base font-bold text-[#485E72] dark:text-slate-100 shadow-lg shadow-black/5 hover:bg-muted hover:border-border transition-all hover:scale-[1.02]"
                                                    >
                                                        <svg
                                                            className="h-6 w-6 shrink-0"
                                                            viewBox="0 0 24 24"
                                                            aria-hidden
                                                        >
                                                            <path
                                                                fill="#4285F4"
                                                                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                                                            />
                                                            <path
                                                                fill="#34A853"
                                                                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                                                            />
                                                            <path
                                                                fill="#FBBC05"
                                                                d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                                                            />
                                                            <path
                                                                fill="#EA4335"
                                                                d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                                                            />
                                                        </svg>
                                                        Continue with Google
                                                    </a>
                                                )}
                                                {canLoginWithGitHub && (
                                                    <a
                                                        href={route('login.github')}
                                                        className="inline-flex items-center justify-center gap-3 rounded-2xl border-2 border-border/80 bg-card px-7 py-4 text-base font-bold text-[#485E72] dark:text-slate-100 shadow-lg shadow-black/5 hover:bg-muted hover:border-border transition-all hover:scale-[1.02]"
                                                    >
                                                        <svg
                                                            className="h-6 w-6 shrink-0"
                                                            viewBox="0 0 24 24"
                                                            fill="currentColor"
                                                            aria-hidden
                                                        >
                                                            <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
                                                        </svg>
                                                        Continue with GitHub
                                                    </a>
                                                )}
                                                {canLoginWithMicrosoft && (
                                                    <a
                                                        href={route('login.microsoft')}
                                                        className="inline-flex items-center justify-center gap-3 rounded-2xl border-2 border-border/80 bg-card px-7 py-4 text-base font-bold text-[#485E72] dark:text-slate-100 shadow-lg shadow-black/5 hover:bg-muted hover:border-border transition-all hover:scale-[1.02]"
                                                    >
                                                        <svg
                                                            className="h-6 w-6 shrink-0"
                                                            viewBox="0 0 21 21"
                                                            aria-hidden
                                                        >
                                                            <rect x="1" y="1" width="9" height="9" fill="#F25022" />
                                                            <rect x="11" y="1" width="9" height="9" fill="#7FBA00" />
                                                            <rect x="1" y="11" width="9" height="9" fill="#00A4EF" />
                                                            <rect x="11" y="11" width="9" height="9" fill="#FFB900" />
                                                        </svg>
                                                        Continue with Microsoft
                                                    </a>
                                                )}
                                            </div>
                                            <TermsNotice action="continuing with Google, GitHub or Microsoft for the first time" className="mt-3 text-center" />
                                        </>
                                    )}
                                </>
                            )}
                            {auth?.user && (
                                <Link
                                    href={route('dashboard')}
                                    className="group inline-flex items-center gap-2 rounded-2xl bg-[#0E83DC] px-7 py-4 text-base font-bold text-white shadow-xl shadow-[#0E83DC]/25 hover:bg-[#0b6fbf] transition-all hover:scale-[1.02]"
                                >
                                    Go to Dashboard
                                    <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
                                </Link>
                            )}
                        </div>
                    </div>

                    {/* Stats strip */}
                    <div
                        className="max-w-3xl mx-auto mt-16 animate-fade-in-up"
                        style={{ animationDelay: '0.35s' }}
                    >
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            {stats.map((s) => (
                                <div
                                    key={s.label}
                                    className="text-center rounded-2xl bg-card/60 backdrop-blur-sm border border-border/60 py-5 px-3 shadow-sm"
                                >
                                    <div className="text-2xl sm:text-3xl font-bold text-[#0E83DC]">
                                        {s.value}
                                    </div>
                                    <div className="text-xs sm:text-sm text-[#485E72]/60 dark:text-slate-400 mt-1 font-medium">
                                        {s.label}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </header>

                {/* ═══ Features ═══ */}
                <section
                    id="features"
                    className="relative z-10 px-6 py-24 sm:py-32"
                >
                    <div className="max-w-6xl mx-auto">
                        <RevealSection className="text-center mb-16">
                            <div className="inline-flex items-center gap-2 rounded-full border border-[#69A748]/20 bg-[#69A748]/5 px-4 py-1.5 text-xs font-semibold text-[#69A748] mb-4">
                                <Globe className="h-3.5 w-3.5" />
                                Features
                            </div>
                            <h2 className="text-3xl sm:text-4xl font-bold text-[#485E72] dark:text-slate-100 mb-4">
                                Everything you need, in one place
                            </h2>
                            <p className="text-[#485E72]/60 dark:text-slate-400 max-w-xl mx-auto text-lg">
                                Capture, plan, write and share &mdash; from quick capture and
                                Scrum boards to wiki pages, lead forms and AI agents.
                            </p>
                        </RevealSection>

                        <WhatsNew />

                        <div className="space-y-14 sm:space-y-16">
                            {featureGroups.map((group) => (
                                <RevealSection key={group.title}>
                                    {/* Group header */}
                                    <div className="flex items-center gap-3 mb-6">
                                        <div
                                            className="flex h-10 w-10 items-center justify-center rounded-xl shrink-0"
                                            style={{
                                                backgroundColor: `${group.color}14`,
                                                color: group.color,
                                            }}
                                        >
                                            <group.icon
                                                className="h-5 w-5"
                                                strokeWidth={1.8}
                                            />
                                        </div>
                                        <div>
                                            <h3 className="text-lg sm:text-xl font-bold text-[#485E72] dark:text-slate-100 leading-tight">
                                                {group.title}
                                            </h3>
                                            <p className="text-sm text-[#485E72]/60 dark:text-slate-400">
                                                {group.tagline}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Group items */}
                                    <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                        {group.items.map((item) => (
                                            <div
                                                key={item.title}
                                                className="group/item h-full rounded-2xl border border-border/60 bg-card/70 backdrop-blur-sm p-5 transition-all duration-300 hover:bg-card hover:shadow-lg hover:shadow-[#485E72]/[0.06] dark:hover:shadow-black/20 hover:border-border/80 hover:-translate-y-0.5"
                                            >
                                                <div className="flex items-center gap-3 mb-2.5">
                                                    <div
                                                        className="flex h-9 w-9 items-center justify-center rounded-lg shrink-0 transition-transform group-hover/item:scale-110"
                                                        style={{
                                                            backgroundColor: `${group.color}12`,
                                                            color: group.color,
                                                        }}
                                                    >
                                                        <item.icon
                                                            className="h-4 w-4"
                                                            strokeWidth={1.8}
                                                        />
                                                    </div>
                                                    <h4 className="font-bold text-[#485E72] dark:text-slate-100 text-[15px] leading-tight">
                                                        {item.title}
                                                    </h4>
                                                    {item.isNew && (
                                                        <span className="ml-auto shrink-0 rounded-full bg-[#69A748]/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-[#69A748]">
                                                            New
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-sm leading-relaxed text-[#485E72]/60 dark:text-slate-400">
                                                    {item.description}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </RevealSection>
                            ))}
                        </div>
                    </div>
                </section>

                {/* ═══ Product tour (screenshots) ═══ */}
                <ProductTour />

                {/* ═══ Visual break / Highlight strip ═══ */}
                <section className="relative z-10 py-20 sm:py-28 overflow-hidden">
                    <div className="absolute inset-0 bg-gradient-to-r from-[#0E83DC] to-[#485E72]" />
                    <div className="absolute inset-0 opacity-50" style={{ backgroundImage: "url(\"data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E\")" }} />
                    <RevealSection className="relative max-w-4xl mx-auto px-6 text-center">
                        <h2 className="text-3xl sm:text-4xl font-bold text-white mb-5">
                            One app. Every workflow.
                        </h2>
                        <p className="text-white/70 text-lg max-w-2xl mx-auto mb-10 leading-relaxed">
                            Stop switching between Notion, Trello, and Google Docs.
                            Brainitive combines Kanban and Scrum boards, a wiki with version
                            history, whiteboards and Gantt timelines &mdash; all connected,
                            in one fast platform.
                        </p>
                        <div className="flex flex-wrap justify-center gap-4">
                            <div className="flex items-center gap-2 bg-white/10 backdrop-blur-sm rounded-xl px-5 py-3 text-white/90 text-sm font-medium">
                                <Shield className="h-4 w-4 text-[#69A748]" />{' '}
                                Role-based access
                            </div>
                            <div className="flex items-center gap-2 bg-white/10 backdrop-blur-sm rounded-xl px-5 py-3 text-white/90 text-sm font-medium">
                                <Zap className="h-4 w-4 text-yellow-400" />{' '}
                                Real-time feel
                            </div>
                            <div className="flex items-center gap-2 bg-white/10 backdrop-blur-sm rounded-xl px-5 py-3 text-white/90 text-sm font-medium">
                                <Palette className="h-4 w-4 text-white/60" />{' '}
                                Excalidraw built-in
                            </div>
                        </div>
                    </RevealSection>
                </section>

                {/* ═══ Browser extension ═══ */}
                <ExtensionSection />

                {/* ═══ CLI ═══ */}
                <CliSection />

                {/* ═══ Pricing ═══ */}
                <PricingSection />

                {/* ═══ CTA ═══ */}
                {!auth?.user && (
                    <section className="relative z-10 px-6 pb-28 sm:pb-36">
                        <RevealSection className="max-w-3xl mx-auto">
                            <div className="relative rounded-3xl overflow-hidden">
                                <div className="absolute inset-0 bg-gradient-to-br from-[#0E83DC] via-[#485E72] to-[#69A748]" />
                                <div className="absolute inset-0 opacity-60" style={{ backgroundImage: "url(\"data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E\")" }} />
                                <div className="relative p-10 sm:p-16 text-center">
                                    <h2 className="text-2xl sm:text-4xl font-bold text-white mb-4">
                                        Ready to get started?
                                    </h2>
                                    <p className="text-white/70 mb-10 text-lg max-w-lg mx-auto">
                                        Create your account and set up your first workspace
                                        in minutes. No credit card required.
                                    </p>
                                    <Link
                                        href={route('register')}
                                        className="group inline-flex items-center gap-2 rounded-2xl bg-card px-8 py-4 text-base font-bold text-[#0E83DC] shadow-xl hover:shadow-2xl transition-all hover:scale-[1.02]"
                                    >
                                        Create free account
                                        <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
                                    </Link>
                                </div>
                            </div>
                        </RevealSection>
                    </section>
                )}

                {/* ═══ Footer ═══ */}
                <footer className="relative z-10 border-t border-border/60 bg-card/40 backdrop-blur-sm">
                    <div className="max-w-6xl mx-auto px-6 py-12">
                        <div className="flex flex-col sm:flex-row items-center justify-between gap-6">
                            <div className="flex items-center gap-3">
                                <ApplicationLogo
                                    className="h-8 w-auto object-contain"
                                    alt="Brainitive"
                                    width={124}
                                    height={32}
                                />
                            </div>
                            <div className="flex flex-wrap items-center justify-center gap-6 text-sm">
                                <a
                                    href="#features"
                                    className="text-[#485E72]/60 dark:text-slate-400 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Features
                                </a>
                                <a
                                    href="#pricing"
                                    className="text-[#485E72]/60 dark:text-slate-400 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Pricing
                                </a>
                                <GitHubStarButton variant="link" className="text-[#485E72]/60 dark:text-slate-400 hover:text-[#0E83DC] transition-colors font-medium" />
                                <a
                                    href={DOCS_URL}
                                    target="_blank"
                                    rel="noopener"
                                    className="text-[#485E72]/60 dark:text-slate-400 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Docs
                                </a>
                                <a
                                    href={SELF_HOSTING_URL}
                                    target="_blank"
                                    rel="noopener"
                                    className="text-[#485E72]/60 dark:text-slate-400 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Self-hosting
                                </a>
                                {/* Imprint · Privacy · Terms · Cookies · Sub-processors (BRAI-156) */}
                                <LegalFooterLinks
                                    className="gap-x-6"
                                    linkClassName="text-[#485E72]/60 dark:text-slate-400 hover:text-[#0E83DC] transition-colors font-medium hover:no-underline"
                                />
                            </div>
                        </div>
                        <div className="mt-8 pt-6 border-t border-border/50 flex flex-col sm:flex-row items-center justify-between gap-4">
                            <p className="text-xs text-[#485E72]/40 dark:text-slate-500">
                                &copy; {new Date().getFullYear()} Brainitive.
                                All rights reserved.
                            </p>
                            <div className="flex items-center gap-1.5">
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
