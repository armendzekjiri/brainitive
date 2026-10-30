import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, RotateCcw, Clock, CheckCircle, XCircle, CreditCard, Mail, AlertTriangle, HelpCircle } from 'lucide-react';
import ApplicationLogo from '@/components/ApplicationLogo';

/*
 * Refund policy of Brainitive Cloud (v1.46.0): a 14-day money-back guarantee on the first payment of a
 * paid plan, no questions asked; after that no refunds (renewals, unused time) except billing mistakes and
 * what the law requires. Paid plans are sold by Paddle (merchant of record), so refunds go through Paddle.
 * DRAFT, to be confirmed by a lawyer.
 */
const sections = [
    {
        icon: CheckCircle,
        num: '1',
        title: '14-day money-back guarantee',
        content: 'Not happy with a paid plan (Starter, Team, Business)? Ask for a refund within 14 days of the first payment of that plan and you get it in full — no questions asked. The workspace then goes back to the Free plan; nothing in it is deleted.',
        highlight: true,
    },
    {
        icon: XCircle,
        num: '2',
        title: 'After 14 days: no refunds',
        content: 'Brainitive has a Free plan with every feature, so you can try everything before you pay. After the first 14 days, payments are not refunded: not for automatic renewals, not for the rest of a month or year after you cancel, and not for members who left or storage you did not use.',
    },
    {
        icon: Clock,
        num: '3',
        title: 'Cancel at any time',
        content: 'A workspace admin can cancel under Workspace settings → Plan & billing at any time. The plan stays active until the end of the period you already paid for, and the workspace then continues on the Free plan. To avoid a renewal charge, cancel before the renewal date. Nothing in the workspace is deleted for 90 days after a cancellation.',
    },
    {
        icon: RotateCcw,
        num: '4',
        title: 'Fewer members',
        content: 'Paid plans are charged per member. When members leave a workspace, the difference is credited against your next payment, not paid out.',
    },
    {
        icon: CheckCircle,
        num: '5',
        title: 'Other refunds',
        content: 'Beyond the 14-day guarantee we refund (a) payments taken by mistake — for example a double charge, a charge after a cancellation that had already taken effect, or a technical error on our side; (b) prepaid time we cannot provide because we end the service ourselves without a reason you are responsible for; and (c) whatever the law requires. If you are a consumer, your statutory right of withdrawal is described in Paddle’s buyer terms and in section “Right of withdrawal” of our Terms of Use.',
    },
    {
        icon: CreditCard,
        num: '6',
        title: 'How to ask for a refund',
        content: 'Paid plans are sold by our reseller Paddle.com (merchant of record). Write to billing@brainitive.app with your account e-mail and the invoice number, or contact Paddle directly through the link in your receipt (paddle.net). Approved refunds are made by Paddle to the original payment method, usually within 5–10 business days.',
    },
    {
        icon: AlertTriangle,
        num: '7',
        title: 'Accounts closed for violations',
        content: 'If a workspace or account is closed because of a serious violation of our Terms of Use, unused prepaid time is not refunded.',
    },
    {
        icon: HelpCircle,
        num: '8',
        title: 'Chargebacks',
        content: 'Please contact us before disputing a payment with your bank — most billing questions are solved within a day. A chargeback suspends the paid plan until it is resolved.',
    },
    {
        icon: Mail,
        num: '9',
        title: 'Contact',
        content: 'Billing and refunds: billing@brainitive.app. Everything else: hello@brainitive.app. We usually answer within 1–2 business days.',
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
                    <a
                        href="/"
                        className="inline-flex items-center gap-2 text-sm font-medium text-[#485E72]/60 hover:text-[#0E83DC] transition-colors mb-10"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to home
                    </a>

                    {/* Header */}
                    <div className="flex items-center gap-4 mb-2">
                        <div className="flex items-center justify-center h-12 w-12 rounded-xl bg-[#69A748]/10 text-[#69A748]">
                            <RotateCcw className="h-6 w-6" />
                        </div>
                        <div>
                            <h1 className="text-3xl font-bold text-[#485E72]">Refund Policy</h1>
                            <p className="text-sm text-[#485E72]/50 mt-1">
                                Last updated: 30 October 2026
                            </p>
                        </div>
                    </div>

                    {/* 14-day guarantee banner */}
                    <div className="mt-8 rounded-2xl border-2 border-[#69A748]/30 bg-[#69A748]/5 p-5 flex items-start gap-4">
                        <div className="flex items-center justify-center h-10 w-10 rounded-xl bg-[#69A748]/15 text-[#69A748] shrink-0">
                            <CheckCircle className="h-5 w-5" />
                        </div>
                        <div>
                            <p className="font-bold text-[#485E72]">14-day money-back guarantee</p>
                            <p className="text-sm text-[#485E72]/60 mt-1">
                                Not happy? Ask within 14 days of your first payment for a plan and we refund it in full — no questions asked. Paid plans
                                can be cancelled at any time and run until the end of the paid period.
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
                        <a href="/" className="inline-flex items-center gap-2 text-sm font-semibold text-[#0E83DC] hover:text-[#0b6fbf] transition-colors">
                            <ApplicationLogo className="h-5 w-auto" alt="" /> Home
                        </a>
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
        </>
    );
}
