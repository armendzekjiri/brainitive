import { REMOTE, SEED } from '../env';
import { expect, test } from '../fixtures';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { pageProps } from '../helpers/helpdesk';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 read-only summaries (v1.49.0, BRAI-423): the workspace owner's Insights overview, the project's
 * health and workload read through the API match what the web pages show (the same Support classes).
 */
test.describe('app api: insights, health, workload', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('the API returns the numbers of the web pages', async ({ page, memberPage }) => {
        const auth = { Authorization: `Bearer ${await seedAppToken(page)}` };
        // the seeded workspace is shared with parallel journeys: an issue made between the two reads moves the
        // numbers, so read both again until they were taken at the same moment
        await expect(async () => {
            const api = (await ok(await page.request.get(`/api/v1/workspaces/${SEED.workspace}/insights/overview?period=last_90`, { headers: auth }))).data;
            expect(api).toMatchObject({ tab: 'overview', filters: { period: 'last_90' } });
            await page.goto(`/workspaces/${SEED.workspace}/insights/overview?period=last_90`);
            const web = await pageProps<{ insights: { kpis: Record<string, number> } }>(page);
            expect(api.insights.kpis.open).toBe(web.insights.kpis.open);
            expect(api.insights.kpis.created).toBe(web.insights.kpis.created);
        }).toPass({ timeout: 15_000 });

        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const health = (await ok(await page.request.get(`/api/v1/projects/${projectId}/health`, { headers: auth }))).data;
        expect(typeof health.metrics.values.open).toBe('number');
        const workload = (await ok(await page.request.get(`/api/v1/projects/${projectId}/workload?weeks=4`, { headers: auth }))).data;
        expect(workload.weeks).toHaveLength(4);
        await page.goto(`${projectPath()}/workload?weeks=4`);
        const grid = await pageProps<{ members: { id: number }[] }>(page);
        expect(workload.members.map((m: { id: number }) => m.id)).toEqual(grid.members.map((m) => m.id));

        // a member (not a workspace admin) can't read Insights, as on the web
        const member = { Authorization: `Bearer ${await seedAppToken(memberPage)}` };
        expect((await memberPage.request.get(`/api/v1/workspaces/${SEED.workspace}/insights/overview`, { headers: member })).status()).toBe(403);

        await page.request.post('/api/v1/auth/logout', { headers: auth });
        await memberPage.request.post('/api/v1/auth/logout', { headers: member });
    });
});
