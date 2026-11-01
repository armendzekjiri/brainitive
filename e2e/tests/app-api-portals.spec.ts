import { REMOTE } from '../env';
import { expect, test, uid } from '../fixtures';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { createProject, guestPage, projectKey } from '../helpers/helpdesk';
import { newGuest, portalSignIn } from '../helpers/portal';
import { request } from '../helpers/workload';

/*
 * App API v1 portals, team side (v1.49.0, BRAI-422): a guest's idea on a feedback portal is approved and planned
 * from the app and the public portal shows the status; a doc shared in the client portal is read back with its
 * approval state. Own project: each project has one portal of each kind (feedback-portal.spec uses others).
 */
test.describe('app api: feedback and client portals', () => {
    test.skip(REMOTE, 'the device seed and the mail log only exist locally');

    test('approve and plan an idea from the app; read a shared deliverable', async ({ page, browser }) => {
        test.setTimeout(120_000);
        const project = await createProject(page, uid('Portals API'), projectKey());
        const projectUrl = `/workspaces/${project.workspace}/projects/${project.slug}`;
        const slug = uid('ideas').toLowerCase();
        await page.goto(projectUrl);
        const saved = await request(page, 'PUT', `${projectUrl}/feedback/settings`, { enabled: true, slug, title: 'API ideas' });
        expect(saved.status()).toBeLessThan(400);

        // a guest shares an idea (pending until moderated)
        const guest = await guestPage(browser);
        const title = `Dark mode ${uid('idea')}`;
        try {
            await portalSignIn(guest.page, newGuest('api'), `/feedback/${slug}`);
            await guest.page.getByRole('button', { name: 'Share an idea' }).click();
            await guest.page.getByRole('textbox', { name: 'Title' }).fill(title);
            await guest.page.getByRole('button', { name: 'Submit idea' }).click();
            await expect(guest.page.getByTestId('feedback-pending')).toBeVisible();
            const postUrl = new URL(guest.page.url()).pathname;

            const auth = { Authorization: `Bearer ${await seedAppToken(page)}` };
            const projectId = await appProjectId(page, auth, project.workspace, project.slug);
            const portal = (await ok(await page.request.get(`/api/v1/projects/${projectId}/feedback-portal`, { headers: auth }))).data;
            expect(portal).toMatchObject({ slug, enabled: true, counts: { pending: 1, published: 0 } });
            const pending = await ok(await page.request.get(`/api/v1/feedback-portals/${portal.id}/posts?tab=pending`, { headers: auth }));
            const idea = pending.data.find((p: { title: string }) => p.title === title);
            expect(idea).toMatchObject({ moderation: 'pending', status: 'open' });

            await ok(await page.request.post(`/api/v1/feedback-posts/${idea.id}/moderate`, { headers: auth, data: { decision: 'approve' } }));
            const planned = await ok(await page.request.patch(`/api/v1/feedback-posts/${idea.id}`, { headers: auth, data: { status: 'planned', note: 'Next quarter.' } }));
            expect(planned.data).toMatchObject({ moderation: 'approved', status: 'planned', status_note: 'Next quarter.' });

            await guest.page.goto(postUrl);
            await expect(guest.page.getByTestId('feedback-status')).toHaveText('Planned');

            // client portal: a published doc shared on the web, read back with its approval state
            const portalSlug = uid('client').toLowerCase();
            expect((await request(page, 'PUT', `${projectUrl}/client-portal`, { enabled: true, title: 'Client', slug: portalSlug })).status()).toBeLessThan(400);
            const doc = (await ok(await page.request.post(`/api/v1/projects/${projectId}/pages`, { headers: auth, data: { name: uid('Proposal'), kind: 'doc', markdown: 'Our **offer**', publish: true } }), 201)).data;
            expect((await request(page, 'POST', `${projectUrl}/client-portal/deliverables`, { kind: 'page', id: doc.uuid, message: 'Please review' })).status()).toBeLessThan(400);

            const client = (await ok(await page.request.get(`/api/v1/projects/${projectId}/client-portal`, { headers: auth }))).data;
            expect(client).toMatchObject({ slug: portalSlug, can: { publish: true, manage: true } });
            const deliverables = await ok(await page.request.get(`/api/v1/client-portals/${client.id}/deliverables`, { headers: auth }));
            expect(deliverables.data[0]).toMatchObject({ kind: 'page', version: 1, status: 'awaiting', title: doc.name });
            const detail = (await ok(await page.request.get(`/api/v1/client-deliverables/${deliverables.data[0].id}`, { headers: auth }))).data;
            expect(detail).toMatchObject({ message: 'Please review', decisions: [] });
            expect(detail.markdown).toContain('**offer**');

            await page.request.post('/api/v1/auth/logout', { headers: auth });
        } finally {
            await guest.close();
        }
    });
});
