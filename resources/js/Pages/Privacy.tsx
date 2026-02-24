import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Shield, FileText, Eye, Database, Lock, UserCheck, Cookie, RefreshCw, Mail } from 'lucide-react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { CookieConsent } from '@/components/CookieConsent';

const sections = [
    {
        icon: Shield,
        num: '1',
        title: 'Introduction',
        content: 'Brainitive ("we", "our", or "us") respects your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our application and related services.',
    },
    {
        icon: Database,
        num: '2',
        title: 'Information we collect',
        content: 'We may collect information you provide directly (e.g. account registration, profile data, content you create in workspaces, projects, boards, and pages), usage data (e.g. how you use the app), and technical data (e.g. IP address, browser type, device information). We use cookies and similar technologies as described in our cookie notice.',
    },
    {
        icon: Eye,
        num: '3',
        title: 'How we use your information',
        content: 'We use your information to provide, maintain, and improve our services, to authenticate you, to communicate with you, to enforce our terms, and to comply with legal obligations. We do not sell your personal data.',
    },
    {
        icon: FileText,
        num: '4',
        title: 'Data sharing and disclosure',
        content: 'We may share data with service providers that assist in operating our application (e.g. hosting, analytics), when required by law, or to protect our rights and safety. We do not share your data with third parties for their marketing.',
    },
    {
        icon: Lock,
        num: '5',
        title: 'Data retention and security',
        content: 'We retain your data for as long as your account is active or as needed to provide services and comply with legal obligations. We implement appropriate technical and organisational measures to protect your data.',
    },
    {
        icon: UserCheck,
        num: '6',
        title: 'Your rights',
        content: 'Depending on your location, you may have rights to access, correct, delete, or port your data, and to object to or restrict certain processing. You can manage your account and preferences in the app; for other requests, contact us.',
    },
    {
        icon: Cookie,
        num: '7',
        title: 'Cookies',
        content: 'We use necessary cookies for the app to function (e.g. session, preferences). You can manage your cookie preferences via the cookie settings on our website. For full details, see our Cookie Policy.',
    },
    {
        icon: RefreshCw,
        num: '8',
        title: 'Changes',
        content: 'We may update this Privacy Policy from time to time. We will notify you of material changes by posting the updated policy and updating the "Last updated" date.',
    },
    {
        icon: Mail,
        num: '9',
        title: 'Contact',
        content: 'For questions about this Privacy Policy or our data practices, please contact us through the contact details provided in the application or on our website.',
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
                                Last updated: {new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })}
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
                    </div>
                </div>
            </div>
            <CookieConsent />
        </>
    );
}
