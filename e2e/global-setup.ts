import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { DB, MAIL_LOG, REMOTE, ROOT, appEnv } from './env';
import { startLiveServer } from './helpers/live';

/**
 * Fresh, seeded e2e database before every local run (guarded: e2e databases only), and the live
 * channel server (BRAI-151) for the run; the returned function stops it.
 */
export default async function globalSetup() {
    if (REMOTE) return;
    if (!process.env.E2E_DB_DATABASE) {
        // local: this port's own database on the local PostgreSQL (CI's service database exists already)
        const psql = (sql: string) =>
            execFileSync('psql', ['-h', DB.host, '-p', DB.port, '-U', DB.username, '-d', 'postgres', '-tAc', sql], {
                env: { ...process.env, PGPASSWORD: DB.password },
            }).toString().trim();
        if (psql(`select 1 from pg_database where datname = '${DB.database}'`) !== '1') psql(`create database "${DB.database}"`);
    }
    fs.rmSync(MAIL_LOG, { force: true });
    execFileSync('php', ['artisan', 'e2e:reset'], { cwd: ROOT, env: appEnv(), stdio: 'inherit' });
    return startLiveServer();
}
