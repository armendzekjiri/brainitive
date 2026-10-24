import type { Locator, Page } from '@playwright/test';
import { expect, urls } from '../fixtures';
import { postJson } from './boards';

/** The signed-in user's id, from the Inertia props of the current page. */
export async function currentUserId(page: Page): Promise<number> {
    if (!page.url().startsWith('http')) await page.goto(urls.home);
    return page.locator('#app').evaluate((el) => JSON.parse((el as HTMLElement).dataset.page ?? '{}').props.auth.user.id as number);
}

/**
 * Setup shortcut: `from` @mentions the user `toId` (the endpoint the mention picker calls), so the
 * recipient gets a "mentioned you in <title>" notification that links to `url`.
 */
export async function mention(from: Page, toId: number, title: string, url = urls.board()): Promise<void> {
    if (!from.url().startsWith('http')) await from.goto(urls.home);
    await postJson(from, '/api/notifications/notify-mention', { user_id: toId, context_type: 'item', context_title: title, context_url: url });
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

/** The inbox page row action buttons are labelled; wait for the request they send. */
export async function rowAction(page: Page, row: Locator, label: 'Mark as read' | 'Mark as unread' | 'Archive' | 'Unarchive'): Promise<void> {
    const sent = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/notifications\//.test(r.url()));
    await row.hover();
    await row.getByRole('button', { name: label, exact: true }).click();
    expect((await sent).status()).toBeLessThan(400);
}
