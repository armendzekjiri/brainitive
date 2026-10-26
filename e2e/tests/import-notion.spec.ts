import { randomBytes } from 'node:crypto';
import { expect, test, uid } from '../fixtures';
import { SEED } from '../env';
import { docReader } from '../helpers/content';

/**
 * BRAI-66: import a Notion "Markdown & CSV" export. Upload a ZIP → preview the page tree (nothing
 * written yet) → start → report → open the imported page (a draft, with its image) and follow an
 * internal link to the subpage it was rewritten to. The ZIP is built here (synthetic, unique titles),
 * like Notion names it: `Title <32 hex id>.md`, subpages in a folder of the same name.
 */

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
});
const crc32 = (data: Buffer) => {
    let c = 0xffffffff;
    for (const byte of data) c = CRC_TABLE[(c ^ byte) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
};

/** A minimal ZIP (stored, UTF-8 names): enough for an upload. */
function zip(files: Record<string, string | Buffer>): Buffer {
    const parts: Buffer[] = [];
    const central: Buffer[] = [];
    let offset = 0;
    for (const [name, content] of Object.entries(files)) {
        const data = Buffer.isBuffer(content) ? content : Buffer.from(content, 'utf8');
        const fileName = Buffer.from(name, 'utf8');
        const crc = crc32(data);
        const local = Buffer.alloc(30);
        local.writeUInt32LE(0x04034b50, 0);
        local.writeUInt16LE(20, 4);
        local.writeUInt16LE(0x0800, 6); // UTF-8 names
        local.writeUInt32LE(crc, 14);
        local.writeUInt32LE(data.length, 18);
        local.writeUInt32LE(data.length, 22);
        local.writeUInt16LE(fileName.length, 26);
        const header = Buffer.alloc(46);
        header.writeUInt32LE(0x02014b50, 0);
        header.writeUInt16LE(20, 4);
        header.writeUInt16LE(20, 6);
        header.writeUInt16LE(0x0800, 8);
        header.writeUInt32LE(crc, 16);
        header.writeUInt32LE(data.length, 20);
        header.writeUInt32LE(data.length, 24);
        header.writeUInt16LE(fileName.length, 28);
        header.writeUInt32LE(offset, 42);
        parts.push(local, fileName, data);
        central.push(header, fileName);
        offset += local.length + fileName.length + data.length;
    }
    const directory = Buffer.concat(central);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(Object.keys(files).length, 8);
    end.writeUInt16LE(Object.keys(files).length, 10);
    end.writeUInt32LE(directory.length, 12);
    end.writeUInt32LE(offset, 16);
    return Buffer.concat([...parts, directory, end]);
}

const hex = () => randomBytes(16).toString('hex');
const enc = (path: string) => path.split('/').map(encodeURIComponent).join('/');

function notionExport(title: string) {
    const [root, guide, contacts] = [hex(), hex(), hex()];
    const dir = `${title} ${root}`;
    return zip({
        [`${dir}.md`]: `# ${title}\n\nStart with [the onboarding guide](${enc(`${dir}/Onboarding ${guide}.md`)}).\n\n[Contacts](${enc(`${dir}/Contacts ${contacts}.csv`)})\n\n![Team photo](${enc(`${dir}/team.png`)})\n`,
        [`${dir}/Onboarding ${guide}.md`]: `# Onboarding\n\n- [x] Get a laptop\n- [ ] Ask for accounts\n\nBack to [the handbook](${enc(`../${dir}.md`)}). The [old page](${enc(`Gone ${hex()}.md`)}) is gone.\n`,
        [`${dir}/Contacts ${contacts}.csv`]: 'Name,Email\nAda,ada@example.test\nGrace,grace@example.test\n',
        [`${dir}/team.png`]: PNG,
    });
}

test('a Notion export becomes a draft page tree, previewed first, with working internal links', async ({ page, memberPage }) => {
    const title = uid('Handbook');

    await page.goto(`/workspaces/${SEED.workspace}/imports`);
    await page.getByRole('radio', { name: /Notion \(Markdown & CSV\)/ }).click();
    await page.getByRole('combobox', { name: 'Project' }).click();
    await page.getByRole('option', { name: 'Website', exact: true }).click();
    await page.getByLabel('Exported file').setInputFiles({ name: 'Export-notion.zip', mimeType: 'application/zip', buffer: notionExport(title) });
    await page.getByRole('button', { name: 'Upload and preview' }).click();

    // the preview: the tree, the table, the image, the broken link
    await expect(page.getByTestId('import-status')).toHaveText('Ready to import');
    const tree = page.getByRole('list', { name: 'Pages to import' });
    await expect(tree).toContainText(title);
    await expect(tree).toContainText('Onboarding');
    await expect(tree.getByRole('listitem').filter({ hasText: /^Contacts/ })).toContainText('table, 2 rows');
    const summary = page.getByRole('list', { name: 'Summary' });
    await expect(summary).toContainText('2 pages');
    await expect(summary).toContainText('1 database as tables');
    await expect(summary).toContainText('1 images');
    await expect(page.getByRole('list', { name: 'Broken links' })).toContainText('Onboarding → Gone');
    await expect(page.getByRole('combobox', { name: 'Parent folder' })).toHaveText('Top of the project');
    await expect(page.getByRole('checkbox', { name: /Import as drafts/ })).toBeDisabled();

    await page.getByRole('button', { name: 'Start import', exact: true }).click();
    await expect(page.getByTestId('import-status')).toHaveText('Done', { timeout: 30_000 });
    const report = page.getByTestId('import-report');
    await expect(report).toContainText('2 pages');
    await expect(report).toContainText('1 tables');
    await expect(report).toContainText('1 images');

    // the top folder holds the page first, then its subpages
    await page.getByRole('link', { name: 'Open what was imported' }).click();
    await page.locator('a', { has: page.getByRole('heading', { level: 3, name: title, exact: true }) }).click();

    // the imported page: a draft (the author edits it), its image stored like an upload
    await expect(page.getByLabel('Page title')).toHaveValue(title);
    const doc = docReader(page);
    await expect(doc.getByRole('img', { name: 'Team photo' })).toHaveAttribute('src', /\/storage\/uploads\/images\/[0-9a-f-]{36}\.png$/);
    const link = doc.getByRole('link', { name: 'the onboarding guide' });
    const href = await link.getAttribute('href');
    expect(href).toMatch(/\/workspaces\/e2e-workspace\/projects\/website\/pages\/[0-9a-f-]{36}$/);

    // follow the internal link
    await page.goto(href!);
    await expect(page.getByLabel('Page title')).toHaveValue('Onboarding');
    await expect(docReader(page).getByRole('link', { name: 'the handbook' })).toHaveAttribute('href', /\/pages\/[0-9a-f-]{36}$/);
    await expect(docReader(page)).toContainText('The old page is gone.');

    // drafts: another project member can't open it
    const response = await memberPage.goto(href!);
    expect(response?.status()).toBe(403);
});

test('a ZIP that is not a Notion export fails before anything is created', async ({ page }) => {
    await page.goto(`/workspaces/${SEED.workspace}/imports`);
    await page.getByRole('radio', { name: /Notion \(Markdown & CSV\)/ }).click();
    await page.getByRole('combobox', { name: 'Project' }).click();
    await page.getByRole('option', { name: 'Website', exact: true }).click();
    await page.getByLabel('Exported file').setInputFiles({ name: 'photos.zip', mimeType: 'application/zip', buffer: zip({ 'readme.txt': 'hello' }) });
    await page.getByRole('button', { name: 'Upload and preview' }).click();

    await expect(page.getByTestId('import-status')).toHaveText('Failed');
    await expect(page.getByRole('alert').filter({ hasText: 'doesn’t look like a Notion export' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Start import', exact: true })).toHaveCount(0);
});
