import { REMOTE, SEED } from '../env';
import { expect, test, uid } from '../fixtures';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath, request } from '../helpers/workload';

/*
 * App API v1 leads (v1.49.0, BRAI-421): a lead form made on the web gets a lead through its inbound API (not the
 * public form: its submits are throttled per IP for the whole run); the app sees the pipeline and the lead, moves
 * it to the next column and assigns it, and the web's lead board shows it there.
 */
test.describe('app api: leads', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('the app moves and assigns a lead and the web board follows', async ({ page }) => {
        const name = uid('API leads');
        await page.goto(projectPath());
        const created = await request(page, 'POST', `${projectPath()}/lead-forms`, { name, template: 'demo_request' });
        expect(created.status()).toBeLessThan(400);

        const auth = { Authorization: `Bearer ${await seedAppToken(page)}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const forms = await ok(await page.request.get(`/api/v1/projects/${projectId}/lead-forms`, { headers: auth }));
        const form = forms.data.find((f: { name: string }) => f.name === name);
        expect(form).toMatchObject({ status: 'draft', leads_count: 0, can: { manage_leads: true } });
        const formPath = new URL(form.url).pathname;

        // a lead through the form's inbound API
        const inbound = await request(page, 'POST', `${formPath}/inbound-token`);
        const { token, url } = (await inbound.json()) as { token: string; url: string };
        const email = `${uid('jane')}@example.test`;
        const lead = await page.request.post(url, {
            headers: { Authorization: `Bearer ${token}` },
            data: { f_goal: 'o_grow_sales', f_team_size: 'o_11_50', f_name: 'Jane Lead', f_email: email, f_company: 'Acme', f_timeline: 'o_this_quarter', f_consent: true },
        });
        expect(lead.status(), await lead.text()).toBe(201);

        const leads = await ok(await page.request.get(`/api/v1/lead-forms/${form.id}/leads`, { headers: auth }));
        const mine = leads.data.find((l: { email: string }) => l.email === email);
        expect(mine).toMatchObject({ name: 'Jane Lead', company: 'Acme', column_id: form.columns[0].id, source: 'webhook' });
        expect(mine.answers.length).toBeGreaterThan(0);

        const me = (await ok(await page.request.get('/api/v1/me', { headers: auth }))).data;
        const next = form.columns[1];
        const moved = await ok(await page.request.patch(`/api/v1/leads/${mine.id}`, { headers: auth, data: { column_id: next.id, position: 0, assignee_id: me.id } }));
        expect(moved.data).toMatchObject({ column_id: next.id, assignee: { id: me.id } });
        const inColumn = await ok(await page.request.get(`/api/v1/lead-forms/${form.id}/leads?column=${next.id}`, { headers: auth }));
        expect(inColumn.data[0].id).toBe(mine.id);

        // the web's lead board has it in the next column
        await page.goto(form.url);
        const props = await page.locator('#app').evaluate((el) => JSON.parse((el as HTMLElement).dataset.page ?? '{}').props);
        const column = (props.columns as { id: number; leads: { id: number }[] }[]).find((c) => c.id === next.id);
        expect(column?.leads.map((l) => l.id)).toContain(mine.id);

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
