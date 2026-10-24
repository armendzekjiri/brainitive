import type { Page } from '@playwright/test';
import { expect } from '../fixtures';
import { pageProps } from './helpdesk';

/** Helpers for the helpdesk SLA journeys (tests/helpdesk-sla.spec.ts, BRAI-125). */

async function headers(page: Page, json = false): Promise<Record<string, string>> {
    const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!cookie) throw new Error('no XSRF-TOKEN cookie: open an app page first');
    return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value), Accept: json ? 'application/json' : 'text/html', ...(json ? { 'Content-Type': 'application/json' } : {}) };
}

type Board = { helpdesk: { columns: { id: number; name: string; tickets: { id: number; key: string; subject: string }[] }[] } };

/**
 * Setup shortcut: a ticket for a customer, created through the same endpoint as "New ticket" (no
 * priority = the default one, Medium). Leaves the page on the board; returns the ticket's id and key.
 */
export async function createTicket(page: Page, helpdeskUrl: string, subject: string): Promise<{ id: number; key: string }> {
    await page.goto(helpdeskUrl);
    const columnId = (await pageProps<Board>(page)).helpdesk.columns[0].id;
    const res = await page.request.post(`${helpdeskUrl}/tickets`, {
        headers: await headers(page),
        form: { subject, column_id: String(columnId), contact_email: 'sla-customer@example.test', notify_contact: '0' },
    });
    expect(res.ok(), `create ticket: ${res.status()}`).toBeTruthy();
    await page.goto(helpdeskUrl);
    const ticket = (await pageProps<Board>(page)).helpdesk.columns.flatMap((c) => c.tickets).find((t) => t.subject === subject);
    expect(ticket, `ticket ${subject} on the board`).toBeTruthy();
    return { id: ticket!.id, key: ticket!.key };
}

/** Setup shortcut: save an SLA policy through the SLA tab's endpoint; targets by priority name. */
export async function saveSlaPolicy(
    page: Page,
    helpdeskUrl: string,
    targets: Record<string, { first_response?: [number, 'minutes' | 'hours' | 'days']; resolution?: [number, 'minutes' | 'hours' | 'days'] }>,
): Promise<void> {
    const loaded = await page.request.get(`${helpdeskUrl}/sla`, { headers: await headers(page, true) });
    expect(loaded.ok(), `load SLA policy: ${loaded.status()}`).toBeTruthy();
    const { priorities } = (await loaded.json()) as { priorities: { id: number; name: string }[] };
    const byName = Object.fromEntries(priorities.map((p) => [p.name, String(p.id)]));
    const body = {
        enabled: true,
        targets: Object.fromEntries(
            Object.entries(targets).map(([name, t]) => [
                name === 'No priority' ? 'none' : byName[name],
                {
                    first_response: t.first_response ? { value: t.first_response[0], unit: t.first_response[1] } : null,
                    resolution: t.resolution ? { value: t.resolution[0], unit: t.resolution[1] } : null,
                },
            ]),
        ),
        business_hours: null,
        timezone: 'UTC',
        pause_column_ids: [],
        warn_percent: 80,
    };
    const saved = await page.request.put(`${helpdeskUrl}/sla`, { headers: await headers(page, true), data: body });
    expect(saved.ok(), `save SLA policy: ${saved.status()}`).toBeTruthy();
}

/**
 * e2e-only route (APP_ENV=e2e, not on dev.brainitive.app): move the ticket's creation back by N minutes,
 * then run `app:check-helpdesk-sla` as the scheduler would.
 */
export async function runSlaChecker(page: Page, helpdeskUrl: string, ticketId: number, backdateMinutes: number): Promise<string> {
    const res = await page.request.post(`${helpdeskUrl}/sla/e2e-check`, {
        headers: await headers(page, true),
        data: { ticket_id: ticketId, backdate_minutes: backdateMinutes },
    });
    expect(res.ok(), `SLA checker: ${res.status()}`).toBeTruthy();
    return ((await res.json()) as { output: string }).output;
}
