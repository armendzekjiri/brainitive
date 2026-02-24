import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Scale, FileText, UserCheck, ShieldAlert, FileCheck, Lock, AlertTriangle, Gavel, XCircle, RefreshCw, Mail } from 'lucide-react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { CookieConsent } from '@/components/CookieConsent';

const sections = [
    {
        icon: Scale,
        num: '1',
        title: 'Agreement to terms',
        content: 'By accessing or using Brainitive ("Service"), you agree to be bound by these Terms of Service. If you do not agree, do not use the Service.',
    },
    {
        icon: FileText,
        num: '2',
        title: 'Description of service',
        content: 'Brainitive provides workspaces, projects, Kanban boards, wiki-style pages, bookmarks, and related collaboration features. We may modify or discontinue features with reasonable notice where practicable.',
    },
    {
        icon: UserCheck,
        num: '3',
        title: 'Account and eligibility',
        content: 'You must provide accurate registration information and keep your account secure. You are responsible for all activity under your account. You must be at least 13 years old (or the minimum age in your jurisdiction) to use the Service.',
    },
    {
        icon: ShieldAlert,
        num: '4',
        title: 'Acceptable use',
        content: "You agree not to use the Service for any illegal purpose, to violate others' rights, to distribute malware, or to attempt to gain unauthorised access to our or others' systems or data. We may suspend or terminate accounts that violate these terms.",
    },
    {
        icon: FileCheck,
        num: '5',
        title: 'Your content',
        content: 'You retain ownership of content you create. By using the Service, you grant us a licence to host, store, and display your content as necessary to provide and improve the Service. You are responsible for ensuring you have the rights to any content you upload.',
    },
    {
        icon: Lock,
        num: '6',
        title: 'Privacy',
        content: 'Our collection and use of personal data is described in our Privacy Policy, which forms part of these terms.',
    },
    {
        icon: AlertTriangle,
        num: '7',
        title: 'Disclaimers',
        content: 'The Service is provided "as is" and "as available". We do not warrant that the Service will be uninterrupted or error-free. To the fullest extent permitted by law, we disclaim all warranties, express or implied.',
    },
    {
        icon: Gavel,
        num: '8',
        title: 'Limitation of liability',
        content: 'To the maximum extent permitted by law, we shall not be liable for any indirect, incidental, special, consequential, or punitive damages, or loss of data or profits, arising from your use of the Service.',
    },
    {
        icon: XCircle,
        num: '9',
        title: 'Termination',
        content: 'You may stop using the Service at any time. We may suspend or terminate your access if you breach these terms or for other operational or legal reasons, with notice where reasonable.',
    },
    {
        icon: RefreshCw,
        num: '10',
        title: 'Changes to terms',
        content: 'We may update these Terms from time to time. We will notify you of material changes by posting the updated terms and updating the "Last updated" date. Continued use after changes constitutes acceptance.',
    },
    {
        icon: Mail,
        num: '11',
        title: 'Contact',
        content: 'For questions about these Terms, please contact us through the contact details provided in the application or on our website.',
    },
];

export default function Terms() {
    return (
        <>
            <Head title="Terms of Service – Brainitive" />
            <div className="min-h-screen bg-[#F4F6F8] text-[#485E72]">
                {/* Ambient blobs */}
                <div className="fixed inset-0 pointer-events-none overflow-hidden">
                    <div className="absolute -top-32 -left-32 w-[500px] h-[500px] rounded-full bg-[#69A748]/[0.05] blur-[100px]" />
                    <div className="absolute bottom-0 right-0 w-[400px] h-[400px] rounded-full bg-[#0E83DC]/[0.04] blur-[80px]" />
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
                        <div className="flex items-center justify-center h-12 w-12 rounded-xl bg-[#485E72]/10 text-[#485E72]">
                            <Scale className="h-6 w-6" />
                        </div>
                        <div>
                            <h1 className="text-3xl font-bold text-[#485E72]">Terms of Service</h1>
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
                                    <div className="flex items-center justify-center h-9 w-9 rounded-lg bg-[#485E72]/10 text-[#485E72] shrink-0 mt-0.5">
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
                        <Link href={route('privacy')} className="text-sm font-semibold text-[#0E83DC] hover:text-[#0b6fbf] transition-colors">
                            Privacy Policy
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
