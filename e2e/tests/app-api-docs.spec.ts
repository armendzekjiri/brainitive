import { expect, test } from '../fixtures';

/**
 * The live API docs (BRAI-381): /docs/api and /docs/api.json list every App API endpoint, so outside
 * the local environment only instance admins may open them (gate `viewApiDocs`). The public copy is
 * docs/api/openapi.json in the repository.
 */
test.describe('app api docs', () => {
    test('a guest gets 403', async ({ browser }) => {
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        for (const path of ['/docs/api', '/docs/api.json']) {
            const res = await context.request.get(path);
            expect(res.status(), path).toBe(403);
        }
        await context.close();
    });

    test('a signed-in member who is no instance admin gets 403', async ({ memberPage }) => {
        const page = await memberPage.goto('/docs/api');
        expect(page?.status()).toBe(403);
        await expect(memberPage.getByText('Brainitive App API')).toHaveCount(0);

        const spec = await memberPage.request.get('/docs/api.json');
        expect(spec.status()).toBe(403);
    });
});
