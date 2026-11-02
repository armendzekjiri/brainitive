import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { card, createCard } from '../helpers/boards';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 boards (v1.49.0, BRAI-403), as the desktop / mobile app calls them: the project's boards and
 * a board's columns are the web's, a card made on the web is in the API's card list, and a card moved
 * through the API (WorkCompletion::moveIssue, shared with the board's drag & drop) sits in its new column
 * on the web board.
 */

test.describe('app api: boards', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('a card made on the web is listed by the API and moved through it', async ({ page }) => {
        const title = uid('API move');
        await page.goto(`${projectPath(SEED.project)}/boards/${SEED.board}`);
        await createCard(page, title);

        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);

        const boards = await ok(await page.request.get(`/api/v1/projects/${projectId}/boards`, { headers: auth }));
        const board = boards.data.find((b: { slug: string }) => b.slug === SEED.board);
        expect(board, 'the seeded board in the API').toBeTruthy();

        const detail = await ok(await page.request.get(`/api/v1/boards/${board.id}`, { headers: auth }));
        const names = detail.data.columns.map((c: { name: string }) => c.name);
        expect(names).toContain('To Do');
        expect(names).toContain('In Progress');
        const inProgress = detail.data.columns.find((c: { name: string }) => c.name === 'In Progress');
        expect(detail.data.can.edit_items).toBe(true);

        const toDo = detail.data.columns.find((c: { name: string }) => c.name === 'To Do');
        const cards = await ok(await page.request.get(`/api/v1/boards/${board.id}/items?column=${toDo.id}&limit=100`, { headers: auth }));
        const created = cards.data.find((c: { title: string }) => c.title === title);
        expect(created).toMatchObject({ status: 'To Do', done: false, key: expect.stringMatching(new RegExp(`^${SEED.key}-\\d+$`)) });

        const moved = await ok(await page.request.post(`/api/v1/items/${created.id}/move`, { headers: auth, data: { column_id: inProgress.id } }));
        expect(moved.data).toMatchObject({ id: created.id, column_id: inProgress.id, status: 'In Progress' });

        // the web board shows it in its new column (seedAppToken left the board page)
        await page.goto(`${projectPath(SEED.project)}/boards/${SEED.board}`);
        await expect(card(page, title, 'In Progress')).toBeVisible();
        await expect(card(page, title, 'To Do')).toHaveCount(0);

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
