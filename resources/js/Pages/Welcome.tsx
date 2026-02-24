import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState, useEffect } from 'react';
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
    Sun,
    Moon,
    Check,
} from 'lucide-react';
import { CookieConsent } from '@/components/CookieConsent';

const THEME_KEY = 'brainitive_landing_theme';

function HeroIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 120 72"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            className={className}
            aria-hidden
        >
            <path
                d="M30 36c0-10 8-20 18-24 7-3 16-2 22 3 7 6 10 16 8 25-2 9-10 16-20 18-10 2-20-2-26-10-5-7-4-14 2-12z"
                className="opacity-90"
                fill="currentColor"
            />
            <path
                d="M82 34c0 8 6 16 16 20 7 3 14 1 18-4 5-6 6-14 3-22-3-8-10-14-18-16-9-2-17 2-22 8-4 5-2 8 3 8z"
                className="opacity-75"
                fill="currentColor"
            />
        </svg>
    );
}

function WavyUnderline({ className }: { className?: string }) {
    return (
        <span className={`relative inline-block ${className ?? ''}`}>
            <svg
                viewBox="0 0 200 12"
                preserveAspectRatio="none"
                className="absolute -bottom-1 left-0 w-full h-3 text-violet-500 dark:text-violet-400"
                aria-hidden
            >
                <path
                    d="M2 8 Q 25 2, 50 8 T 100 8 T 150 8 T 198 8"
                    stroke="currentColor"
                    strokeWidth="2.5"
                    fill="none"
                    strokeLinecap="round"
                />
            </svg>
        </span>
    );
}

export default function Welcome({
    auth,
}: PageProps<{ laravelVersion?: string; phpVersion?: string }>) {
    const [theme, setTheme] = useState<'light' | 'dark'>(() => {
        if (typeof window === 'undefined') return 'dark';
        return (localStorage.getItem(THEME_KEY) as 'light' | 'dark') || 'dark';
    });

    useEffect(() => {
        localStorage.setItem(THEME_KEY, theme);
    }, [theme]);

    const isDark = theme === 'dark';

    const features = [
        {
            icon: LayoutDashboard,
            title: 'Workspaces',
            description:
                'Organize teams and projects in dedicated workspaces. Create multiple workspaces for clients, departments, or side projects. Invite members with admin, editor, or viewer roles, set permissions per workspace, and keep everything in one place without mixing contexts.',
        },
        {
            icon: FolderKanban,
            title: 'Projects & Boards',
            description:
                'Create projects under each workspace and add Kanban boards with custom columns. Move cards across columns with drag-and-drop, add labels and priorities, link items (blocks, duplicates, relates to), attach files, and discuss in comments. Move items between boards when priorities shift.',
        },
        {
            icon: FileText,
            title: 'Wiki & Docs',
            description:
                'Tree-based pages live alongside your projects: nest pages in folders, reorder by drag-and-drop, and structure docs the way you think. Each page supports rich content, embeds, and diagrams. Publish pages for your team or make them public, or unpublish and archive when you need to.',
        },
        {
            icon: PenLine,
            title: 'Rich editors',
            description:
                'Write in powerful editors (Lexical and TipTap) with tables, code blocks with syntax highlighting, math (KaTeX), images and embeds, block quotes, lists, and more. Use slash commands and keyboard shortcuts for a smooth writing experience.',
        },
        {
            icon: Palette,
            title: 'Excalidraw',
            description:
                'Embed whiteboard sketches and diagrams directly in your docs. Draw freely, add shapes and arrows, collaborate on the same canvas, and export as PNG or SVG. Perfect for architecture sketches, flowcharts, and quick visual notes.',
        },
        {
            icon: Users,
            title: 'Team collaboration',
            description:
                'Invite members to workspaces with admin, editor, or viewer roles. Admins manage settings and members; editors create and edit content; viewers only read. Manage workspaces and permissions in one place so the right people have the right access.',
        },
        {
            icon: Share2,
            title: 'Publish & share',
            description:
                'Publish wiki pages for internal use or public links. Unpublish or archive pages when they’re no longer relevant. Control visibility per page so your knowledge base stays up to date and only visible to who should see it.',
        },
        {
            icon: Zap,
            title: 'Fast & modern',
            description:
                'Built with Laravel, Inertia, and React for a snappy, app-like experience. Real-time feel, keyboard shortcuts, and a clean UI so you can focus on work instead of waiting on the tool.',
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
                '2 GB per workspace (allocatable across workspaces, 40 GB total)',
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
                'Custom workspace and storage limits',
                'SLA and compliance',
                'Dedicated success manager',
                'Custom integrations',
            ],
            cta: 'Contact us',
            href: 'mailto:hello@brainitive.com?subject=Enterprise%20plan',
            highlighted: false,
        },
    ];

    return (
        <>
            <Head title="Brainitive – Workspaces, Boards & Wiki" />
            <div
                className={`min-h-screen overflow-x-hidden transition-colors duration-300 ${
                    isDark
                        ? 'bg-[#0a0a0f] text-white'
                        : 'bg-zinc-50 text-zinc-900'
                }`}
            >
                {/* Background effects */}
                <div className="fixed inset-0 pointer-events-none">
                    {isDark ? (
                        <>
                            <div className="absolute top-0 left-1/4 w-[600px] h-[600px] bg-violet-500/10 rounded-full blur-[120px] animate-pulse-slow" />
                            <div className="absolute bottom-1/4 right-1/4 w-[500px] h-[500px] bg-emerald-500/10 rounded-full blur-[100px] animate-pulse-slow" style={{ animationDelay: '1s' }} />
                            <div className="absolute inset-0 bg-[linear-gradient(180deg,transparent_0%,#0a0a0f_70%)]" />
                        </>
                    ) : (
                        <>
                            <div className="absolute top-0 right-0 w-[500px] h-[500px] bg-violet-200/40 rounded-full blur-[100px]" />
                            <div className="absolute bottom-1/3 left-0 w-[400px] h-[400px] bg-emerald-200/30 rounded-full blur-[80px]" />
                            <div className="absolute inset-0 bg-gradient-to-b from-transparent to-zinc-50/80" />
                        </>
                    )}
                </div>

                {/* Nav */}
                <nav className="relative z-10 flex items-center justify-between px-6 py-5 max-w-6xl mx-auto">
                    <Link
                        href="/"
                        className="flex items-center gap-2 font-semibold text-xl tracking-tight"
                    >
                        <ApplicationLogo
                            className="h-9 w-auto object-contain"
                            alt="Brainitive"
                        />
                    </Link>
                    <div className="flex items-center gap-4">
                        <button
                            type="button"
                            onClick={() => setTheme(isDark ? 'light' : 'dark')}
                            className={`rounded-lg p-2 transition-colors ${
                                isDark
                                    ? 'text-zinc-400 hover:text-white hover:bg-white/10'
                                    : 'text-zinc-500 hover:text-zinc-900 hover:bg-zinc-200/80'
                            }`}
                            aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
                        >
                            {isDark ? <Sun className="h-5 w-5" /> : <Moon className="h-5 w-5" />}
                        </button>
                        <Link
                            href={route('privacy')}
                            className={`text-sm transition-colors ${isDark ? 'text-zinc-400 hover:text-white' : 'text-zinc-600 hover:text-zinc-900'}`}
                        >
                            Privacy
                        </Link>
                        <Link
                            href={route('terms')}
                            className={`text-sm transition-colors ${isDark ? 'text-zinc-400 hover:text-white' : 'text-zinc-600 hover:text-zinc-900'}`}
                        >
                            Terms
                        </Link>
                        {auth?.user ? (
                            <Link
                                href={route('dashboard')}
                                className="inline-flex items-center gap-2 rounded-lg bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20 transition-colors"
                            >
                                Dashboard
                                <ChevronRight className="h-4 w-4" />
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={route('login')}
                                    className={`text-sm font-medium transition-colors ${isDark ? 'text-zinc-300 hover:text-white' : 'text-zinc-600 hover:text-zinc-900'}`}
                                >
                                    Log in
                                </Link>
                                <Link
                                    href={route('register')}
                                    className="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-violet-500 to-violet-600 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-violet-500/25 hover:from-violet-600 hover:to-violet-700 transition-all"
                                >
                                    Get started
                                    <ChevronRight className="h-4 w-4" />
                                </Link>
                            </>
                        )}
                    </div>
                </nav>

                {/* Hero — like screenshot: icon, social proof, headline with wavy underline, subheading */}
                <header className="relative z-10 px-6 pt-12 pb-24 text-center max-w-4xl mx-auto">
                    <div className="flex justify-center mb-6 animate-fade-in">
                        <div
                            className={`p-4 rounded-2xl ${
                                isDark ? 'text-violet-400/80 bg-violet-500/10' : 'text-violet-500 bg-violet-100/80'
                            }`}
                        >
                            <HeroIcon className="h-14 w-14 sm:h-16 sm:w-16" />
                        </div>
                    </div>
                    <p className={`mb-6 text-sm font-medium flex items-center justify-center gap-2 ${isDark ? 'text-zinc-400' : 'text-zinc-500'}`}>
                        <span className="inline-flex items-center gap-1.5">
                            <Zap className="h-4 w-4 text-amber-500" />
                            All-in-one
                        </span>
                        <span className="text-zinc-400">·</span>
                        <span>Workspaces · Boards · Wiki</span>
                    </p>
                    <h1
                        className={`text-4xl sm:text-5xl md:text-6xl font-bold tracking-tight max-w-3xl mx-auto animate-fade-in-up ${
                            isDark ? 'text-white' : 'text-zinc-900'
                        }`}
                        style={{ animationDelay: '0.1s' }}
                    >
                        Your workspace for{' '}
                        <span className="relative inline-block">
                            projects, boards & docs
                            <WavyUnderline />
                        </span>
                    </h1>
                    <p
                        className={`mt-6 text-lg sm:text-xl max-w-2xl mx-auto animate-fade-in-up ${
                            isDark ? 'text-zinc-400' : 'text-zinc-600'
                        }`}
                        style={{ animationDelay: '0.2s' }}
                    >
                        Brainitive brings workspaces, Kanban boards, and a full wiki together. Plan in boards, write in rich pages with tables and code blocks, and sketch in Excalidraw—all in one app. Manage teams, publish docs, and keep everything in sync.
                    </p>
                    <div className="mt-10 flex flex-wrap items-center justify-center gap-4 animate-fade-in-up" style={{ animationDelay: '0.3s' }}>
                        {!auth?.user && (
                            <>
                                <Link
                                    href={route('register')}
                                    className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-violet-500 to-violet-600 px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-violet-500/25 hover:from-violet-600 hover:to-violet-700 transition-all hover:scale-[1.02]"
                                >
                                    Start for free
                                    <ChevronRight className="h-4 w-4" />
                                </Link>
                                <Link
                                    href={route('login')}
                                    className={`inline-flex items-center rounded-xl border px-6 py-3.5 text-base font-semibold transition-colors ${
                                        isDark
                                            ? 'border-zinc-600 bg-white/5 text-white hover:bg-white/10'
                                            : 'border-zinc-300 bg-white text-zinc-900 hover:bg-zinc-50'
                                    }`}
                                >
                                    Log in
                                </Link>
                            </>
                        )}
                        {auth?.user && (
                            <Link
                                href={route('dashboard')}
                                className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-violet-500 to-violet-600 px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-violet-500/25 hover:from-violet-600 hover:to-violet-700 transition-all"
                            >
                                Go to Dashboard
                                <ChevronRight className="h-4 w-4" />
                            </Link>
                        )}
                    </div>
                </header>

                {/* Features — detailed */}
                <section className="relative z-10 px-6 pb-24 max-w-6xl mx-auto">
                    <h2 className={`text-2xl sm:text-3xl font-bold text-center mb-4 ${isDark ? 'text-white' : 'text-zinc-900'}`}>
                        Everything you need to ship
                    </h2>
                    <p className={`text-center mb-16 max-w-xl mx-auto ${isDark ? 'text-zinc-400' : 'text-zinc-600'}`}>
                        Workspaces, Kanban boards, wiki pages, and rich editors in a single product. Detailed for every function.
                    </p>
                    <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                        {features.map((feature, i) => (
                            <div
                                key={feature.title}
                                className={`group rounded-2xl border p-6 backdrop-blur-sm transition-all hover:border-opacity-60 animate-fade-in-up ${
                                    isDark
                                        ? 'border-zinc-800 bg-white/[0.02] hover:bg-white/[0.04]'
                                        : 'border-zinc-200 bg-white/80 hover:bg-white shadow-sm'
                                }`}
                                style={{ animationDelay: `${0.4 + i * 0.05}s` }}
                            >
                                <div className={`flex h-11 w-11 items-center justify-center rounded-xl mb-4 group-hover:scale-105 transition-transform ${
                                    isDark ? 'bg-violet-500/20 text-violet-400' : 'bg-violet-100 text-violet-600'
                                }`}>
                                    <feature.icon className="h-5 w-5" />
                                </div>
                                <h3 className={`font-semibold mb-2 ${isDark ? 'text-white' : 'text-zinc-900'}`}>
                                    {feature.title}
                                </h3>
                                <p className={`text-sm leading-relaxed ${isDark ? 'text-zinc-400' : 'text-zinc-600'}`}>
                                    {feature.description}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Pricing */}
                <section id="pricing" className="relative z-10 px-6 pb-24 max-w-6xl mx-auto">
                    <h2 className={`text-2xl sm:text-3xl font-bold text-center mb-4 ${isDark ? 'text-white' : 'text-zinc-900'}`}>
                        Simple pricing
                    </h2>
                    <p className={`text-center mb-14 max-w-xl mx-auto ${isDark ? 'text-zinc-400' : 'text-zinc-600'}`}>
                        Start free, upgrade when you need more workspaces or storage.
                    </p>
                    <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                        {plans.map((plan) => (
                            <div
                                key={plan.name}
                                className={`rounded-2xl border p-6 flex flex-col transition-all animate-fade-in-up ${
                                    plan.highlighted
                                        ? 'ring-2 ring-violet-500 shadow-lg shadow-violet-500/20 bg-violet-500/5 border-violet-500/50'
                                        : isDark
                                            ? 'border-zinc-800 bg-white/[0.02] hover:border-zinc-700'
                                            : 'border-zinc-200 bg-white shadow-sm hover:border-zinc-300'
                                }`}
                            >
                                <h3 className={`font-bold text-lg ${isDark ? 'text-white' : 'text-zinc-900'}`}>
                                    {plan.name}
                                </h3>
                                <div className="mt-2 flex items-baseline gap-1">
                                    {plan.price !== null ? (
                                        <>
                                            <span className={`text-2xl font-bold ${isDark ? 'text-white' : 'text-zinc-900'}`}>
                                                ${plan.price}
                                            </span>
                                            <span className={isDark ? 'text-zinc-400' : 'text-zinc-500'}>
                                                {plan.period}
                                            </span>
                                        </>
                                    ) : (
                                        <span className={`text-xl font-bold ${isDark ? 'text-white' : 'text-zinc-900'}`}>
                                            Custom
                                        </span>
                                    )}
                                </div>
                                <p className={`mt-2 text-sm ${isDark ? 'text-zinc-400' : 'text-zinc-600'}`}>
                                    {plan.description}
                                </p>
                                <ul className="mt-6 space-y-3 flex-1">
                                    {plan.features.map((f) => (
                                        <li key={f} className="flex items-start gap-2 text-sm">
                                            <Check className="h-4 w-4 shrink-0 text-violet-500 mt-0.5" />
                                            <span className={isDark ? 'text-zinc-300' : 'text-zinc-600'}>{f}</span>
                                        </li>
                                    ))}
                                </ul>
                                {plan.name === 'Custom' ? (
                                    <a
                                        href="mailto:hello@brainitive.com?subject=Enterprise%20plan"
                                        className={`mt-6 inline-flex items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold transition-all ${
                                            isDark ? 'bg-white/10 text-white hover:bg-white/20' : 'bg-zinc-900 text-white hover:bg-zinc-800'
                                        }`}
                                    >
                                        Contact us
                                        <ChevronRight className="h-4 w-4" />
                                    </a>
                                ) : (
                                    <Link
                                        href={plan.href}
                                        className={`mt-6 inline-flex items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold transition-all ${
                                            plan.highlighted
                                                ? 'bg-violet-600 text-white hover:bg-violet-500'
                                                : isDark
                                                    ? 'bg-white/10 text-white hover:bg-white/20'
                                                    : 'bg-zinc-900 text-white hover:bg-zinc-800'
                                        }`}
                                    >
                                        {plan.cta}
                                        <ChevronRight className="h-4 w-4" />
                                    </Link>
                                )}
                            </div>
                        ))}
                    </div>
                </section>

                {/* CTA */}
                {!auth?.user && (
                    <section className="relative z-10 px-6 pb-32 max-w-3xl mx-auto text-center">
                        <div
                            className={`rounded-3xl border p-10 sm:p-14 ${
                                isDark
                                    ? 'border-zinc-800 bg-gradient-to-br from-violet-500/10 to-emerald-500/10'
                                    : 'border-zinc-200 bg-white shadow-xl'
                            }`}
                        >
                            <h2 className={`text-2xl sm:text-3xl font-bold mb-3 ${isDark ? 'text-white' : 'text-zinc-900'}`}>
                                Ready to get started?
                            </h2>
                            <p className={isDark ? 'text-zinc-400 mb-8' : 'text-zinc-600 mb-8'}>
                                Create your account and set up your first workspace in minutes.
                            </p>
                            <Link
                                href={route('register')}
                                className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-violet-500 to-violet-600 px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-violet-500/25 hover:from-violet-600 hover:to-violet-700 transition-all"
                            >
                                Create free account
                                <ChevronRight className="h-4 w-4" />
                            </Link>
                        </div>
                    </section>
                )}

                {/* Footer */}
                <footer
                    className={`relative z-10 border-t px-6 py-10 max-w-6xl mx-auto ${
                        isDark ? 'border-zinc-800' : 'border-zinc-200'
                    }`}
                >
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div className={`flex items-center gap-2 font-semibold ${isDark ? 'text-zinc-400' : 'text-zinc-500'}`}>
                            <span className={`flex h-8 w-8 items-center justify-center rounded-lg text-white text-sm ${isDark ? 'bg-zinc-800' : 'bg-zinc-700'}`}>
                                B
                            </span>
                            Brainitive
                        </div>
                        <div className="flex items-center gap-8 text-sm">
                            <Link
                                href={route('privacy')}
                                className={isDark ? 'text-zinc-500 hover:text-white' : 'text-zinc-500 hover:text-zinc-900'}
                            >
                                Privacy Policy
                            </Link>
                            <Link
                                href={route('terms')}
                                className={isDark ? 'text-zinc-500 hover:text-white' : 'text-zinc-500 hover:text-zinc-900'}
                            >
                                Terms of Service
                            </Link>
                            <button
                                type="button"
                                onClick={() => window.dispatchEvent(new CustomEvent('brainitive-open-cookie-settings'))}
                                className={isDark ? 'text-zinc-500 hover:text-white' : 'text-zinc-500 hover:text-zinc-900'}
                            >
                                Cookie settings
                            </button>
                        </div>
                    </div>
                    <p className={`mt-6 text-center text-xs ${isDark ? 'text-zinc-600' : 'text-zinc-400'}`}>
                        © {new Date().getFullYear()} Brainitive. All rights reserved.
                    </p>
                </footer>
            </div>

            <CookieConsent />
        </>
    );
}
