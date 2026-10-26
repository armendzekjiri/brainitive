import { request as playwrightRequest } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { projectWithIssue } from '../helpers/time-overview';
import { projectPath, request } from '../helpers/workload';

/**
 * Calendar feed (BRAI-142): the owner creates a private subscription link in the Calendar page's
 * "Subscribe in other apps" dialog; a calendar app (no cookies) fetches it and gets the issue as an
 * event; resetting the link makes the old URL 404, turning it off makes the new one 404 too.
 */
test.describe('calendar feed', () => {
    test('subscribe link with my issue; reset and turn off stop the old URLs', async ({ page }) => {
        test.slow(); // a project, an issue and several fetches
        const title = uid('Feed launch');
        const project = await projectWithIssue(page, title);
        const due = new Date(Date.now() + 3 * 86_400_000).toISOString().slice(0, 10);
        const patched = await request(page, 'PATCH', `${projectPath(project.slug)}/boards/main-board/items/${project.itemId}`, { due_date: due });
        expect(patched.status(), await patched.text()).toBeLessThan(400);

        // start clean: the owner may still have a feed from an earlier run (dev.brainitive.app)
        expect((await request(page, 'DELETE', '/calendar/feed')).status()).toBe(200);

        await page.goto('/calendar');
        await page.getByRole('button', { name: 'Subscribe in other apps' }).click();
        const dialog = page.getByRole('dialog', { name: 'Subscribe in other apps' });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('checkbox', { name: /Issues I created/ }).check(); // the issue isn't assigned
        await dialog.getByRole('button', { name: 'Create calendar link' }).click();

        const urlField = dialog.getByRole('textbox', { name: 'Calendar feed URL' });
        await expect(urlField).toHaveValue(/\/calendar\/feed\/[A-Za-z0-9]{48}\.ics$/);
        const url = await urlField.inputValue();
        await expect(dialog.getByRole('link', { name: 'Open in Apple Calendar' })).toHaveAttribute('href', /^webcal:\/\//);
        await expect(dialog.getByRole('link', { name: /Add to Google Calendar/ })).toHaveAttribute('href', /^https:\/\/calendar\.google\.com\/calendar\/r\?cid=webcal/);

        // a calendar app: no session, no cookies
        const calendarApp = await playwrightRequest.newContext({
            storageState: { cookies: [], origins: [] },
            httpCredentials: process.env.E2E_HTTP_USER ? { username: process.env.E2E_HTTP_USER, password: process.env.E2E_HTTP_PASSWORD ?? '' } : undefined,
        });
        const response = await calendarApp.get(url);
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('text/calendar');
        const ics = (await response.text()).replace(/\r\n /g, ''); // unfold
        expect(ics).toContain('BEGIN:VCALENDAR');
        expect(ics).toContain(`UID:item-${project.itemId}@`);
        expect(ics).toMatch(new RegExp(`SUMMARY:\\[[A-Z0-9]+-\\d+\\] ${title}\\r\\n`));
        expect(ics).toContain(`DTSTART;VALUE=DATE:${due.replaceAll('-', '')}`);

        // reset: a new URL, the old one is gone
        page.once('dialog', (d) => d.accept());
        await dialog.getByRole('button', { name: 'Reset link' }).click();
        await expect(urlField).not.toHaveValue(url);
        const fresh = await urlField.inputValue();
        expect((await calendarApp.get(url)).status()).toBe(404);
        expect((await calendarApp.get(fresh)).status()).toBe(200);

        // turn off: the dialog offers to create a link again and the URL stops working
        page.once('dialog', (d) => d.accept());
        await dialog.getByRole('button', { name: 'Turn off' }).click();
        await expect(dialog.getByRole('button', { name: 'Create calendar link' })).toBeVisible();
        expect((await calendarApp.get(fresh)).status()).toBe(404);

        await calendarApp.dispose();
    });
});
