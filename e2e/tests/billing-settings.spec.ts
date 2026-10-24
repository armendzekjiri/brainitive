import { expect, test, uid } from '../fixtures';
import { SEED } from '../env';
import { createWorkspace } from '../helpers/workspaces-projects';

/**
 * Billing → Settings (v1.38.0, BRAI-101): a workspace admin sets VAT, invoice mode, company and bank
 * details and uploads a logo (kept on the private disk); members are refused. Each journey uses its
 * own workspace, so parallel runs never share billing settings.
 */
const settingsUrl = (workspace: string) => `/workspaces/${workspace}/billing/settings`;

// a 40 × 20 px blue PNG
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAACgAAAAUCAIAAABwJOjsAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAJ0lEQVRIiWPka77DMBCAaUBsHbV41OJRi0ctHrV41OJRi0ctHhAAAD4SAZWlsVV3AAAAAElFTkSuQmCC', 'base64');

test.describe('billing settings', () => {
    test('the owner fills in the settings, uploads a logo, and both persist', async ({ page }) => {
        const workspace = await createWorkspace(page, uid('Billing'));
        const company = uid('Acme Studio');

        await page.goto(settingsUrl(workspace.slug));
        await expect(page.getByRole('heading', { level: 1, name: 'Billing' })).toBeVisible();
        await expect(page.getByRole('navigation', { name: 'Billing' }).getByRole('link', { name: 'Settings' })).toHaveAttribute('aria-current', 'page');
        await expect(page.getByText('you are responsible for the legal requirements')).toBeVisible();

        // prices & tax
        await page.getByLabel('Default VAT (%)').fill('19');
        await page.getByLabel('Rounding').click();
        await page.getByRole('option', { name: 'Round up to 15 minutes per entry' }).click();
        await page.getByLabel('Payment terms (days)').fill('14');

        // invoice mode
        await page.getByLabel('Issue invoices with consecutive numbers').check();
        await page.getByLabel('Number prefix').fill('INV-2026-');
        await page.getByLabel('Next number').fill('7');

        // issuer + bank
        await page.getByLabel('Company name').fill(company);
        await page.getByLabel('Address').fill('Hauptstraße 1\n10115 Berlin');
        await page.getByLabel('VAT ID').fill('DE123456789');
        await page.getByLabel('IBAN').fill('de89 3704 0044 0532 0130 00');
        await page.getByLabel('BIC').fill('COBADEFFXXX');
        await page.getByLabel('Footer').fill('Registered at Amtsgericht Berlin');

        await page.getByRole('button', { name: 'Save settings' }).click();
        await expect(page.getByText('Billing settings saved.')).toBeVisible();

        // logo
        await page.locator('#billing-logo').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: PNG });
        await expect(page.getByText('Logo uploaded.')).toBeVisible();
        await expect(page.getByRole('img', { name: 'Current logo' })).toBeVisible();

        await page.reload();
        await expect(page.getByLabel('Default VAT (%)')).toHaveValue('19.00');
        await expect(page.getByLabel('Rounding')).toContainText('Round up to 15 minutes per entry');
        await expect(page.getByLabel('Payment terms (days)')).toHaveValue('14');
        await expect(page.getByLabel('Issue invoices with consecutive numbers')).toBeChecked();
        await expect(page.getByLabel('Number prefix')).toHaveValue('INV-2026-');
        await expect(page.getByLabel('Next number')).toHaveValue('7');
        await expect(page.getByLabel('Company name')).toHaveValue(company);
        await expect(page.getByLabel('Address')).toHaveValue('Hauptstraße 1\n10115 Berlin');
        await expect(page.getByLabel('IBAN')).toHaveValue('DE89370400440532013000');
        await expect(page.getByLabel('Footer')).toHaveValue('Registered at Amtsgericht Berlin');

        // the logo comes from the admin-only route (private disk), and it really is an image
        const logo = page.getByRole('img', { name: 'Current logo' });
        await expect(logo).toBeVisible();
        expect(await logo.getAttribute('src')).toContain(`/workspaces/${workspace.slug}/billing/settings/logo`);
        await expect.poll(() => logo.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);

        await page.getByRole('button', { name: 'Remove logo' }).click();
        await expect(page.getByText('Logo removed.')).toBeVisible();
        await expect(page.getByRole('img', { name: 'Current logo' })).toHaveCount(0);
    });

    test('a bad IBAN and an SVG logo are refused with a message', async ({ page }) => {
        const workspace = await createWorkspace(page, uid('Billing'));
        await page.goto(settingsUrl(workspace.slug));

        await page.getByLabel('IBAN').fill('DE12');
        await page.getByRole('button', { name: 'Save settings' }).click();
        await expect(page.getByText('This doesn’t look like an IBAN')).toBeVisible();

        await page.locator('#billing-logo').setInputFiles({ name: 'logo.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"/>') });
        await expect(page.getByRole('alert').filter({ hasText: 'The logo must be a PNG, JPG or WebP image.' })).toBeVisible();
        await expect(page.getByRole('img', { name: 'Current logo' })).toHaveCount(0);
    });

    test('a workspace member gets 403 on the billing settings', async ({ memberPage }) => {
        const page = await memberPage.goto(settingsUrl(SEED.workspace));
        expect(page?.status()).toBe(403);

        const logo = await memberPage.request.get(`${settingsUrl(SEED.workspace)}/logo`);
        expect(logo.status()).toBe(403);
    });
});
