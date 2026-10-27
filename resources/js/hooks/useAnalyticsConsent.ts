import { useSyncExternalStore } from 'react';
import { consentState, subscribeConsent, type ConsentState } from '@/lib/analytics/consent';

/** The visitor's statistics choice in this browser, live (BRAI-156, lib/analytics/consent.ts). */
export function useAnalyticsConsent(): ConsentState {
    return useSyncExternalStore(subscribeConsent, consentState, () => 'unset');
}
