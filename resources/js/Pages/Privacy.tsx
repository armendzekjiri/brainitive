import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Shield, Database, Eye, FileText, Lock, UserCheck, Cookie, RefreshCw, Mail, Server, Puzzle } from 'lucide-react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { CookieConsent } from '@/components/CookieConsent';

const sections = [
    {
        icon: Shield,
        num: '1',
        title: 'Introduction',
        content: 'Brainitive ("we", "our", or "us") provides an all-in-one workspace platform for project management, Kanban boards, wiki-style documentation, and team collaboration. This Privacy Policy explains how we collect, use, disclose, and protect your personal information when you use Brainitive at brainitive.com and its related services.',
    },
    {
        icon: Database,
        num: '2',
        title: 'Information we collect',
        content: 'We collect information you provide directly: name, email address, and password when registering; profile details you add; and all content you create inside Brainitive — workspace names, project and board data, page content, comments, uploaded files and attachments (up to the storage limit of your plan), and bookmark collections. We also collect usage data (features used, pages visited, session duration) and technical data (IP address, browser type, device info, and cookies).',
    },
    {
        icon: Eye,
        num: '3',
        title: 'How we use your information',
        content: 'We use your information to operate and deliver the Brainitive service (authenticating you, storing your workspaces, boards, and pages), to send transactional emails (account confirmations, in-app notifications), to improve and develop new features, to enforce our Terms of Service, and to comply with legal obligations. We do not sell your personal data to third parties.',
    },
    {
        icon: FileText,
        num: '4',
        title: 'Data sharing',
        content: 'We share data only with infrastructure and service providers needed to run Brainitive (e.g. cloud hosting, file storage, transactional email). These providers are bound by data processing agreements and may not use your data for their own purposes. We may also disclose data when required by law or to protect the rights, safety, or property of Brainitive and its users. We do not share your data for third-party advertising.',
    },
    {
        icon: Server,
        num: '5',
        title: 'Workspace and team data',
        content: 'When you create or join a workspace, members of that workspace with appropriate roles (Admin, Editor, Viewer) can see the projects, boards, pages, and files shared within it. Workspace Admins can manage members and roles. Public pages you explicitly publish may be accessible to anyone with the link.',
    },
    {
        icon: Lock,
        num: '6',
        title: 'Data retention and security',
        content: 'Your account data and workspace content are retained for as long as your account remains active. If you delete your account, we will delete or anonymise your personal data within 30 days, except where retention is required by law. We use industry-standard encryption in transit (TLS) and apply appropriate access controls to protect your data.',
    },
    {
        icon: UserCheck,
        num: '7',
        title: 'Your rights',
        content: 'You may access, correct, or delete your personal data directly in your account settings. You may also request a copy of your data or object to certain processing by contacting us at privacy@brainitive.com. If you are in the EEA or UK, you have rights under GDPR, including the right to lodge a complaint with a supervisory authority.',
    },
    {
        icon: Cookie,
        num: '8',
        title: 'Cookies',
        content: 'Brainitive uses strictly necessary cookies (session management, authentication) and, with your consent, analytics cookies to understand how the app is used. You can manage your cookie preferences through the cookie settings banner. See our Cookie Policy for full details.',
    },
    {
        icon: Puzzle,
        num: '9',
        title: 'Browser extension',
        content: 'The optional Brainitive browser extension (Chrome, Edge, Firefox) reads a web page only when you use it on that page — the toolbar button, the right-click menu or a keyboard shortcut — and sends what you choose to capture (the page address and title, selected text, a clipped article or a screenshot) only to the Brainitive server you signed in to. It does not track your browsing, has no analytics and shares nothing with third parties. Its sign-in token is stored on your device, can only save content and search titles, and is removed when you sign out.',
    },
    {
        icon: RefreshCw,
        num: '10',
        title: 'Changes to this policy',
        content: 'We may update this Privacy Policy from time to time. When we do, we will update the "Last updated" date below and, for material changes, notify you by email or an in-app notice at least 14 days before the change takes effect.',
    },
    {
        icon: Mail,
        num: '11',
        title: 'Contact',
        content: 'For privacy questions or data requests, email us at privacy@brainitive.com. For general enquiries, reach us at hello@brainitive.com.',
    },
];

export default function Privacy() {
    return (
        <>
            <Head title="Privacy Policy – Brainitive" />
            <div className="min-h-screen bg-[#F4F6F8] text-[#485E72]">
                {/* Ambient blobs */}
                <div className="fixed inset-0 pointer-events-none overflow-hidden">
                    <div className="absolute -top-32 right-0 w-[500px] h-[500px] rounded-full bg-[#0E83DC]/[0.05] blur-[100px]" />
                    <div className="absolute bottom-0 -left-32 w-[400px] h-[400px] rounded-full bg-[#69A748]/[0.04] blur-[80px]" />
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
                        <div className="flex items-center justify-center h-12 w-12 rounded-xl bg-[#0E83DC]/10 text-[#0E83DC]">
                            <Shield className="h-6 w-6" />
                        </div>
                        <div>
                            <h1 className="text-3xl font-bold text-[#485E72]">Privacy Policy</h1>
                            <p className="text-sm text-[#485E72]/50 mt-1">
                                Last updated: 22 October 2026
                            </p>
                        </div>
                    </div>

                    {/* Sections */}
                    <div className="mt-12 space-y-6">
                        {sections.map((s) => (
                            <div
                                key={s.num}
                                className="rounded-2xl border border-[#C0C5C0]/30 bg-white/70 backdrop-blur-sm p-6 transition-all hover:bg-white hover:shadow-lg hover:shadow-[#485E72]/[0.04]"
                            >
                                <div className="flex items-start gap-4">
                                    <div className="flex items-center justify-center h-9 w-9 rounded-lg bg-[#0E83DC]/10 text-[#0E83DC] shrink-0 mt-0.5">
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

                    {/* Footer links */}
                    <div className="mt-16 pt-8 border-t border-[#C0C5C0]/30 flex flex-wrap items-center gap-6">
                        <Link href="/" className="inline-flex items-center gap-2 text-sm font-semibold text-[#0E83DC] hover:text-[#0b6fbf] transition-colors">
                            <ApplicationLogo className="h-5 w-auto" alt="" /> Home
                        </Link>
                        <Link href={route('terms')} className="text-sm font-semibold text-[#0E83DC] hover:text-[#0b6fbf] transition-colors">
                            Terms of Service
                        </Link>
                        <Link href={route('cookies')} className="text-sm font-semibold text-[#0E83DC] hover:text-[#0b6fbf] transition-colors">
                            Cookie Policy
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
