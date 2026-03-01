import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';
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
    Bookmark,
    Layers,
    LifeBuoy,
    Bell,
    CreditCard,
    ShieldCheck,
    Scale,
    Cookie,
} from 'lucide-react';
import { CookieConsent } from '@/components/CookieConsent';

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
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const obs = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setVisible(true);
                    obs.disconnect();
                }
            },
            { threshold: 0.15 },
        );
        obs.observe(el);
        return () => obs.disconnect();
    }, []);

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

export default function Welcome({
    auth,
    canLoginWithGoogle,
    canLoginWithGitHub,
}: PageProps<{
    laravelVersion?: string;
    phpVersion?: string;
    canLoginWithGoogle?: boolean;
    canLoginWithGitHub?: boolean;
}>) {
    const [scrolled, setScrolled] = useState(false);
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 20);
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    const features = [
        {
            icon: LayoutDashboard,
            title: 'Workspaces',
            description:
                'Organize teams and projects in dedicated workspaces with fine-grained roles and permissions.',
            color: '#0E83DC',
        },
        {
            icon: FolderKanban,
            title: 'Projects & Boards',
            description:
                'Kanban boards with drag-and-drop, labels, priorities, file attachments, and threaded comments.',
            color: '#69A748',
        },
        {
            icon: FileText,
            title: 'Wiki & Docs',
            description:
                'Tree-based pages with rich content, embeds, and diagrams. Publish internally or publicly.',
            color: '#0E83DC',
        },
        {
            icon: PenLine,
            title: 'Rich Editors',
            description:
                'Powerful editors with tables, code blocks, math, slash commands, and keyboard shortcuts.',
            color: '#485E72',
        },
        {
            icon: Palette,
            title: 'Excalidraw',
            description:
                'Whiteboard sketches and diagrams embedded right in your docs. Draw, collaborate, export.',
            color: '#69A748',
        },
        {
            icon: Users,
            title: 'Team Collaboration',
            description:
                'Admin, editor, and viewer roles per workspace. The right people get the right access.',
            color: '#0E83DC',
        },
        {
            icon: Share2,
            title: 'Publish & Share',
            description:
                'Control visibility per page — publish for internal use or generate public links.',
            color: '#69A748',
        },
        {
            icon: Bookmark,
            title: 'Bookmarks',
            description:
                'Save and organize links, resources, and references. Share bookmark collections with your team.',
            color: '#0E83DC',
        },
        {
            icon: Layers,
            title: 'Snippets',
            description:
                'Reusable text and code snippets with labels and search. Quick-insert into pages and items.',
            color: '#69A748',
        },
        {
            icon: LifeBuoy,
            title: 'Portal support',
            description:
                'Embeddable helpdesk and support portal. Let customers submit tickets and track replies from your site.',
            color: '#485E72',
        },
        {
            icon: Bell,
            title: 'Notifications',
            description:
                'In-app notifications for assignments, replies, and updates. Stay in the loop without leaving the app.',
            color: '#0E83DC',
        },
        {
            icon: Sparkles,
            title: 'AI assist',
            description:
                'Connect your preferred AI provider for content rewriting and suggestions in descriptions and docs.',
            color: '#69A748',
        },
    ];

    const plans = [
        {
            name: 'Free',
            price: '0',
            period: 'forever',
            description: 'All features, great for getting started.',
            features: [
                'All product features',
                '5 workspaces',
                '1 GB attachments per workspace',
                'Unlimited projects, boards, pages',
                'Unlimited members per workspace',
            ],
            cta: 'Get started free',
            href: route('register'),
            highlighted: false,
        },
        {
            name: 'Pro',
            price: '3.99',
            period: '/month',
            description: 'More workspaces and storage for growing teams.',
            features: [
                'Everything in Free',
                '20 workspaces',
                '2 GB per workspace (40 GB total)',
                'Priority support',
            ],
            cta: 'Start Pro trial',
            href: route('register'),
            highlighted: true,
        },
        {
            name: 'Max',
            price: '9.99',
            period: '/month',
            description: 'Unlimited workspaces, large storage.',
            features: [
                'Everything in Pro',
                'Unlimited workspaces',
                '1 TB total attachments',
                'Dedicated support',
            ],
            cta: 'Go Max',
            href: route('register'),
            highlighted: false,
        },
        {
            name: 'Custom',
            price: null,
            period: '',
            description: 'Enterprise contracts and custom needs.',
            features: [
                'Custom workspace & storage limits',
                'SLA and compliance',
                'Dedicated success manager',
                'Custom integrations',
            ],
            cta: 'Contact us',
            href: 'mailto:hello@brainitive.com?subject=Enterprise%20plan',
            highlighted: false,
        },
    ];

    const stats = [
        { value: '100%', label: 'Free to start' },
        { value: '∞', label: 'Projects & pages' },
        { value: '<50ms', label: 'UI response time' },
        { value: '99.9%', label: 'Uptime SLA' },
    ];

    return (
        <>
            <Head title="Brainitive – Workspaces, Boards & Wiki" />
            <div className="min-h-screen overflow-x-hidden bg-[#F4F6F8] text-[#485E72]">
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
                            ? 'bg-white/80 backdrop-blur-xl shadow-[0_1px_3px_rgba(72,94,114,0.08)] border-b border-[#C0C5C0]/40'
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
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-[#485E72]/70 hover:text-[#0E83DC] transition-colors"
                            >
                                <Layers className="h-3.5 w-3.5" />
                                Features
                            </a>
                            <a
                                href="#pricing"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-[#485E72]/70 hover:text-[#0E83DC] transition-colors"
                            >
                                <CreditCard className="h-3.5 w-3.5" />
                                Pricing
                            </a>
                            <Link
                                href="/privacy"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-[#485E72]/70 hover:text-[#0E83DC] transition-colors"
                            >
                                <ShieldCheck className="h-3.5 w-3.5" />
                                Privacy
                            </Link>
                            <Link
                                href="/terms"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-[#485E72]/70 hover:text-[#0E83DC] transition-colors"
                            >
                                <Scale className="h-3.5 w-3.5" />
                                Terms
                            </Link>
                        </div>

                        <div className="flex items-center gap-3">
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
                                        className="hidden sm:inline text-sm font-semibold text-[#485E72] hover:text-[#0E83DC] transition-colors"
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
                                className="md:hidden p-2 text-[#485E72]/70 hover:text-[#0E83DC] transition-colors"
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
                        <div className="md:hidden bg-white/95 backdrop-blur-xl border-b border-[#C0C5C0]/30 px-6 py-4 space-y-3">
                            <a
                                href="#features"
                                onClick={() => setMobileMenuOpen(false)}
                                className="flex items-center gap-2 text-sm font-medium text-[#485E72]/70 hover:text-[#0E83DC]"
                            >
                                <Layers className="h-3.5 w-3.5" />
                                Features
                            </a>
                            <a
                                href="#pricing"
                                onClick={() => setMobileMenuOpen(false)}
                                className="flex items-center gap-2 text-sm font-medium text-[#485E72]/70 hover:text-[#0E83DC]"
                            >
                                <CreditCard className="h-3.5 w-3.5" />
                                Pricing
                            </a>
                            <Link
                                href="/privacy"
                                className="flex items-center gap-2 text-sm font-medium text-[#485E72]/70 hover:text-[#0E83DC]"
                            >
                                <ShieldCheck className="h-3.5 w-3.5" />
                                Privacy
                            </Link>
                            <Link
                                href="/terms"
                                className="flex items-center gap-2 text-sm font-medium text-[#485E72]/70 hover:text-[#0E83DC]"
                            >
                                <Scale className="h-3.5 w-3.5" />
                                Terms
                            </Link>
                            {!auth?.user && (
                                <Link
                                    href={route('login')}
                                    className="block text-sm font-medium text-[#485E72] hover:text-[#0E83DC]"
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
                        <div className="inline-flex items-center gap-2 rounded-full border border-[#0E83DC]/20 bg-white/70 backdrop-blur-sm px-4 py-1.5 text-xs font-semibold text-[#0E83DC] mb-8 animate-fade-in shadow-sm">
                            <Sparkles className="h-3.5 w-3.5" />
                            All-in-one workspace platform
                        </div>



                        <h1 className="text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-bold tracking-tight text-[#485E72] animate-fade-in-up leading-[1.1]">
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
                            className="mt-6 text-lg sm:text-xl text-[#485E72]/70 max-w-2xl mx-auto animate-fade-in-up leading-relaxed"
                            style={{ animationDelay: '0.15s' }}
                        >
                            Brainitive brings workspaces, Kanban boards, and a full wiki
                            together. Plan in boards, write in rich pages, and sketch in
                            Excalidraw &mdash; all in one app.
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
                                            className="inline-flex items-center rounded-2xl border-2 border-[#C0C5C0]/60 bg-white/60 backdrop-blur-sm px-7 py-4 text-base font-bold text-[#485E72] hover:border-[#0E83DC]/40 hover:bg-white transition-all"
                                        >
                                            Log in
                                        </Link>
                                    </div>
                                    {(canLoginWithGoogle || canLoginWithGitHub) && (
                                        <>
                                            <div className="flex items-center gap-3 w-full max-w-xs">
                                                <span className="flex-1 h-px bg-[#C0C5C0]/50" />
                                                <span className="text-sm font-medium text-[#485E72]/60">
                                                    or
                                                </span>
                                                <span className="flex-1 h-px bg-[#C0C5C0]/50" />
                                            </div>
                                            <div className="flex flex-wrap items-center justify-center gap-3">
                                                {canLoginWithGoogle && (
                                                    <a
                                                        href={route('login.google')}
                                                        className="inline-flex items-center justify-center gap-3 rounded-2xl border-2 border-[#C0C5C0]/50 bg-white px-7 py-4 text-base font-bold text-[#485E72] shadow-lg shadow-[#485E72]/5 hover:bg-gray-50 hover:border-[#C0C5C0] transition-all hover:scale-[1.02]"
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
                                                        className="inline-flex items-center justify-center gap-3 rounded-2xl border-2 border-[#C0C5C0]/50 bg-white px-7 py-4 text-base font-bold text-[#485E72] shadow-lg shadow-[#485E72]/5 hover:bg-gray-50 hover:border-[#C0C5C0] transition-all hover:scale-[1.02]"
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
                                            </div>
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
                                    className="text-center rounded-2xl bg-white/60 backdrop-blur-sm border border-[#C0C5C0]/30 py-5 px-3 shadow-sm"
                                >
                                    <div className="text-2xl sm:text-3xl font-bold text-[#0E83DC]">
                                        {s.value}
                                    </div>
                                    <div className="text-xs sm:text-sm text-[#485E72]/60 mt-1 font-medium">
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
                            <h2 className="text-3xl sm:text-4xl font-bold text-[#485E72] mb-4">
                                Everything you need to ship
                            </h2>
                            <p className="text-[#485E72]/60 max-w-xl mx-auto text-lg">
                                Workspaces, Kanban boards, wiki pages, and rich editors
                                &mdash; all in a single product.
                            </p>
                        </RevealSection>

                        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
                            {features.map((feature, i) => (
                                <RevealSection
                                    key={feature.title}
                                    delay={i * 80}
                                >
                                    <div className="group h-full rounded-2xl border border-[#C0C5C0]/30 bg-white/70 backdrop-blur-sm p-6 transition-all duration-300 hover:bg-white hover:shadow-xl hover:shadow-[#485E72]/[0.06] hover:border-[#C0C5C0]/50 hover:-translate-y-1">
                                        <div
                                            className="flex h-12 w-12 items-center justify-center rounded-xl mb-5 transition-transform group-hover:scale-110"
                                            style={{
                                                backgroundColor: `${feature.color}12`,
                                                color: feature.color,
                                            }}
                                        >
                                            <feature.icon
                                                className="h-5 w-5"
                                                strokeWidth={1.8}
                                            />
                                        </div>
                                        <h3 className="font-bold text-[#485E72] mb-2 text-base">
                                            {feature.title}
                                        </h3>
                                        <p className="text-sm leading-relaxed text-[#485E72]/60">
                                            {feature.description}
                                        </p>
                                    </div>
                                </RevealSection>
                            ))}
                        </div>
                    </div>
                </section>

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
                            Brainitive combines project boards, a full wiki with rich
                            editors, and Excalidraw whiteboarding into a single, fast
                            platform.
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
                                <Palette className="h-4 w-4 text-[#C0C5C0]" />{' '}
                                Excalidraw built-in
                            </div>
                        </div>
                    </RevealSection>
                </section>

                {/* ═══ Pricing ═══ */}
                <section
                    id="pricing"
                    className="relative z-10 px-6 py-24 sm:py-32"
                >
                    <div className="max-w-6xl mx-auto">
                        <RevealSection className="text-center mb-16">
                            <div className="inline-flex items-center gap-2 rounded-full border border-[#0E83DC]/20 bg-[#0E83DC]/5 px-4 py-1.5 text-xs font-semibold text-[#0E83DC] mb-4">
                                <Sparkles className="h-3.5 w-3.5" />
                                Pricing
                            </div>
                            <h2 className="text-3xl sm:text-4xl font-bold text-[#485E72] mb-4">
                                Simple, transparent pricing
                            </h2>
                            <p className="text-[#485E72]/60 max-w-xl mx-auto text-lg">
                                Start free, upgrade when you need more workspaces or
                                storage.
                            </p>
                        </RevealSection>

                        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
                            {plans.map((plan, i) => (
                                <RevealSection
                                    key={plan.name}
                                    delay={i * 80}
                                >
                                    <div
                                        className={`h-full rounded-2xl border p-6 flex flex-col transition-all duration-300 hover:-translate-y-1 ${plan.highlighted
                                                ? 'border-[#0E83DC]/40 bg-white ring-2 ring-[#0E83DC]/20 shadow-xl shadow-[#0E83DC]/10'
                                                : 'border-[#C0C5C0]/30 bg-white/70 backdrop-blur-sm hover:bg-white hover:shadow-lg hover:shadow-[#485E72]/[0.06]'
                                            }`}
                                    >
                                        {plan.highlighted && (
                                            <div className="inline-flex self-start items-center rounded-full bg-[#0E83DC]/10 px-3 py-1 text-xs font-bold text-[#0E83DC] mb-3">
                                                Most popular
                                            </div>
                                        )}
                                        <h3 className="font-bold text-lg text-[#485E72]">
                                            {plan.name}
                                        </h3>
                                        <div className="mt-3 flex items-baseline gap-1">
                                            {plan.price !== null ? (
                                                <>
                                                    <span className="text-3xl font-bold text-[#485E72]">
                                                        ${plan.price}
                                                    </span>
                                                    <span className="text-[#485E72]/50 text-sm">
                                                        {plan.period}
                                                    </span>
                                                </>
                                            ) : (
                                                <span className="text-2xl font-bold text-[#485E72]">
                                                    Custom
                                                </span>
                                            )}
                                        </div>
                                        <p className="mt-2 text-sm text-[#485E72]/60">
                                            {plan.description}
                                        </p>
                                        <ul className="mt-6 space-y-3 flex-1">
                                            {plan.features.map((f) => (
                                                <li
                                                    key={f}
                                                    className="flex items-start gap-2.5 text-sm"
                                                >
                                                    <Check className="h-4 w-4 shrink-0 mt-0.5 text-[#69A748]" />
                                                    <span className="text-[#485E72]/70">
                                                        {f}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                        {plan.name === 'Custom' ? (
                                            <a
                                                href="mailto:hello@brainitive.com?subject=Enterprise%20plan"
                                                className="mt-6 inline-flex items-center justify-center gap-2 rounded-xl border-2 border-[#C0C5C0]/50 px-4 py-3 text-sm font-bold text-[#485E72] hover:border-[#0E83DC]/40 hover:text-[#0E83DC] transition-all"
                                            >
                                                Contact us
                                                <ChevronRight className="h-4 w-4" />
                                            </a>
                                        ) : (
                                            <Link
                                                href={plan.href}
                                                className={`mt-6 inline-flex items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold transition-all ${plan.highlighted
                                                        ? 'bg-[#0E83DC] text-white shadow-lg shadow-[#0E83DC]/20 hover:bg-[#0b6fbf] hover:shadow-xl'
                                                        : 'bg-[#485E72] text-white hover:bg-[#3a4d5e]'
                                                    }`}
                                            >
                                                {plan.cta}
                                                <ChevronRight className="h-4 w-4" />
                                            </Link>
                                        )}
                                    </div>
                                </RevealSection>
                            ))}
                        </div>
                    </div>
                </section>

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
                                        className="group inline-flex items-center gap-2 rounded-2xl bg-white px-8 py-4 text-base font-bold text-[#0E83DC] shadow-xl hover:shadow-2xl transition-all hover:scale-[1.02]"
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
                <footer className="relative z-10 border-t border-[#C0C5C0]/30 bg-white/40 backdrop-blur-sm">
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
                                    className="text-[#485E72]/60 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Features
                                </a>
                                <a
                                    href="#pricing"
                                    className="text-[#485E72]/60 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Pricing
                                </a>
                                <Link
                                    href="/privacy"
                                    className="text-[#485E72]/60 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Privacy Policy
                                </Link>
                                <Link
                                    href="/terms"
                                    className="text-[#485E72]/60 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Terms of Service
                                </Link>
                                <Link
                                    href="/cookies"
                                    className="text-[#485E72]/60 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Cookie Policy
                                </Link>
                                <Link
                                    href="/refund"
                                    className="text-[#485E72]/60 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Refund Policy
                                </Link>
                                <button
                                    type="button"
                                    onClick={() =>
                                        window.dispatchEvent(
                                            new CustomEvent(
                                                'brainitive-open-cookie-settings',
                                            ),
                                        )
                                    }
                                    className="text-[#485E72]/60 hover:text-[#0E83DC] transition-colors font-medium"
                                >
                                    Cookie settings
                                </button>
                            </div>
                        </div>
                        <div className="mt-8 pt-6 border-t border-[#C0C5C0]/20 flex flex-col sm:flex-row items-center justify-between gap-4">
                            <p className="text-xs text-[#485E72]/40">
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

            <CookieConsent />
        </>
    );
}
