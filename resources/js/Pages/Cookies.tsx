import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Cookie, ShieldCheck, BarChart3, Megaphone, Settings, Clock, Globe } from 'lucide-react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { CookieConsent } from '@/components/CookieConsent';

const cookieTypes = [
    {
        icon: ShieldCheck,
        name: 'Necessary cookies',
        required: true,
        color: '#69A748',
        description: 'Essential for the app to function. These cannot be disabled.',
        cookies: [
            { name: 'brainitive_session', purpose: 'Maintains your login session across pages.', duration: 'Session' },
            { name: 'XSRF-TOKEN', purpose: 'Protects against cross-site request forgery (CSRF) attacks.', duration: 'Session' },
            { name: 'brainitive_cookie_consent', purpose: 'Stores your cookie consent preference so we don\'t ask again.', duration: '1 year' },
            { name: 'brainitive_landing_theme', purpose: 'Remembers your preferred theme setting (if applicable).', duration: 'Persistent' },
        ],
    },
    {
        icon: BarChart3,
        name: 'Analytics cookies',
        required: false,
        color: '#0E83DC',
        description: 'Help us understand how visitors interact with the app so we can improve the experience.',
        cookies: [
            { name: '_ga / _gid', purpose: 'Google Analytics — tracks unique visitors and page views.', duration: '2 years / 24 hours' },
        ],
    },
    {
        icon: Megaphone,
        name: 'Marketing cookies',
        required: false,
        color: '#485E72',
        description: 'Used to deliver relevant advertisements and measure campaign effectiveness. Currently none are active.',
        cookies: [],
    },
];

const sections = [
    {
        icon: Cookie,
        num: '1',
        title: 'What are cookies?',
        content: 'Cookies are small text files stored on your device when you visit a website. They help the site remember your preferences, keep you logged in, and understand how you use the service.',
    },
    {
        icon: Settings,
        num: '2',
        title: 'How we use cookies',
        content: 'Brainitive uses cookies to maintain your session, protect your security, remember your preferences, and (optionally) gather anonymised usage statistics. We do not use cookies to track you across other websites.',
    },
    {
        icon: Globe,
        num: '3',
        title: 'Third-party cookies',
        content: 'We may use analytics services (e.g. Google Analytics) that set their own cookies. These third parties have their own privacy policies. We do not control third-party cookies but only enable them with your consent.',
    },
    {
        icon: Clock,
        num: '4',
        title: 'Managing your preferences',
        content: 'You can change your cookie preferences at any time by clicking "Cookie settings" in the footer of any page. You can also configure your browser to block or delete cookies, though this may affect functionality.',
    },
];

export default function Cookies() {
    return (
        <>
            <Head title="Cookie Policy – Brainitive" />
            <div className="min-h-screen bg-[#F4F6F8] text-[#485E72]">
                {/* Ambient blobs */}
                <div className="fixed inset-0 pointer-events-none overflow-hidden">
                    <div className="absolute -top-32 left-1/4 w-[500px] h-[500px] rounded-full bg-[#0E83DC]/[0.05] blur-[100px]" />
                    <div className="absolute bottom-0 right-1/4 w-[400px] h-[400px] rounded-full bg-[#69A748]/[0.04] blur-[80px]" />
                </div>

                <div className="relative z-10 mx-auto max-w-3xl px-6 py-12">
                    {/* Back link */}
                    <Link
                        href="/"
                        className="inline-flex items-center gap-2 text-sm font-medium text-[#485E72]/60 hover:text-[#0E83DC] transition-colors mb-10"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to home
                    </Link>

                    {/* Header */}
                    <div className="flex items-center gap-4 mb-2">
                        <div className="flex items-center justify-center h-12 w-12 rounded-xl bg-[#69A748]/10 text-[#69A748]">
                            <Cookie className="h-6 w-6" />
                        </div>
                        <div>
                            <h1 className="text-3xl font-bold text-[#485E72]">Cookie Policy</h1>
                            <p className="text-sm text-[#485E72]/50 mt-1">
                                Last updated: 28 February 2026
                            </p>
                        </div>
                    </div>

                    {/* General sections */}
                    <div className="mt-12 space-y-6">
                        {sections.map((s) => (
                            <div
                                key={s.num}
                                className="rounded-2xl border border-[#C0C5C0]/30 bg-white/70 backdrop-blur-sm p-6 transition-all hover:bg-white hover:shadow-lg hover:shadow-[#485E72]/[0.04]"
                            >
                                <div className="flex items-start gap-4">
                                    <div className="flex items-center justify-center h-9 w-9 rounded-lg bg-[#69A748]/10 text-[#69A748] shrink-0 mt-0.5">
                                        <s.icon className="h-4 w-4" strokeWidth={1.8} />
                                    </div>
                                    <div>
                                        <h2 className="font-bold text-[#485E72] text-base">
                                            {s.num}. {s.title}
                                        </h2>
                                        <p className="mt-2 text-sm leading-relaxed text-[#485E72]/60">
                                            {s.content}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Cookie detail tables */}
                    <h2 className="text-2xl font-bold text-[#485E72] mt-16 mb-6">
                        Cookies we use
                    </h2>

                    <div className="space-y-6">
                        {cookieTypes.map((type) => (
                            <div
                                key={type.name}
                                className="rounded-2xl border border-[#C0C5C0]/30 bg-white/70 backdrop-blur-sm overflow-hidden transition-all hover:bg-white hover:shadow-lg hover:shadow-[#485E72]/[0.04]"
                            >
                                <div className="p-6 pb-4">
                                    <div className="flex items-center gap-3">
                                        <div
                                            className="flex items-center justify-center h-9 w-9 rounded-lg shrink-0"
                                            style={{ backgroundColor: `${type.color}15`, color: type.color }}
                                        >
                                            <type.icon className="h-4 w-4" strokeWidth={1.8} />
                                        </div>
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2">
                                                <h3 className="font-bold text-[#485E72]">{type.name}</h3>
                                                {type.required ? (
                                                    <span className="text-xs font-bold text-[#69A748] bg-[#69A748]/10 px-2 py-0.5 rounded-full">Required</span>
                                                ) : (
                                                    <span className="text-xs font-medium text-[#485E72]/50 bg-[#C0C5C0]/20 px-2 py-0.5 rounded-full">Optional</span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <p className="mt-2 text-sm text-[#485E72]/60 leading-relaxed">
                                        {type.description}
                                    </p>
                                </div>

                                {type.cookies.length > 0 && (
                                    <div className="border-t border-[#C0C5C0]/20">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="bg-[#F4F6F8]/80">
                                                    <th className="text-left px-6 py-3 text-xs font-bold text-[#485E72]/50 uppercase tracking-wider">Cookie</th>
                                                    <th className="text-left px-6 py-3 text-xs font-bold text-[#485E72]/50 uppercase tracking-wider hidden sm:table-cell">Purpose</th>
                                                    <th className="text-left px-6 py-3 text-xs font-bold text-[#485E72]/50 uppercase tracking-wider">Duration</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {type.cookies.map((c, i) => (
                                                    <tr key={c.name} className={i > 0 ? 'border-t border-[#C0C5C0]/10' : ''}>
                                                        <td className="px-6 py-3 font-mono text-xs text-[#0E83DC] font-semibold">{c.name}</td>
                                                        <td className="px-6 py-3 text-[#485E72]/60 hidden sm:table-cell">{c.purpose}</td>
                                                        <td className="px-6 py-3 text-[#485E72]/50 text-xs font-medium">{c.duration}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}

                                {type.cookies.length === 0 && (
                                    <div className="px-6 pb-4 text-sm text-[#485E72]/40 italic">
                                        No cookies currently active in this category.
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>

                    {/* Manage button */}
                    <div className="mt-10 text-center">
                        <button
                            type="button"
                            onClick={() => window.dispatchEvent(new CustomEvent('brainitive-open-cookie-settings'))}
                            className="inline-flex items-center gap-2 rounded-xl bg-[#0E83DC] px-6 py-3 text-sm font-bold text-white shadow-lg shadow-[#0E83DC]/20 hover:bg-[#0b6fbf] transition-all"
                        >
                            <Settings className="h-4 w-4" />
                            Manage cookie preferences
                        </button>
                    </div>

                    {/* Footer links */}
                    <div className="mt-16 pt-8 border-t border-[#C0C5C0]/30 flex flex-wrap items-center gap-6">
                        <Link href="/" className="inline-flex items-center gap-2 text-sm font-semibold text-[#0E83DC] hover:text-[#0b6fbf] transition-colors">
                            <ApplicationLogo className="h-5 w-auto" alt="" /> Home
                        </Link>
                        <Link href={route('privacy')} className="text-sm font-semibold text-[#0E83DC] hover:text-[#0b6fbf] transition-colors">
                            Privacy Policy
                        </Link>
                        <Link href={route('terms')} className="text-sm font-semibold text-[#0E83DC] hover:text-[#0b6fbf] transition-colors">
                            Terms of Service
                        </Link>
                        <Link href={route('refund')} className="text-sm font-semibold text-[#0E83DC] hover:text-[#0b6fbf] transition-colors">
                            Refund Policy
                        </Link>
                    </div>
                </div>
            </div>
            <CookieConsent />
        </>
    );
}
