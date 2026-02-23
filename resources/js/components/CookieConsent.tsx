'use client';

import { useState, useEffect } from 'react';
import { Link } from '@inertiajs/react';

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
        const openSettings = () => setShowDetails(true);
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
        setStoredConsent('necessary'); // In a full impl you'd read checkboxes
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
            <div className="mx-auto max-w-3xl rounded-2xl border border-zinc-700 bg-zinc-900/95 backdrop-blur-xl shadow-2xl p-6 text-left">
                {!showDetails ? (
                    <>
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <h3 className="text-lg font-semibold text-white">We use cookies</h3>
                                <p className="mt-1 text-sm text-zinc-400">
                                    We use necessary cookies to run the app and optional ones to improve your experience. You can accept all or only necessary cookies.
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-3 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => setShowDetails(true)}
                                    className="rounded-lg border border-zinc-600 bg-transparent px-4 py-2 text-sm font-medium text-zinc-300 hover:bg-zinc-800 transition-colors"
                                >
                                    Customize
                                </button>
                                <button
                                    type="button"
                                    onClick={acceptNecessary}
                                    className="rounded-lg border border-zinc-600 bg-transparent px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 transition-colors"
                                >
                                    Necessary only
                                </button>
                                <button
                                    type="button"
                                    onClick={acceptAll}
                                    className="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-500 transition-colors"
                                >
                                    Accept all
                                </button>
                            </div>
                        </div>
                        <p className="mt-4 text-xs text-zinc-500">
                            <Link href={route('privacy')} className="underline hover:text-zinc-400">
                                Privacy Policy
                            </Link>
                            {' · '}
                            <Link href={route('terms')} className="underline hover:text-zinc-400">
                                Terms of Service
                            </Link>
                        </p>
                    </>
                ) : (
                    <>
                        <h3 className="text-lg font-semibold text-white">Cookie preferences</h3>
                        <p className="mt-2 text-sm text-zinc-400">
                            Necessary cookies are required for the app to work (e.g. session, preferences). Analytics and marketing cookies are optional.
                        </p>
                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                onClick={() => setShowDetails(false)}
                                className="rounded-lg border border-zinc-600 px-4 py-2 text-sm font-medium text-zinc-300 hover:bg-zinc-800 transition-colors"
                            >
                                Back
                            </button>
                            <button
                                type="button"
                                onClick={savePreferences}
                                className="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-500 transition-colors"
                            >
                                Save preferences
                            </button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
