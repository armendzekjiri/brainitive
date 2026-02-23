import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { CookieConsent } from '@/components/CookieConsent';

export default function Privacy() {
    return (
        <>
            <Head title="Privacy Policy" />
            <div className="min-h-screen bg-[#0a0a0f] text-white">
                <div className="mx-auto max-w-3xl px-6 py-12">
                    <Link
                        href="/"
                        className="inline-flex items-center gap-2 text-sm text-zinc-400 hover:text-white transition-colors mb-10"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to home
                    </Link>
                    <h1 className="text-3xl font-bold text-white mb-2">Privacy Policy</h1>
                    <p className="text-zinc-500 text-sm mb-12">Last updated: {new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })}</p>

                    <div className="prose prose-invert prose-zinc max-w-none space-y-8 text-zinc-300">
                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">1. Introduction</h2>
                            <p>
                                Brainitive (&quot;we&quot;, &quot;our&quot;, or &quot;us&quot;) respects your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our application and related services.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">2. Information we collect</h2>
                            <p>
                                We may collect information you provide directly (e.g. account registration, profile data, content you create in workspaces, projects, boards, and pages), usage data (e.g. how you use the app), and technical data (e.g. IP address, browser type, device information). We use cookies and similar technologies as described in our cookie notice.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">3. How we use your information</h2>
                            <p>
                                We use your information to provide, maintain, and improve our services, to authenticate you, to communicate with you, to enforce our terms, and to comply with legal obligations. We do not sell your personal data.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">4. Data sharing and disclosure</h2>
                            <p>
                                We may share data with service providers that assist in operating our application (e.g. hosting, analytics), when required by law, or to protect our rights and safety. We do not share your data with third parties for their marketing.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">5. Data retention and security</h2>
                            <p>
                                We retain your data for as long as your account is active or as needed to provide services and comply with legal obligations. We implement appropriate technical and organisational measures to protect your data.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">6. Your rights</h2>
                            <p>
                                Depending on your location, you may have rights to access, correct, delete, or port your data, and to object to or restrict certain processing. You can manage your account and preferences in the app; for other requests, contact us.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">7. Cookies</h2>
                            <p>
                                We use necessary cookies for the app to function (e.g. session, preferences). You can manage your cookie preferences via the cookie settings on our website.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">8. Changes</h2>
                            <p>
                                We may update this Privacy Policy from time to time. We will notify you of material changes by posting the updated policy and updating the &quot;Last updated&quot; date.
                            </p>
                        </section>

                        <section>
                            <h2 className="text-xl font-semibold text-white mt-10 mb-3">9. Contact</h2>
                            <p>
                                For questions about this Privacy Policy or our data practices, please contact us through the contact details provided in the application or on our website.
                            </p>
                        </section>
                    </div>

                    <div className="mt-16 pt-8 border-t border-zinc-800 flex gap-6">
                        <Link href="/" className="text-violet-400 hover:text-violet-300">Home</Link>
                        <Link href={route('terms')} className="text-violet-400 hover:text-violet-300">Terms of Service</Link>
                    </div>
                </div>
            </div>
            <CookieConsent />
        </>
    );
}
