import fs from 'node:fs';
import { MAIL_LOG, REMOTE } from './env';

/**
 * Mail sent by the local e2e app (MAIL_MAILER=log → storage/e2e/mail-<port>.log): find the
 * newest message to an address and pull links out of it, so journeys can follow invitation and
 * verification emails like a real person. Not available against a remote server.
 */
export const canReadMail = !REMOTE;

// Laravel's log transport already decodes quoted-printable bodies before writing them.

function messagesTo(address: string): string[] {
    if (!fs.existsSync(MAIL_LOG)) return [];
    // every logged message starts with a log line, then the MIME headers
    return fs
        .readFileSync(MAIL_LOG, 'utf8')
        .split(/\n(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/)
        .filter((m) => new RegExp(`^To: .*${address.replace(/[.+]/g, '\\$&')}`, 'mi').test(m));
}

/** The first link matching `pattern` in the newest mail to `address` (waits up to 10 s). */
export async function mailLink(address: string, pattern: RegExp): Promise<string> {
    const deadline = Date.now() + 10_000;
    while (Date.now() < deadline) {
        const newest = messagesTo(address).at(-1);
        const link = newest?.match(/https?:\/\/[^\s"'<>)\]]+/g)?.map((l) => l.replace(/&amp;/g, '&')).find((l) => pattern.test(l));
        if (link) return link;
        await new Promise((r) => setTimeout(r, 250));
    }
    throw new Error(`no mail to ${address} with a link matching ${pattern} in ${MAIL_LOG}`);
}
