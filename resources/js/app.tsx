// first: remembers the address before Inertia rewrites it (offline shell, BRAI-82)
import './lib/offline/bootLocation';
import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { AnalyticsConsentNotice } from './components/legal/AnalyticsConsent';
import { initAnalytics } from './lib/analytics/tracker';
import { watchOfflineSession } from './lib/offline/session';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Offline mode (BRAI-82): per account and device; cleared on sign-out / another account
watchOfflineSession();

// Our own statistics (Umami, BRAI-156): only where the server allows it and only after "Allow"
initAnalytics();

// Register service worker for PWA install, push and offline mode (mobile & desktop)
if (typeof window !== 'undefined' && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <>
                <App {...props} />
                <AnalyticsConsentNotice />
            </>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});
