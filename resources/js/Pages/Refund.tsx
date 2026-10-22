import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, RotateCcw, Clock, CheckCircle, XCircle, CreditCard, Mail, AlertTriangle, HelpCircle } from 'lucide-react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { CookieConsent } from '@/components/CookieConsent';

const sections = [
    {
        icon: RotateCcw,
        num: '1',
        title: '7-day money-back guarantee',
        content: 'We offer a full 7-day money-back guarantee on all paid Brainitive Cloud plans (Team and Business). If you are not satisfied with your purchase for any reason, contact us within 7 days of your initial payment and we will issue a full refund — no questions asked. This applies to first-time purchases only.',
        highlight: true,
    },
    {
        icon: CheckCircle,
        num: '2',
        title: 'Eligibility',
        content: 'To be eligible for a refund under the 7-day guarantee, you must: (a) be making your first purchase of the plan in question, (b) submit your refund request within 7 calendar days of the original payment date, and (c) contact us via email at billing@brainitive.com with the email address associated with your account.',
    },
    {
        icon: Clock,
        num: '3',
        title: 'Renewal charges',
        content: 'After the initial 7-day period, subscription renewals are generally non-refundable. If you wish to avoid a renewal charge, you must cancel your subscription before the renewal date. Cancellation instructions are available in your account settings under Billing. You will retain access to premium features until the end of the paid billing period.',
    },
    {
        icon: CreditCard,
        num: '4',
        title: 'How to request a refund',
        content: 'Send an email to billing@brainitive.com with the subject "Refund Request" and include: your account email address, the plan you purchased (Team or Business), the date of purchase, and a brief reason for the refund (optional). We will process your request within 5 business days. Refunds are returned to the original payment method.',
    },
    {
        icon: XCircle,
        num: '5',
        title: 'Non-refundable situations',
        content: 'The following are not eligible for refunds: subscription renewals after the initial 7-day window; partial months or unused days remaining in a billing period after cancellation; Custom/Enterprise plan payments (these are governed by a separate agreement); and any plan purchased after a prior refund was already issued on the same account.',
    },
    {
        icon: AlertTriangle,
        num: '6',
        title: 'Accounts terminated for policy violations',
        content: 'If your account is suspended or terminated due to a violation of our Terms of Service or Acceptable Use Policy, you are not entitled to a refund for any unused portion of your subscription.',
    },
    {
        icon: HelpCircle,
        num: '7',
        title: 'Chargebacks',
        content: 'We ask that you contact us to resolve billing issues before initiating a chargeback with your bank or payment provider. Chargebacks that are contested and ruled in our favour may result in account suspension. We\'re committed to resolving all genuine billing disputes fairly and quickly.',
    },
    {
        icon: Mail,
        num: '8',
        title: 'Contact',
        content: 'For any billing or refund enquiries, contact us at billing@brainitive.com. For general support, reach us at hello@brainitive.com. We typically respond within 1–2 business days.',
    },
];

export default function Refund() {
    return (
        <>
            <Head title="Refund Policy – Brainitive" />
            <div className="min-h-screen bg-[#F4F6F8] text-[#485E72]">
                {/* Ambient blobs */}
                <div className="fixed inset-0 pointer-events-none overflow-hidden">
                    <div className="absolute -top-32 left-0 w-[500px] h-[500px] rounded-full bg-[#69A748]/[0.05] blur-[100px]" />
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
                        <div className="flex items-center justify-center h-12 w-12 rounded-xl bg-[#69A748]/10 text-[#69A748]">
                            <RotateCcw className="h-6 w-6" />
                        </div>
                        <div>
                            <h1 className="text-3xl font-bold text-[#485E72]">Refund Policy</h1>
                            <p className="text-sm text-[#485E72]/50 mt-1">
                                Last updated: 28 February 2026
                            </p>
                        </div>
                    </div>

                    {/* 7-day highlight banner */}
                    <div className="mt-8 rounded-2xl border-2 border-[#69A748]/30 bg-[#69A748]/5 p-5 flex items-start gap-4">
                        <div className="flex items-center justify-center h-10 w-10 rounded-xl bg-[#69A748]/15 text-[#69A748] shrink-0">
                            <CheckCircle className="h-5 w-5" />
                        </div>
                        <div>
                            <p className="font-bold text-[#485E72]">7-day money-back guarantee</p>
                            <p className="text-sm text-[#485E72]/60 mt-1">
                                Not happy? We'll refund your first payment in full — no questions asked — if you contact us within 7 days of purchase.
                            </p>
                        </div>
                    </div>

                    {/* Sections */}
                    <div className="mt-8 space-y-6">
                        {sections.map((s) => (
                            <div
                                key={s.num}
                                className={`rounded-2xl border p-6 transition-all hover:shadow-lg ${s.highlight
                                    ? 'border-[#69A748]/30 bg-[#69A748]/5 hover:bg-[#69A748]/8 hover:shadow-[#69A748]/[0.06]'
                                    : 'border-[#C0C5C0]/30 bg-white/70 backdrop-blur-sm hover:bg-white hover:shadow-[#485E72]/[0.04]'
                                    }`}
                            >
                                <div className="flex items-start gap-4">
                                    <div className={`flex items-center justify-center h-9 w-9 rounded-lg shrink-0 mt-0.5 ${s.highlight ? 'bg-[#69A748]/15 text-[#69A748]' : 'bg-[#69A748]/10 text-[#69A748]'}`}>
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
