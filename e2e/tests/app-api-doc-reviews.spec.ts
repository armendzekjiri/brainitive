import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED, USERS } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath, request } from '../helpers/workload';

/*
 * App API v1 doc reviews (v1.49.0, BRAI-413): the owner asks the member for a review on the web; the member — in the
 * app — reads the snapshot of a draft they can't open, comments on a passage and approves. The web's review page
 * shows the thread and the approval, and the doc's history lists the publish.
 */

test.describe('app api: doc reviews', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('a reviewer comments and approves through the API', async ({ page, memberPage }) => {
        const ownerAuth = { Authorization: `Bearer ${await seedAppToken(page)}` };
        const memberAuth = { Authorization: `Bearer ${await seedAppToken(memberPage)}` };
        const projectId = await appProjectId(page, ownerAuth, SEED.workspace, SEED.project);

        const doc = (await ok(await page.request.post(`/api/v1/projects/${projectId}/pages`, { headers: ownerAuth, data: { name: uid('Reviewed doc'), markdown: 'The quick brown fox jumps.' } }), 201)).data;
        const members = await ok(await page.request.get(`/api/v1/projects/${projectId}/members?limit=100`, { headers: ownerAuth }));
        const member = members.data.find((m: { name: string }) => m.name === USERS.member.name);
        expect(member, 'the member is in the project').toBeTruthy();

        // asking for a review is web only
        const asked = await request(page, 'POST', `${projectPath(SEED.project)}/pages/${doc.uuid}/reviews`, { reviewer_ids: [member.id] });
        expect(asked.status()).toBeLessThan(400);

        expect((await memberPage.request.get(`/api/v1/pages/${doc.uuid}`, { headers: memberAuth })).status()).toBe(404); // the draft itself
        const reviews = await ok(await memberPage.request.get(`/api/v1/pages/${doc.uuid}/reviews`, { headers: memberAuth }));
        const review = reviews.data[0];
        const detail = (await ok(await memberPage.request.get(`/api/v1/pages/${doc.uuid}/reviews/${review.uuid}`, { headers: memberAuth }))).data;
        expect(detail).toMatchObject({ snapshot_markdown: 'The quick brown fox jumps.', can: { decide: true, open_page: false } });

        const thread = (await ok(await memberPage.request.post(`/api/v1/pages/${doc.uuid}/reviews/${review.uuid}/comments`, {
            headers: memberAuth, data: { body: 'Which fox?', quote: 'brown fox', prefix: 'The quick ', suffix: ' jumps.' },
        }), 201)).data;
        expect(thread.comments[0].body).toBe('Which fox?');
        const decided = (await ok(await memberPage.request.post(`/api/v1/pages/${doc.uuid}/reviews/${review.uuid}/decision`, { headers: memberAuth, data: { decision: 'approved' } }))).data;
        expect(decided.status).toBe('approved');

        await page.goto(`${projectPath(SEED.project)}/pages/${doc.uuid}/reviews/${review.uuid}`);
        await expect(page.getByText('Which fox?').first()).toBeVisible();

        await ok(await page.request.post(`/api/v1/pages/${doc.uuid}/publish`, { headers: ownerAuth, data: { message: 'Reviewed' } }));
        const versions = await ok(await page.request.get(`/api/v1/pages/${doc.uuid}/versions`, { headers: ownerAuth }));
        expect(versions.data[0]).toMatchObject({ version: 1, message: 'Reviewed' });

        for (const auth of [ownerAuth, memberAuth]) await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
