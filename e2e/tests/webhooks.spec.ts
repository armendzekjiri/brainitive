import { test, expect, uid, urls } from '../fixtures';
import { REMOTE, SEED, USERS } from '../env';
import { createCard } from '../helpers/boards';
import { expectedSignature, startReceiver, webhooksUrl, type ReceivedWebhook } from '../helpers/webhooks';

/**
 * Outgoing webhooks (v1.39.0, BRAI-104): an admin connects a receiver from the workspace settings,
 * a new issue arrives there signed, and a delivery can be sent again. The receiver is a Node HTTP
 * server on 127.0.0.1 in this worker; private targets are allowed only in the e2e app (env.ts).
 */
test.describe('outgoing webhooks', () => {
    test.skip(REMOTE, 'the receiver runs on this machine, the deployed app cannot reach it');

    let receiver: Awaited<ReturnType<typeof startReceiver>>;
    test.beforeAll(async () => {
        receiver = await startReceiver();
    });
    test.afterAll(async () => {
        await receiver?.close();
    });

    const find = (predicate: (r: ReceivedWebhook) => boolean) => receiver.received.filter(predicate);

    test('an admin adds a webhook, a new issue is delivered signed and can be redelivered', async ({ page }) => {
        const name = uid('Receiver');
        const title = uid('Webhook issue');

        // Workspace settings → Webhooks
        await page.goto(`/workspaces/${SEED.workspace}/edit`);
        await page.getByRole('link', { name: 'Webhooks', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${webhooksUrl()}$`));
        await expect(page.getByRole('heading', { level: 1, name: 'Webhooks' })).toBeVisible();

        // create: only "Issue created"
        await page.getByRole('button', { name: 'Add webhook' }).click();
        const dialog = page.getByRole('dialog', { name: 'Add webhook' });
        await dialog.getByLabel('Name').fill(name);
        await dialog.getByLabel('Payload URL').fill(receiver.url);
        await dialog.getByRole('checkbox', { name: /^All events/ }).uncheck();
        await dialog.getByRole('checkbox', { name: /^Issue created/ }).check();
        await dialog.getByRole('button', { name: 'Create webhook' }).click();

        // the secret is shown once, right after creating
        const secretDialog = page.getByRole('dialog', { name: 'Webhook created' });
        await expect(secretDialog).toBeVisible();
        const secret = (await secretDialog.locator('code').innerText()).trim();
        expect(secret).toMatch(/^whsec_[0-9a-f]{48}$/);
        await secretDialog.getByRole('button', { name: 'Done' }).click();

        const hook = page.getByRole('listitem').filter({ has: page.getByRole('heading', { level: 2, name, exact: true }) });
        await expect(hook.getByText('Issue created', { exact: true })).toBeVisible();

        // Send test → a ping reaches the receiver
        await hook.getByRole('button', { name: 'Send test' }).click();
        await expect(page.getByText(/Test delivered: HTTP 200/)).toBeVisible();
        expect(find((r) => r.json.event === 'ping' && r.json.data.webhook?.name === name)).toHaveLength(1);

        // a new issue on the board is delivered, signed with the secret
        await page.goto(urls.board());
        await createCard(page, title);
        await expect.poll(() => find((r) => r.json.event === 'item.created' && r.json.data.item?.title === title).length, { timeout: 20_000 }).toBe(1);
        const [delivery] = find((r) => r.json.event === 'item.created' && r.json.data.item?.title === title);
        expect(delivery.headers['x-brainitive-event']).toBe('item.created');
        expect(delivery.headers['x-brainitive-signature']).toBe(expectedSignature(secret, delivery));
        expect(delivery.json.workspace.slug).toBe(SEED.workspace);
        expect(delivery.json.actor?.name).toBe(USERS.owner.name);
        expect(delivery.json.data.item.key).toMatch(new RegExp(`^${SEED.key}-\\d+$`));

        // the delivery log shows it as delivered; Redeliver sends the same event again
        await page.goto(webhooksUrl());
        await hook.getByRole('button', { name: 'Deliveries' }).click();
        const row = hook.getByRole('row').filter({ hasText: title });
        await expect(row).toContainText('Delivered');
        await expect(row).toContainText('200');
        await row.getByRole('button', { name: 'Show details' }).click();
        await expect(hook.getByText(`"title": "${title}"`)).toBeVisible();
        await row.getByRole('button', { name: 'Redeliver' }).click();
        await expect(page.getByText(/Redelivered: HTTP 200/)).toBeVisible();
        const copies = find((r) => r.json.id === delivery.json.id);
        expect(copies).toHaveLength(2);
        expect(copies[1].headers['x-brainitive-delivery']).not.toBe(copies[0].headers['x-brainitive-delivery']);

        // clean up: other journeys keep creating issues in this workspace
        await hook.getByRole('button', { name: `More actions for ${name}` }).click();
        await page.getByRole('menuitem', { name: 'Delete' }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Delete webhook' }).click();
        await expect(hook).toHaveCount(0);
    });

    test('members cannot open the webhooks page', async ({ memberPage }) => {
        const response = await memberPage.goto(webhooksUrl());
        expect(response?.status()).toBe(403);
    });
});
