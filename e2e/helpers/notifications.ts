import { expect, type Page } from '@playwright/test';

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
