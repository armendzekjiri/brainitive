import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Scale, FileText, UserCheck, ShieldAlert, FileCheck, Lock, AlertTriangle, Gavel, XCircle, RefreshCw, Mail, CreditCard, Users } from 'lucide-react';
import ApplicationLogo from '@/components/ApplicationLogo';
import { CookieConsent } from '@/components/CookieConsent';

const sections = [
    {
        icon: Scale,
        num: '1',
        title: 'Agreement to terms',
        content: 'By creating an account or using Brainitive ("Service", "we", "our", "us"), you agree to these Terms of Service ("Terms"). If you do not agree, do not access or use the Service. These Terms apply to all users, including workspace admins, editors, and viewers.',
    },
    {
        icon: FileText,
        num: '2',
        title: 'Description of service',
        content: 'Brainitive is an all-in-one workspace platform providing: Kanban boards with drag-and-drop, priorities, labels, file attachments, and threaded comments; wiki-style pages with rich editors (tables, code, math, slash commands), Excalidraw whiteboards, and public publishing; workspace and project management with role-based access; and bookmark collections. We may add, modify, or remove features with reasonable prior notice.',
    },
    {
        icon: CreditCard,
        num: '3',
        title: 'Plans and billing',
        content: 'These Terms apply to Brainitive Cloud, the hosted service operated by us. Brainitive Cloud is currently in early access: it is free of charge, we do not offer paid plans and we do not collect payment details. During early access the Service is provided as is; the commitments of the Business plan (such as the uptime SLA and backup retention) do not yet apply. We plan to introduce the following plans after early access: Free (€0 – up to 5 members, all features, 1 GB file storage); Team (€6 per user per month, or €5 per user per month billed yearly – unlimited members, 100 GB file storage, email support); Business (€12 per user per month, or €10 per user per month billed yearly – everything in Team plus priority support, a data processing agreement, a 99.9% uptime SLA and daily backups with 30-day restore); Enterprise (custom pricing and a separate agreement). We will announce paid plans at least 30 days before they start, and you will only be charged if you actively choose a paid plan. Once offered, paid plans are billed in advance for the chosen period, prices are exclusive of any applicable taxes, and we will give at least 30 days\' notice of any price change. The Brainitive software itself is open source under the GNU Affero General Public License v3.0; if you run it on your own servers (self-hosting), that licence applies instead of these Terms and no fees are due to us.',
    },
    {
        icon: UserCheck,
        num: '4',
        title: 'Account and eligibility',
        content: 'You must provide accurate and complete registration information and keep it up to date. You must be at least 13 years old, or the minimum age required in your jurisdiction. You are responsible for maintaining the confidentiality of your login credentials and for all activity that occurs under your account. Notify us immediately at hello@brainitive.app if you suspect unauthorised access.',
    },
    {
        icon: Users,
        num: '5',
        title: 'Workspaces and team access',
        content: 'Workspace Admins control member access and roles (Admin, Editor, Viewer). Admins are responsible for ensuring that workspace members comply with these Terms. Brainitive is not responsible for the content, actions, or omissions of workspace members. When you invite someone to a workspace, you grant them access to the workspace\'s projects, boards, pages, and files according to their role.',
    },
    {
        icon: ShieldAlert,
        num: '6',
        title: 'Acceptable use',
        content: 'You agree not to use the Service to: upload or transmit unlawful, harmful, infringing, or fraudulent content; distribute malware or conduct phishing attacks; attempt to gain unauthorised access to our systems or another user\'s account; scrape or harvest data without our written consent; or violate any applicable law or regulation. We may immediately suspend or terminate accounts that breach these rules.',
    },
    {
        icon: FileCheck,
        num: '7',
        title: 'Your content',
        content: 'You retain full ownership of all content you create in Brainitive, including project data, board items, pages, and attachments. By using the Service, you grant us a limited, non-exclusive, royalty-free licence to host, store, process, and display your content solely to provide and improve the Service. You are responsible for ensuring that your content does not infringe third-party rights and that you have all necessary rights to upload it.',
    },
    {
        icon: Lock,
        num: '8',
        title: 'Privacy',
        content: 'Our Privacy Policy, available at www.brainitive.app/privacy, describes how we collect, use, and protect your personal data. It forms part of these Terms. By using the Service you acknowledge and agree to our Privacy Policy.',
    },
    {
        icon: AlertTriangle,
        num: '9',
        title: 'Disclaimers',
        content: 'The Service is provided "as is" and "as available". While we target 99.9% uptime, we do not guarantee uninterrupted, error-free operation. To the fullest extent permitted by applicable law, we disclaim all warranties, express or implied, including merchantability, fitness for a particular purpose, and non-infringement.',
    },
    {
        icon: Gavel,
        num: '10',
        title: 'Limitation of liability',
        content: 'To the maximum extent permitted by applicable law, Brainitive shall not be liable for any indirect, incidental, special, consequential, or punitive damages, including loss of profits, data, or goodwill, arising out of or in connection with your use of, or inability to use, the Service. Our total aggregate liability to you for any claims under these Terms will not exceed the greater of (a) the amount you paid us in the 12 months preceding the claim or (b) €50.',
    },
    {
        icon: XCircle,
        num: '11',
        title: 'Cancellation and termination',
        content: 'You may cancel your subscription or delete your account at any time from your account settings. Cancellation takes effect at the end of the current billing period; you will retain access to paid features until then. We may suspend or terminate your access if you breach these Terms, with notice where reasonably practicable. Upon termination, your workspace data may be deleted after a 30-day grace period.',
    },
    {
        icon: RefreshCw,
        num: '12',
        title: 'Changes to terms',
        content: 'We may update these Terms from time to time. We will notify you of material changes by email or in-app notice at least 14 days before they take effect. Your continued use of the Service after changes take effect constitutes acceptance of the updated Terms.',
    },
    {
        icon: Mail,
        num: '13',
        title: 'Contact',
        content: 'For questions about these Terms, contact us at legal@brainitive.app or at hello@brainitive.app.',
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
                                Last updated: 28 February 2026
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
