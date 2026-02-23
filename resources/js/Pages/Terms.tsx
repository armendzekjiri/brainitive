import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { CookieConsent } from '@/components/CookieConsent';

export default function Terms() {
    return (
        <>
            <Head title="Terms of Service" />
            <div className="min-h-screen bg-[#0a0a0f] text-white">
                <div className="mx-auto max-w-3xl px-6 py-12">
                    <Link
                        href="/"
                        className="inline-flex items-center gap-2 text-sm text-zinc-400 hover:text-white transition-colors mb-10"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to home
                    </Link>
                    <h1 className="text-3xl font-bold text-white mb-2">Terms of Service</h1>
                    <p className="text-zinc-500 text-sm mb-12">Last updated: {new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })}</p>

                    <div className="prose prose-invert prose-zinc max-w-none space-y-8 text-zinc-300">
                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">1. Agreement to terms</h2>
                            <p>
                                By accessing or using Brainitive (&quot;Service&quot;), you agree to be bound by these Terms of Service. If you do not agree, do not use the Service.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">2. Description of service</h2>
                            <p>
                                Brainitive provides workspaces, projects, Kanban boards, wiki-style pages, and related collaboration features. We may modify or discontinue features with reasonable notice where practicable.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">3. Account and eligibility</h2>
                            <p>
                                You must provide accurate registration information and keep your account secure. You are responsible for all activity under your account. You must be at least 13 years old (or the minimum age in your jurisdiction) to use the Service.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">4. Acceptable use</h2>
                            <p>
                                You agree not to use the Service for any illegal purpose, to violate others&apos; rights, to distribute malware, or to attempt to gain unauthorised access to our or others&apos; systems or data. We may suspend or terminate accounts that violate these terms.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">5. Your content</h2>
                            <p>
                                You retain ownership of content you create. By using the Service, you grant us a licence to host, store, and display your content as necessary to provide and improve the Service. You are responsible for ensuring you have the rights to any content you upload.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">6. Privacy</h2>
                            <p>
                                Our collection and use of personal data is described in our{' '}
                                <Link href={route('privacy')} className="text-violet-400 hover:text-violet-300 underline">
                                    Privacy Policy
                                </Link>
                                , which forms part of these terms.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">7. Disclaimers</h2>
                            <p>
                                The Service is provided &quot;as is&quot; and &quot;as available&quot;. We do not warrant that the Service will be uninterrupted or error-free. To the fullest extent permitted by law, we disclaim all warranties, express or implied.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">8. Limitation of liability</h2>
                            <p>
                                To the maximum extent permitted by law, we shall not be liable for any indirect, incidental, special, consequential, or punitive damages, or loss of data or profits, arising from your use of the Service.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">9. Termination</h2>
                            <p>
                                You may stop using the Service at any time. We may suspend or terminate your access if you breach these terms or for other operational or legal reasons, with notice where reasonable.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">10. Changes to terms</h2>
                            <p>
                                We may update these Terms from time to time. We will notify you of material changes by posting the updated terms and updating the &quot;Last updated&quot; date. Continued use after changes constitutes acceptance.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">11. Contact</h2>
                            <p>
                                For questions about these Terms, please contact us through the contact details provided in the application or on our website.
                            </p>
                        </section>
                    </div>

                    <div className="mt-16 pt-8 border-t border-zinc-800 flex gap-6">
                        <Link href="/" className="text-violet-400 hover:text-violet-300">Home</Link>
                        <Link href={route('privacy')} className="text-violet-400 hover:text-violet-300">Privacy Policy</Link>
                    </div>
                </div>
            </div>
            <CookieConsent />
        </>
    );
}
