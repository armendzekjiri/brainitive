'use client';

import { useState, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import { Cookie, Settings, ShieldCheck, ChevronRight } from 'lucide-react';

const STORAGE_KEY = 'brainitive_cookie_consent';
const CONSENT_EXPIRY_DAYS = 365;

export type CookieConsentStatus = 'necessary' | 'analytics' | 'marketing' | 'all' | 'none';

function getStoredConsent(): CookieConsentStatus | null {
    if (typeof window === 'undefined') return null;
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const { status, expires } = JSON.parse(raw) as { status: CookieConsentStatus; expires: number };
        if (expires && Date.now() > expires) {
            localStorage.removeItem(STORAGE_KEY);
            return null;
        }
        return status;
    } catch {
        return null;
    }
}

function setStoredConsent(status: CookieConsentStatus) {
    const expires = Date.now() + CONSENT_EXPIRY_DAYS * 24 * 60 * 60 * 1000;
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ status, expires }));
}

export function CookieConsent() {
    const [visible, setVisible] = useState(false);
    const [showDetails, setShowDetails] = useState(false);
    const [mounted, setMounted] = useState(false);

    useEffect(() => {
        setMounted(true);
    }, []);

    useEffect(() => {
        if (!mounted) return;
        const consent = getStoredConsent();
        setVisible(consent === null);
    }, [mounted]);

    useEffect(() => {
        const openSettings = () => {
            setVisible(true);
            setShowDetails(true);
        };
        window.addEventListener('brainitive-open-cookie-settings', openSettings);
        return () => window.removeEventListener('brainitive-open-cookie-settings', openSettings);
    }, []);

    const acceptAll = () => {
        setStoredConsent('all');
        setVisible(false);
        setShowDetails(false);
    };

    const acceptNecessary = () => {
        setStoredConsent('necessary');
        setVisible(false);
        setShowDetails(false);
    };

    const savePreferences = () => {
        setStoredConsent('necessary');
        setVisible(false);
        setShowDetails(false);
    };

    if (!visible) return null;

    return (
        <div
            className="fixed bottom-0 left-0 right-0 z-[100] p-4 sm:p-6 animate-fade-in-up"
            role="dialog"
            aria-label="Cookie consent"
        >
            <div className="mx-auto max-w-3xl rounded-2xl border border-[#C0C5C0]/40 bg-white/95 backdrop-blur-xl shadow-2xl shadow-[#485E72]/10 p-6 text-left">
                {!showDetails ? (
                    <>
                        <div className="flex flex-col sm:flex-row sm:items-start gap-4">
                            <div className="flex items-center justify-center h-10 w-10 rounded-xl bg-[#0E83DC]/10 text-[#0E83DC] shrink-0 mt-0.5">
                                <Cookie className="h-5 w-5" />
                            </div>
                            <div className="flex-1">
                                <h3 className="text-lg font-bold text-[#485E72]">We use cookies</h3>
                                <p className="mt-1 text-sm text-[#485E72]/60 leading-relaxed">
                                    We use necessary cookies to run the app and optional ones to improve your experience.
                                    You can accept all or only necessary cookies.
                                </p>
                            </div>
                        </div>
                        <div className="mt-5 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                onClick={() => setShowDetails(true)}
                                className="inline-flex items-center gap-1.5 rounded-xl border-2 border-[#C0C5C0]/50 px-4 py-2.5 text-sm font-semibold text-[#485E72] hover:border-[#0E83DC]/30 hover:text-[#0E83DC] transition-all"
                            >
                                <Settings className="h-3.5 w-3.5" />
                                Customize
                            </button>
                            <button
                                type="button"
                                onClick={acceptNecessary}
                                className="inline-flex items-center gap-1.5 rounded-xl border-2 border-[#C0C5C0]/50 px-4 py-2.5 text-sm font-semibold text-[#485E72] hover:border-[#0E83DC]/30 hover:text-[#0E83DC] transition-all"
                            >
                                <ShieldCheck className="h-3.5 w-3.5" />
                                Necessary only
                            </button>
                            <button
                                type="button"
                                onClick={acceptAll}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-[#0E83DC] px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-[#0E83DC]/20 hover:bg-[#0b6fbf] transition-all"
                            >
                                Accept all
                                <ChevronRight className="h-3.5 w-3.5" />
                            </button>
                        </div>
                        <p className="mt-4 text-xs text-[#485E72]/40">
                            <Link href="/privacy" className="hover:text-[#0E83DC] underline transition-colors">
                                Privacy Policy
                            </Link>
                            {' · '}
                            <Link href="/terms" className="hover:text-[#0E83DC] underline transition-colors">
                                Terms of Service
                            </Link>
                            {' · '}
                            <Link href="/cookies" className="hover:text-[#0E83DC] underline transition-colors">
                                Cookie Policy
                            </Link>
                        </p>
                    </>
                ) : (
                    <>
                        <div className="flex items-start gap-3">
                            <div className="flex items-center justify-center h-10 w-10 rounded-xl bg-[#69A748]/10 text-[#69A748] shrink-0 mt-0.5">
                                <Settings className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="text-lg font-bold text-[#485E72]">Cookie preferences</h3>
                                <p className="mt-1 text-sm text-[#485E72]/60 leading-relaxed">
                                    Necessary cookies are required for sessions and preferences. Analytics and marketing cookies are optional.
                                </p>
                            </div>
                        </div>

                        <div className="mt-5 space-y-3">
                            <div className="flex items-center justify-between rounded-xl border border-[#C0C5C0]/30 bg-[#F4F6F8] p-4">
                                <div>
                                    <div className="text-sm font-semibold text-[#485E72]">Necessary</div>
                                    <div className="text-xs text-[#485E72]/50">Session, CSRF, preferences</div>
                                </div>
                                <span className="text-xs font-bold text-[#69A748] bg-[#69A748]/10 px-2.5 py-1 rounded-full">Always on</span>
                            </div>
                            <div className="flex items-center justify-between rounded-xl border border-[#C0C5C0]/30 bg-[#F4F6F8] p-4">
                                <div>
                                    <div className="text-sm font-semibold text-[#485E72]">Analytics</div>
                                    <div className="text-xs text-[#485E72]/50">Usage statistics &amp; improvements</div>
                                </div>
                                <span className="text-xs font-medium text-[#485E72]/50 bg-[#C0C5C0]/20 px-2.5 py-1 rounded-full">Optional</span>
                            </div>
                            <div className="flex items-center justify-between rounded-xl border border-[#C0C5C0]/30 bg-[#F4F6F8] p-4">
                                <div>
                                    <div className="text-sm font-semibold text-[#485E72]">Marketing</div>
                                    <div className="text-xs text-[#485E72]/50">Personalized content</div>
                                </div>
                                <span className="text-xs font-medium text-[#485E72]/50 bg-[#C0C5C0]/20 px-2.5 py-1 rounded-full">Optional</span>
                            </div>
                        </div>

                        <div className="mt-5 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                onClick={() => setShowDetails(false)}
                                className="inline-flex items-center gap-1.5 rounded-xl border-2 border-[#C0C5C0]/50 px-4 py-2.5 text-sm font-semibold text-[#485E72] hover:border-[#0E83DC]/30 transition-all"
                            >
                                Back
                            </button>
                            <button
                                type="button"
                                onClick={savePreferences}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-[#0E83DC] px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-[#0E83DC]/20 hover:bg-[#0b6fbf] transition-all"
                            >
                                Save preferences
                                <ChevronRight className="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
