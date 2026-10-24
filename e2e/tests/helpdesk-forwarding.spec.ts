import fs from 'node:fs';
import path from 'node:path';
import { expect, test, uid } from '../fixtures';
import { INBOUND_MAIL, REMOTE, ROOT } from '../env';
import { createHelpdesk } from '../helpers/helpdesk';

/*
 * Email → helpdesk through a forwarding address (BRAI-124). The local e2e app has forwarding on
 * (e2e/env.ts: INBOUND_MAIL_* with the Postmark endpoint); dev.brainitive.app has no inbound domain,
 * so this file only runs locally. The ticket the mail creates is ticket 3's pipeline
 * (InboundMail::receive); here: the settings section and the webhook's answers.
 */

const postmarkPayload = (to: string) =>
    JSON.parse(fs.readFileSync(path.join(ROOT, 'tests', 'Fixtures', 'inbound', 'postmark.json'), 'utf8').replaceAll('{{TO}}', to));

test.describe('helpdesk forwarding address', () => {
    test.skip(REMOTE, 'forwarding addresses are only configured in the local e2e app');

    test('the Email tab shows and copies the address; regenerating retires the old one', async ({ page, browserName }) => {
        const hd = await createHelpdesk(page, uid('Forwarding desk'));
        await page.goto(`${hd.url}/edit?tab=email`);

        await expect(page.getByRole('heading', { name: 'Forwarding address', exact: true })).toBeVisible();
        const field = page.getByRole('textbox', { name: 'Forwarding address', exact: true });
        const pattern = new RegExp(`^forwarding-desk-[a-z0-9-]+-[a-z0-9]{10}@${INBOUND_MAIL.domain.replaceAll('.', '\\.')}$`);
        await expect(field).toHaveValue(pattern);
        const address = await field.inputValue();
        await expect(page.getByText('Gmail / Google Workspace')).toBeVisible();

        // Chromium asks for clipboard permissions (the other engines don't know these names)
        const chromium = browserName === 'chromium';
        if (chromium) await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.getByRole('button', { name: 'Copy forwarding address', exact: true }).click();
        await expect(page.getByText('Address copied')).toBeVisible();
        if (chromium) expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(address);

        // the same address after a reload
        await page.reload();
        await expect(field).toHaveValue(address);

        // the provider posts mail for it: accepted (token = the URL's basic-auth password)
        const auth = { Authorization: `Basic ${Buffer.from(`postmark:${INBOUND_MAIL.postmarkToken}`).toString('base64')}` };
        const accepted = await page.request.post('/api/inbound-mail/postmark', { data: postmarkPayload(address), headers: auth });
        expect(accepted.status(), await accepted.text()).toBe(200);
        // a wrong token is refused
        const forged = await page.request.post('/api/inbound-mail/postmark', { data: postmarkPayload(address), headers: { Authorization: `Basic ${Buffer.from('postmark:nope').toString('base64')}` } });
        expect(forged.status()).toBe(401);

        // regenerate: a new address, and mail to the old one is refused (403 = Postmark won't retry)
        await page.getByRole('button', { name: 'Regenerate address', exact: true }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Create new address', exact: true }).click();
        await expect(page.getByText('New forwarding address created')).toBeVisible();
        await expect(field).not.toHaveValue(address);
        await expect(field).toHaveValue(pattern);

        const old = await page.request.post('/api/inbound-mail/postmark', { data: postmarkPayload(address), headers: auth });
        expect(old.status()).toBe(403);
        const fresh = await page.request.post('/api/inbound-mail/postmark', { data: postmarkPayload(await field.inputValue()), headers: auth });
        expect(fresh.status()).toBe(200);
    });
});
