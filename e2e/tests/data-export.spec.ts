import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import zlib from 'node:zlib';
import { expect, test } from '../fixtures';
import { guestContext, newAccount, register, skipWelcome } from '../helpers/auth-account';
import { canReadMail, mailLink } from '../mail';

/** One file of a ZIP (stored or deflated; sizes in the local header, as PHP's ZipArchive writes them). */
function zipEntry(zip: Buffer, name: string): string | null {
    let offset = 0;
    while ((offset = zip.indexOf(Buffer.from([0x50, 0x4b, 0x03, 0x04]), offset)) !== -1) {
        const method = zip.readUInt16LE(offset + 8);
        const compressed = zip.readUInt32LE(offset + 18);
        const nameLength = zip.readUInt16LE(offset + 26);
        const extraLength = zip.readUInt16LE(offset + 28);
        const entry = zip.subarray(offset + 30, offset + 30 + nameLength).toString('utf8');
        const start = offset + 30 + nameLength + extraLength;
        if (entry === name) {
            const data = zip.subarray(start, start + compressed);
            return (method === 8 ? zlib.inflateRawSync(data) : data).toString('utf8');
        }
        offset = start + compressed;
    }
    return null;
}

/**
 * "Download my data" (GDPR Art. 15 + 20, BRAI-156 follow-up): a fresh account asks for its data in the
 * profile, the export is ready (sync queue), the ZIP downloads with account.json + README, the email
 * carries the signed link, a second request is throttled, and the link is useless to anyone else.
 */
test('download my data: a ZIP of the account, ready in the profile, only for its owner', async ({ browser, page }) => {
    test.slow();
    const context = await guestContext(browser);
    const me = await context.newPage();
    const account = await register(me, newAccount('mydata'));

    await me.goto('/profile#your-data');
    await skipWelcome(me);
    const card = me.locator('#your-data');
    await expect(card.getByText('Your data', { exact: true })).toBeVisible();
    await expect(card.getByText(/never passwords|never included/i).first()).toBeVisible();

    await card.getByRole('button', { name: 'Download my data' }).click();
    const exports = card.getByRole('list', { name: 'Your data exports' });
    await expect(exports.getByRole('listitem').first()).toContainText('Ready', { timeout: 60_000 });

    // one request per hour
    await expect(card.getByRole('button', { name: 'Download my data' })).toBeDisabled();
    await expect(card.getByText(/You can request a new copy in \d+ minutes?/)).toBeVisible();

    const link = exports.getByRole('listitem').first().getByRole('link', { name: 'Download' });
    const href = await link.getAttribute('href');
    expect(href).toContain('signature=');
    const [download] = await Promise.all([me.waitForEvent('download'), link.click()]);
    expect(download.suggestedFilename()).toMatch(/^brainitive-my-data-\d{4}-\d{2}-\d{2}\.zip$/);
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'brainitive-my-data-'));
    const file = path.join(dir, download.suggestedFilename());
    await download.saveAs(file);

    const zip = fs.readFileSync(file);
    fs.rmSync(dir, { recursive: true, force: true });
    const accountJson = JSON.parse(zipEntry(zip, 'account.json') ?? 'null');
    expect(accountJson.profile.email).toBe(account.email);
    expect(accountJson.profile.name).toBe(account.name);
    expect(JSON.stringify(accountJson)).not.toMatch(/"password":|remember_token|\$2y\$/);
    expect(zipEntry(zip, 'README.txt')).toContain('Your Brainitive data');
    expect(zipEntry(zip, 'manifest.json')).toContain('brainitive-personal-data');

    // the email carries the same kind of signed link
    if (canReadMail) {
        expect(await mailLink(account.email, /\/data-exports\/[0-9a-f-]+\/download/)).toContain('signature=');
    }

    // somebody else (the seeded owner, signed in) can't use the link
    const other = await page.request.get(href!, { maxRedirects: 0 });
    expect(other.status()).toBe(403);
    // nor can a visitor who isn't signed in
    const visitor = await guestContext(browser);
    const anonymous = await visitor.request.get(href!, { maxRedirects: 0 });
    expect(anonymous.status()).toBe(302);
    expect(anonymous.headers().location ?? '').toContain('/login');
    await visitor.close();

    await context.close();
});
