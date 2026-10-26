import { createHmac, randomUUID } from 'node:crypto';
import type { Page } from '@playwright/test';
import { REMOTE, SEED } from '../env';
import { expect, test, uid, urls } from '../fixtures';
import { card, closeIssue, createCard, issueDialog, openCard } from '../helpers/boards';
import { createBlankProject } from '../helpers/workspaces-projects';

/**
 * BRAI-61 GitHub App connect: "Connect GitHub" → (GitHub's install screen) → pick a repository →
 * a signed merge delivery to the app's webhook moves the card. The local e2e app runs a fake GitHub
 * App (GITHUB_APP_FAKE, honoured only with APP_ENV=e2e): its API is answered in-process, and
 * "Connect GitHub" returns from the "install screen" at once with a fresh installation, whose
 * repositories are e2e-org/site-<id> (id <id>1) and e2e-org/api-<id>.
 */
const WEBHOOK_SECRET = 'e2e-github-app-secret'; // App\Support\Github\FakeGithub::WEBHOOK_SECRET

async function deliver(page: Page, event: string, payload: Record<string, unknown>): Promise<void> {
    const body = JSON.stringify(payload);
    const response = await page.request.post('/api/webhooks/github-app', {
        headers: {
            'Content-Type': 'application/json',
            'X-GitHub-Event': event,
            'X-GitHub-Delivery': randomUUID(),
            'X-Hub-Signature-256': 'sha256=' + createHmac('sha256', WEBHOOK_SECRET).update(body).digest('hex'),
        },
        data: body,
    });
    expect(response.status(), `${event}: ${await response.text()}`).toBe(202);
}

test.describe('GitHub App', () => {
    test.skip(REMOTE, 'needs the fake GitHub App of the local e2e server (GITHUB_APP_FAKE)');

    test('connect GitHub, follow a repository, and a merged primary PR moves the card to Done', async ({ page }) => {
        const project = await createBlankProject(page, SEED.workspace, uid('GitHub'));
        const title = `Login ${uid('gh')}`;
        await createCard(page, title);
        const key = `${project.key}-1`;

        // project settings → Integrations: the GitHub App is set up, so it's one click
        await page.goto(`${urls.project(project.slug)}/edit?tab=integrations`);
        await expect(page.getByRole('button', { name: 'Use manual webhooks instead' })).toBeVisible();
        await page.getByRole('button', { name: 'Connect GitHub' }).click();

        await expect(page.getByText('GitHub connected. Choose the repositories this project follows.').first()).toBeVisible();
        await expect(page.getByText('GitHub App · e2e-org')).toBeVisible();
        const repo = (await page.getByText(/^e2e-org\/site-\d+$/).textContent()) ?? '';
        const installation = Number(/site-(\d+)$/.exec(repo)?.[1]);
        expect(installation).toBeGreaterThan(0);

        await page.getByRole('checkbox', { name: new RegExp(repo.replace('/', '\\/')) }).check();
        await page.getByRole('button', { name: 'Save repositories' }).click();
        await expect(page.getByText('Following 1 repository.').first()).toBeVisible();
        await expect(page.getByRole('checkbox', { name: new RegExp(repo.replace('/', '\\/')) })).toBeChecked();

        // merge transition for the Main Board: off by default → "Move to Done"
        await page.getByLabel('When merged on Main Board').click();
        await page.getByRole('option', { name: 'Move to Done' }).click();
        await page.getByRole('button', { name: 'Save merge transitions' }).click();
        await expect(page.getByText('Merge transitions saved.').first()).toBeVisible();

        // GitHub delivers: the PR is opened, then merged (signed with the app's webhook secret)
        const repository = { id: Number(`${installation}1`), full_name: repo };
        const pr = (extra: Record<string, unknown>) => ({
            number: 42, title: `${key}: login form`, html_url: `https://github.com/${repo}/pull/42`,
            head: { ref: `feat/${key.toLowerCase()}-login` }, base: { ref: 'main' }, user: { login: 'octocat' },
            state: 'open', draft: false, merged: false, merged_at: null, updated_at: new Date().toISOString(), ...extra,
        });
        await deliver(page, 'pull_request', { action: 'opened', installation: { id: installation }, repository, pull_request: pr({}) });
        await deliver(page, 'pull_request', {
            action: 'closed', installation: { id: installation }, repository,
            pull_request: pr({ state: 'closed', merged: true, merged_at: new Date().toISOString() }),
        });

        // the card moved to Done; the issue shows the merged PR and "GitHub" in its history
        await page.goto(urls.board('main-board', project.slug));
        await expect(card(page, title, 'Done')).toBeVisible();
        const dialog = await openCard(page, title);
        await expect(dialog.getByRole('button', { name: /1 pull request/ })).toBeVisible();
        await dialog.getByRole('tab', { name: 'History' }).click();
        await expect(dialog.getByText('GitHub', { exact: true }).first()).toBeVisible();
        await closeIssue(page);
        await expect(issueDialog(page)).toBeHidden();

        // a delivery with a wrong signature is refused
        const forged = await page.request.post('/api/webhooks/github-app', {
            headers: { 'Content-Type': 'application/json', 'X-GitHub-Event': 'push', 'X-GitHub-Delivery': randomUUID(), 'X-Hub-Signature-256': 'sha256=0' },
            data: '{}',
        });
        expect(forged.status()).toBe(401);
    });
});
