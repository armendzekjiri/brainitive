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
        PHP_CLI_SERVER_WORKERS: process.env.E2E_SERVER_WORKERS ?? '4',
    };
}
