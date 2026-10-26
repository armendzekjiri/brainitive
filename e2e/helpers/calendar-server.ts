import http from 'node:http';
import type { AddressInfo } from 'node:net';

/**
 * A calendar server on 127.0.0.1 (random port) for tests/calendar-links.spec.ts (BRAI-141):
 * - GET /<secret>/calendar.ics — an ICS feed with events in the current month;
 * - /dav/… — a tiny CalDAV server (principal → calendar home → one event calendar + a tasks
 *   list, calendar-query REPORT, GET / PUT / DELETE) behind basic auth.
 * The local e2e app may reach it because env.ts sets CALENDAR_LINKS_ALLOW_PRIVATE (honoured
 * only with APP_ENV=e2e).
 */

export const DAV_USER = 'e2e-user';
export const DAV_PASSWORD = 'e2e-app-password';

const pad = (n: number) => String(n).padStart(2, '0');
/** YYYYMMDD of day `day` of the current month. */
export const icsDay = (day: number) => {
    const now = new Date();
    return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(day)}`;
};

function ics(events: string[], name?: string): string {
    return ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Brainitive e2e//EN', ...(name ? [`X-WR-CALNAME:${name}`] : []), ...events, 'END:VCALENDAR', ''].join('\r\n');
}

/** The ICS feed: an all-day event on the 22nd, a weekly event on the 8th and 15th (COUNT=2), a timed one on the 23rd. */
export function feed(name: string, tag: string): string {
    return ics([
        'BEGIN:VEVENT', `UID:offsite-${tag}`, `DTSTART;VALUE=DATE:${icsDay(22)}`, `DTEND;VALUE=DATE:${icsDay(23)}`, `SUMMARY:Link offsite ${tag}`, 'LOCATION:Lisbon\\, PT', 'END:VEVENT',
        'BEGIN:VEVENT', `UID:weekly-${tag}`, `DTSTART:${icsDay(8)}T090000Z`, `DTEND:${icsDay(8)}T093000Z`, 'RRULE:FREQ=WEEKLY;COUNT=2', `SUMMARY:Link weekly ${tag}`, 'END:VEVENT',
        'BEGIN:VEVENT', `UID:call-${tag}`, `DTSTART:${icsDay(23)}T100000Z`, `DTEND:${icsDay(23)}T110000Z`, `SUMMARY:Link call ${tag}`, 'END:VEVENT',
    ], name);
}

const multistatus = (responses: string) =>
    `<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">${responses}</d:multistatus>`;
const ok = (href: string, props: string) =>
    `<d:response><d:href>${href}</d:href><d:propstat><d:prop>${props}</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>`;
const xmlEscape = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

export interface CalendarServer {
    origin: string;
    /** the secret ICS link (http://127.0.0.1:<port>/<secret>/calendar.ics) */
    icsUrl: (name: string, tag: string) => string;
    /** the CalDAV server URL to type into the connect dialog */
    davUrl: string;
    /** CalDAV objects by path (PUTs land here) */
    objects: Map<string, string>;
    close: () => Promise<void>;
}

export async function startCalendarServer(calendarName: string, tag: string): Promise<CalendarServer> {
    const objects = new Map<string, string>([[
        '/dav/calendars/e2e/work/review.ics',
        ics(['BEGIN:VEVENT', `UID:review-${tag}`, `DTSTART:${icsDay(9)}T140000Z`, `DTEND:${icsDay(9)}T150000Z`, `SUMMARY:CalDAV review ${tag}`, 'END:VEVENT']),
    ]]);
    const feeds = new Map<string, { name: string; tag: string }>();

    const server = http.createServer((req, res) => {
        const chunks: Buffer[] = [];
        req.on('data', (chunk: Buffer) => chunks.push(chunk));
        req.on('end', () => {
            const body = Buffer.concat(chunks).toString('utf8');
            const path = new URL(req.url ?? '/', 'http://localhost').pathname;

            const feedMatch = path.match(/^\/([\w-]+)\/calendar\.ics$/);
            if (req.method === 'GET' && feedMatch && feeds.has(feedMatch[1])) {
                const { name, tag: feedTag } = feeds.get(feedMatch[1])!;
                res.writeHead(200, { 'Content-Type': 'text/calendar; charset=utf-8', ETag: `"${feedTag}"` }).end(feed(name, feedTag));
                return;
            }
            if (!path.startsWith('/dav/')) {
                res.writeHead(404).end();
                return;
            }

            const expected = `Basic ${Buffer.from(`${DAV_USER}:${DAV_PASSWORD}`).toString('base64')}`;
            if (req.headers.authorization !== expected) {
                res.writeHead(401, { 'WWW-Authenticate': 'Basic realm="e2e"' }).end();
                return;
            }

            const xml = (status: number, content: string) => res.writeHead(status, { 'Content-Type': 'application/xml; charset=utf-8' }).end(content);
            if (req.method === 'PROPFIND' && path === '/dav/') {
                return xml(207, multistatus(ok('/dav/', '<d:current-user-principal><d:href>/dav/principals/e2e/</d:href></d:current-user-principal>')));
            }
            if (req.method === 'PROPFIND' && path === '/dav/principals/e2e/') {
                return xml(207, multistatus(ok(path, '<d:displayname>E2E User</d:displayname><c:calendar-home-set><d:href>/dav/calendars/e2e/</d:href></c:calendar-home-set>')));
            }
            if (req.method === 'PROPFIND' && path === '/dav/calendars/e2e/') {
                return xml(207, multistatus(
                    ok(path, '<d:resourcetype><d:collection/></d:resourcetype>')
                    + ok(`${path}work/`, `<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>${xmlEscape(calendarName)}</d:displayname><a:calendar-color>#34C759FF</a:calendar-color><c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set><d:current-user-privilege-set><d:privilege><d:read/></d:privilege><d:privilege><d:write/></d:privilege></d:current-user-privilege-set>`)
                    + ok(`${path}tasks/`, '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Reminders</d:displayname><c:supported-calendar-component-set><c:comp name="VTODO"/></c:supported-calendar-component-set>'),
                ));
            }
            if (req.method === 'REPORT' && path === '/dav/calendars/e2e/work/') {
                const items = [...objects].map(([href, data]) => ok(href, `<d:getetag>"${data.length}"</d:getetag><c:calendar-data>${xmlEscape(data)}</c:calendar-data>`));
                return xml(207, multistatus(items.join('')));
            }
            if (path.startsWith('/dav/calendars/e2e/work/') && path.endsWith('.ics')) {
                if (req.method === 'GET') {
                    const data = objects.get(path);
                    if (!data) return void res.writeHead(404).end();
                    return void res.writeHead(200, { 'Content-Type': 'text/calendar', ETag: `"${data.length}"` }).end(data);
                }
                if (req.method === 'PUT') {
                    objects.set(path, body);
                    return void res.writeHead(201).end();
                }
                if (req.method === 'DELETE') {
                    objects.delete(path);
                    return void res.writeHead(204).end();
                }
            }
            res.writeHead(404).end();
        });
    });
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    const { port } = server.address() as AddressInfo;
    const origin = `http://127.0.0.1:${port}`;

    return {
        origin,
        icsUrl: (name, feedTag) => {
            const secret = `private-${feedTag}`;
            feeds.set(secret, { name, tag: feedTag });
            return `${origin}/${secret}/calendar.ics`;
        },
        davUrl: `${origin}/dav/`,
        objects,
        close: () => new Promise<void>((resolve) => server.close(() => resolve())),
    };
}
