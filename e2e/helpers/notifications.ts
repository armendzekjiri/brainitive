import type { Locator, Page } from '@playwright/test';
import { expect, urls } from '../fixtures';

/**
 * Helpers for the notification coverage journeys (BRAI-103). New notification types share one
 * payload (docs/ai/tasks/release-v1.39.0.md): type, title, body, url, actor_name, actor_avatar,
 * project_name, subject_key. The journeys check that payload through the bell's JSON feed, so
 * they don't depend on how the bell words or groups an entry.
 */
export interface NotificationPayload {
    type: string;
    title?: string;
    body?: string | null;
    url?: string;
    actor_name?: string | null;
    project_name?: string | null;
    subject_key?: string | null;
    // older types keep their own keys (e.g. `mentioned`: context_type, context_title, context_url)
    context_type?: string;
    context_title?: string;
}

/** The newest notifications of the user signed in on `page` (the bell's feed). */
export async function latestNotifications(page: Page): Promise<NotificationPayload[]> {
    const response = await page.request.get('/api/notifications', { headers: { Accept: 'application/json' } });
    expect(response.ok(), `/api/notifications: ${response.status()}`).toBeTruthy();
    const json = (await response.json()) as { data?: { data: NotificationPayload }[] };
    return (json.data ?? []).map((n) => n.data);
}

/** Wait until the user of `page` has a notification matching `match` (queued sends are sync in e2e). */
export async function expectNotification(page: Page, match: (n: NotificationPayload) => boolean, description: string): Promise<NotificationPayload> {
    let found: NotificationPayload | undefined;
    await expect
        .poll(async () => {
            found = (await latestNotifications(page)).find(match);
            return !!found;
        }, { message: `notification: ${description}`, timeout: 30_000 })
        .toBe(true);
    return found!;
}

// ── Inbox UI (BRAI-102) ─────────────────────────────────────────────────────────

/** The signed-in user's id, from the Inertia props of the current page. */
export async function currentUserId(page: Page): Promise<number> {
    if (!page.url().startsWith('http')) await page.goto(urls.home);
    return page.locator('#app').evaluate((el) => JSON.parse((el as HTMLElement).dataset.page ?? '{}').props.auth.user.id as number);
}

/**
 * Setup shortcut: `from` creates an issue titled `title` on the seeded board whose description
 * @mentions the user `toId` (the same endpoint the create dialog posts to). The recipient gets
 * "<from> mentioned you in WEB-n - <title>", linking to the issue.
 */
export async function mention(from: Page, toId: number, title: string): Promise<void> {
    await from.goto(urls.board());
    const props = await from.locator('#app').evaluate((el) => JSON.parse((el as HTMLElement).dataset.page ?? '{}').props);
    const columnId = props.board.columns[0].id as number;
    const xsrf = (await from.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await from.request.post(`${urls.board()}/items`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' },
        data: { title, column_id: columnId, description: `<p>Please look <span data-mention-user-id="${toId}">@someone</span></p>` },
        maxRedirects: 0,
    });
    expect(response.status(), `create issue with a mention: ${response.status()}`).toBeLessThan(400);
}

/** Open the bell; returns the panel. */
export async function openBell(page: Page): Promise<Locator> {
    await page.getByRole('button', { name: /^Notifications/ }).click();
    const panel = page.getByRole('dialog', { name: 'Notifications' });
    await expect(panel).toBeVisible();
    return panel;
}

/** One notification row (list item) that contains `text`. */
export function notificationRow(scope: Page | Locator, text: string | RegExp): Locator {
    return scope.getByRole('listitem').filter({ hasText: text });
}

/** Click a row action (they show on hover / focus) and wait for the request it sends. */
export async function rowAction(page: Page, row: Locator, label: 'Mark as read' | 'Mark as unread' | 'Archive' | 'Unarchive'): Promise<void> {
    const sent = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/notifications\//.test(r.url()));
    await row.hover();
    await row.getByRole('button', { name: label, exact: true }).click();
    expect((await sent).status()).toBeLessThan(400);
}
