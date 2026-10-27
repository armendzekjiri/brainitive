/**
 * Where the e2e suite runs (BRAI-96).
 * - Default: a local app started by Playwright (php artisan serve) on an e2e-only PostgreSQL
 *   database, reset + seeded before every run by `php artisan e2e:reset` (refuses any non-e2e
 *   database).
 * - E2E_BASE_URL set (e.g. https://dev.brainitive.app): run against that server instead; no local
 *   server, no reset. E2E_HTTP_USER / E2E_HTTP_PASSWORD answer its basic auth.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const REMOTE = !!process.env.E2E_BASE_URL;
export const PORT = Number(process.env.E2E_PORT ?? 8010);
export const BASE_URL = process.env.E2E_BASE_URL ?? `http://127.0.0.1:${PORT}`;

/** Saved login sessions, per target (parallel local runs on other ports must not share them). */
export const authFile = (role: 'owner' | 'member', worker?: number) =>
    path.join(ROOT, 'e2e', '.auth', `${REMOTE ? new URL(BASE_URL).host : PORT}-${role}${worker === undefined ? '' : `-w${worker}`}.json`);

/** Outgoing mail of the local app (config/logging.php "mail" channel), read by e2e/mail.ts. */
export const MAIL_LOG = path.join(ROOT, 'storage', 'e2e', `mail-${PORT}.log`);

/** Test-only password of the seeded users (database/seeders/E2eSeeder.php). */
export const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-password';

export const USERS = {
    owner: { name: 'Erin Owner', email: 'e2e-owner@example.test' },
    member: { name: 'Max Member', email: 'e2e-member@example.test' },
} as const;
export type Role = keyof typeof USERS;

/** Email → helpdesk forwarding addresses in the local e2e app (BRAI-124); test-only values. */
export const INBOUND_MAIL = { domain: 'inbound.e2e.test', postmarkToken: 'e2e-postmark-token' } as const;

/** Fake Outlook app registration of the local e2e app (BRAI-140): enables the menu entry only. */
export const OUTLOOK_E2E = { clientId: 'e2e-outlook-client' } as const;

/**
 * Live co-editing (BRAI-54): live-docs.spec.ts starts the realtime server on this port and opts in with
 * the `X-E2E-Collab: 1` header; every other journey keeps the single-user editor (COLLAB_E2E_OPT_IN).
 */
export const COLLAB = { port: PORT + 100, secret: 'e2e-collab-secret' } as const;

/** Web push in the local e2e app (BRAI-154): test-only VAPID public key (the private one is in appEnv). */
export const PUSH_E2E = { vapidPublicKey: 'BDk2GPkD9AFgfvh6G-rXWxZTv1MEdII6HUA_5Eqj9ExwaiTarukhiYVdWBaHOKwLYMEVVid2DA5o7jg3q1gIxKY' } as const;
/**
 * Live channels (BRAI-151): global-setup starts one realtime server on this port for the whole run
 * (live-docs.spec.ts keeps its own on COLLAB.port: it stops / restarts it). Specs opt their browser
 * contexts in with `X-E2E-Live: 1` (e2e/helpers/live.ts); every other journey runs without live.
 */
export const LIVE = { port: PORT + 101 } as const;

/** Seeded workspace / project / board (slugs). */
export const SEED = { workspace: 'e2e-workspace', project: 'website', board: 'main-board', key: 'WEB' } as const;

/**
 * The e2e database: PostgreSQL like production. Locally one per port (`brainitive_e2e_<port>` on
 * the local server, created by global-setup); CI passes its service database via E2E_DB_*.
 */
export const DB = {
    // the local Unix socket when there is one: no TCP port per connection
    host: process.env.E2E_DB_HOST ?? (fs.existsSync('/tmp/.s.PGSQL.5432') ? '/tmp' : '127.0.0.1'),
    port: process.env.E2E_DB_PORT ?? '5432',
    database: process.env.E2E_DB_DATABASE ?? `brainitive_e2e_${PORT}`,
    username: process.env.E2E_DB_USERNAME ?? process.env.USER ?? 'postgres',
    password: process.env.E2E_DB_PASSWORD ?? '',
};

/** Environment of the local app + the reset: never the developer's .env database. */
export function appEnv(): Record<string, string> {
    return {
        ...(process.env as Record<string, string>),
        DB_CONNECTION: 'pgsql',
        DB_HOST: DB.host,
        DB_PORT: DB.port,
        DB_DATABASE: DB.database,
        DB_USERNAME: DB.username,
        DB_PASSWORD: DB.password,
        APP_ENV: 'e2e',
        APP_DEBUG: 'true',
        APP_URL: BASE_URL,
        SESSION_SECURE_COOKIE: 'false',
        SESSION_DRIVER: 'database',
        CACHE_STORE: 'database',
        QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'log',
        MAIL_LOG_CHANNEL: 'mail',
        MAIL_LOG_PATH: MAIL_LOG,
        BROADCAST_CONNECTION: 'log',
        PULSE_ENABLED: 'false',
        TELESCOPE_ENABLED: 'false',
        NIGHTWATCH_ENABLED: 'false',
        E2E_PASSWORD: PASSWORD,
        // webhooks.spec.ts delivers to a receiver on 127.0.0.1; the app honours this only with APP_ENV=e2e
        WEBHOOKS_ALLOW_PRIVATE_TARGETS: 'true',
        // calendar-links.spec.ts serves an ICS feed + a CalDAV server on 127.0.0.1; honoured only with APP_ENV=e2e
        CALENDAR_LINKS_ALLOW_PRIVATE: 'true',
        // helpdesk-forwarding.spec.ts: forwarding addresses on (Postmark endpoint, test token)
        INBOUND_MAIL_DOMAIN: INBOUND_MAIL.domain,
        INBOUND_MAIL_PROVIDER: 'postmark',
        INBOUND_MAIL_POSTMARK_TOKEN: INBOUND_MAIL.postmarkToken,
        // helpdesk-email.spec.ts: a fake IMAP / SMTP connection (no network); honoured only with APP_ENV=e2e
        HELPDESK_MAIL_FAKE: 'true',
        // github-app.spec.ts: a fake GitHub App + in-process GitHub API (BRAI-61); honoured only with APP_ENV=e2e
        GITHUB_APP_FAKE: 'true',
        // calendar-outlook.spec.ts + microsoft-login.spec.ts: a Microsoft app registration that only has to exist (Microsoft is never called)
        MICROSOFT_CLIENT_ID: OUTLOOK_E2E.clientId,
        MICROSOFT_CLIENT_SECRET: 'e2e-outlook-secret',
        MICROSOFT_TENANT: 'common',
        MICROSOFT_REDIRECT_URI: '',
        // live-docs.spec.ts: the realtime server it starts itself; honoured only with the opt-in header
        COLLAB_URL: `ws://127.0.0.1:${COLLAB.port}`,
        COLLAB_SECRET: COLLAB.secret,
        COLLAB_E2E_OPT_IN: 'true',
        // push-devices.spec.ts (BRAI-154): a test-only VAPID pair (signs nothing real) + the fake push service;
        // WEBPUSH_FAKE is honoured only with APP_ENV=e2e
        VAPID_SUBJECT: 'mailto:e2e@example.test',
        VAPID_PUBLIC_KEY: PUSH_E2E.vapidPublicKey,
        VAPID_PRIVATE_KEY: 'icnBIwSAna7_jPM3xbRAe9fYjkjZUzGkdb-gzFfHMQA',
        WEBPUSH_FAKE: 'true',
        // live channels: the server global-setup starts; browsers only with the `X-E2E-Live: 1` opt-in
        LIVE_URL: `ws://127.0.0.1:${LIVE.port}/live`,
        LIVE_PUBLISH_URL: `http://127.0.0.1:${LIVE.port}`,
        // legal.spec.ts (BRAI-156): Brainitive Cloud's legal pages (imprint, DPA, sub-processors) like production
        LEGAL_CLOUD: 'true',
        // analytics-consent.spec.ts: requests with `X-E2E-Analytics: 1` count as brainitive.app for analytics (consent
        // notice + Umami config); honoured only with APP_ENV=e2e. The spec intercepts the stats host: nothing leaves.
        ANALYTICS_E2E_OPT_IN: 'true',
        PHP_CLI_SERVER_WORKERS: process.env.E2E_SERVER_WORKERS ?? '4',
    };
}
