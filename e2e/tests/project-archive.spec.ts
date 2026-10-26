import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { expect, test, uid } from '../fixtures';
import { SEED } from '../env';

/**
 * BRAI-81: export a project from its settings, download the ZIP, restore it as a new project via
 * Import data (checked preview first), then open the copy: same content, new key.
 */
test('a project export downloads as a ZIP and restores as a new project', async ({ page }) => {
    test.slow();
    const name = uid('Restored');
    const key = `R${Array.from({ length: 6 }, () => String.fromCharCode(65 + Math.floor(Math.random() * 26))).join('')}`;

    // export from project settings
    await page.goto(`/workspaces/${SEED.workspace}/projects/${SEED.project}/edit`);
    const card = page.locator('#export');
    await expect(card.getByText(/download link works for 24 hours/)).toBeVisible();
    await card.getByRole('button', { name: 'Export project', exact: true }).click();
    const exports = card.getByRole('list', { name: 'Your exports' });
    await expect(exports.getByRole('listitem').first()).toContainText('Ready', { timeout: 60_000 });

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        exports.getByRole('listitem').first().getByRole('link', { name: 'Download' }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/^website-\d{4}-\d{2}-\d{2}\.zip$/);
    const zip = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'brainitive-export-')), download.suggestedFilename());
    await download.saveAs(zip);

    // the notification links to the same download
    await page.goto('/notifications');
    await expect(page.getByText(/Your export of “Website” is ready to download/).first()).toBeVisible();

    // restore: upload → checked preview → name, key → new project
    await page.goto(`/workspaces/${SEED.workspace}/imports`);
    await page.getByRole('radio', { name: /Brainitive project export/ }).click();
    await expect(page.getByRole('combobox', { name: 'Project' })).toHaveCount(0); // a restore creates its own project
    await page.getByLabel('Exported file').setInputFiles(zip);
    await page.getByRole('button', { name: 'Upload and preview' }).click();

    await expect(page.getByTestId('import-status')).toHaveText('Ready to import');
    await expect(page.getByText(/every file matches its checksum/)).toBeVisible();
    await expect(page.getByRole('list', { name: 'Contents' })).toContainText('issues');
    await expect(page.getByRole('list', { name: 'Not in the export' })).toContainText('Helpdesk tickets and contacts');
    await expect(page.getByRole('combobox', { name: 'Max Member is' })).toHaveText('Max Member'); // matched by email
    await expect(page.getByLabel('Key')).not.toHaveValue(SEED.key); // WEB is taken: a free key is suggested

    await page.getByLabel('New project name').fill(name);
    await page.getByLabel('Key').fill(key);
    await page.getByRole('button', { name: 'Restore as a new project', exact: true }).click();
    await expect(page.getByTestId('import-status')).toHaveText('Done', { timeout: 120_000 });
    await expect(page.getByTestId('import-report')).toContainText('issues');

    // the copy: the seeded issues with new keys on the restored board
    await page.getByRole('link', { name: 'Open what was imported' }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${name.toLowerCase()}`));
    await expect(page.getByText(name).first()).toBeVisible();
    await page.goto(`/workspaces/${SEED.workspace}/projects/${name.toLowerCase()}/boards/${SEED.board}`);
    const hero = page.getByRole('article').filter({ hasText: 'Homepage hero' });
    await expect(hero).toBeVisible();
    await expect(hero).toContainText(new RegExp(`${key}-\\d+`));
});
