import { REMOTE, SEED } from '../env';
import { expect, test, uid } from '../fixtures';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { createHelpdesk } from '../helpers/helpdesk';
import { createTicket } from '../helpers/helpdesk-sla';

/*
 * App API v1 helpdesk, agent side (v1.49.0, BRAI-420): a ticket made on the web shows up in the app's inbox; the
 * app replies, adds an internal note, assigns and resolves it, and the web's ticket shows all of it.
 */
test.describe('app api: helpdesk', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('an agent works a ticket from the app and the web shows it', async ({ page }) => {
        const helpdesk = await createHelpdesk(page, uid('API desk'));
        const subject = uid('Printer on fire');
        const created = await createTicket(page, helpdesk.url, subject);

        const auth = { Authorization: `Bearer ${await seedAppToken(page)}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const desks = await ok(await page.request.get(`/api/v1/projects/${projectId}/helpdesks`, { headers: auth }));
        const desk = desks.data.find((h: { slug: string }) => h.slug === helpdesk.slug);
        expect(desk).toMatchObject({ name: helpdesk.name, open_count: 1, can: { manage_tickets: true } });

        const inbox = await ok(await page.request.get(`/api/v1/helpdesks/${desk.id}/tickets?assignee=unassigned`, { headers: auth }));
        expect(inbox.data[0]).toMatchObject({ id: created.id, key: created.key, subject, contact: { email: 'sla-customer@example.test' } });

        const reply = `Replaced the fuser ${uid()}`;
        await ok(await page.request.post(`/api/v1/tickets/${created.id}/replies`, { headers: auth, data: { body: `**${reply}**` } }), 201);
        const note = `Vendor ticket ${uid()}`;
        await ok(await page.request.post(`/api/v1/tickets/${created.id}/replies`, { headers: auth, data: { body: note, internal: true } }), 201);

        const me = (await ok(await page.request.get('/api/v1/me', { headers: auth }))).data;
        const done = desk.columns.find((c: { is_done: boolean }) => c.is_done);
        const resolved = await ok(await page.request.patch(`/api/v1/tickets/${created.id}`, {
            headers: auth,
            data: { assignee_id: me.id, column_id: done.id, resolution_message: 'All good now.' },
        }));
        expect(resolved.data).toMatchObject({ status: { column_id: done.id, is_done: true }, assignee: { id: me.id } });
        expect(resolved.data.replies.map((r: { internal: boolean }) => r.internal)).toEqual([false, true, false]);

        // the web's ticket panel has the reply, the note and the closing message
        await page.goto(`${helpdesk.url}/tickets/${created.id}`);
        await expect(page.getByText(reply).first()).toBeVisible();
        await expect(page.getByText(note).first()).toBeVisible();
        await expect(page.getByText('All good now.').first()).toBeVisible();

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
