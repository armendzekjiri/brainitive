import { expect, test, uid, urls } from '../fixtures';
import { SEED } from '../env';
import { boardCard, createIssue, isoDay, projectSectionAdd } from '../helpers/leads-plans-search';

/**
 * Lead forms (BRAI-93), Gantt plans, releases, sprints / backlog, global search + command
 * palette and assignment notifications. Everything is created with uid() names; the seeded
 * workspace / project / board and WEB-1..3 are only read.
 *
 * Public lead form submits are throttled (10 per minute per IP): this file submits once per run
 * and, when parallel suites used up the budget, sends again once the limit allows it.
 */

test.describe('lead forms', () => {
    test.describe.configure({ mode: 'serial' });

    const formName = uid('lf');
    const lead = { name: `Lena ${uid('lead')}`, email: `${uid('lead')}@example.test`, seats: '42' };
    let formUrl = '';
    let publicUrl = '';

    test('build a multi-step form with icon cards and a conditional step, then publish it', async ({ page }) => {
        await page.goto(urls.project());
        await projectSectionAdd(page, 'Lead forms').click();
        const dialog = page.getByRole('dialog', { name: 'Create Lead Form' });
        await dialog.getByRole('button', { name: /^Blank form/ }).click();
        await dialog.getByLabel('Form name').fill(formName);
        await dialog.getByRole('button', { name: 'Create lead form' }).click();

        // a new form opens in the builder
        await expect(page).toHaveURL(/\/lead-forms\/[^/?]+\?tab=builder/);
        formUrl = page.url().split('?')[0];
        await expect(page.getByRole('heading', { level: 1, name: formName })).toBeVisible();
        const inspector = page.locator('fieldset');

        // step 2: one icon-card question
        await page.getByRole('button', { name: 'Add step' }).click();
        await inspector.getByLabel('Title').fill('Pick a plan');
        await inspector.getByRole('button', { name: 'Add question' }).click();
        await page.getByRole('button', { name: /^Choice cards/ }).click();
        await inspector.getByRole('textbox', { name: 'Question' }).fill('Which plan?');
        await inspector.locator('input[value="Option 1"]').fill('Starter');
        await inspector.locator('input[value="Option 2"]').fill('Enterprise');
        await inspector.getByRole('button', { name: 'Choose icon' }).first().click();
        await page.getByPlaceholder('Search icons…').fill('rocket');
        await page.getByTitle('rocket', { exact: true }).click();

        // step 3: only for "Enterprise"
        await page.getByRole('button', { name: 'Add step' }).click();
        await inspector.getByLabel('Title').fill('Team details');
        await inspector.getByRole('button', { name: 'Only show this step when…' }).click();
        await inspector.getByRole('combobox').filter({ hasText: 'Starter' }).click();
        await page.getByRole('option', { name: 'Enterprise' }).click();
        await expect(inspector.getByText('Skipped unless Which plan? is “Enterprise”.')).toBeVisible();
        await inspector.getByRole('button', { name: 'Add question' }).click();
        await page.getByRole('button', { name: /^Short text/ }).click();
        await inspector.getByRole('textbox', { name: 'Question' }).fill('How many seats?');

        await expect(page.getByText('All changes saved')).toBeVisible();

        // publish under its own address
        await page.getByRole('navigation', { name: 'Lead form sections' }).getByRole('button', { name: 'Share' }).click();
        const slug = formName.toLowerCase();
        await page.getByLabel('Form address').fill(slug);
        await page.getByRole('button', { name: 'Publish', exact: true }).last().click();
        await expect(page.getByRole('heading', { level: 1 }).getByText('Live')).toBeVisible();
        const open = page.getByRole('link', { name: 'Open', exact: true });
        await expect(open).toHaveAttribute('href', new RegExp(`/f/${slug}$`));
        publicUrl = new URL((await open.getAttribute('href'))!).pathname;
    });

    test('a guest fills the public form; the conditional step follows the answer', async ({ browser }) => {
        test.skip(!publicUrl, 'needs the published form');
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const guest = await context.newPage();
        const errors: string[] = [];
        guest.on('pageerror', (e) => errors.push(e.message));

        await guest.goto(publicUrl);
        await expect(guest.getByRole('heading', { name: 'Your details' })).toBeVisible();
        await guest.getByLabel('Full name').fill(lead.name);
        await guest.getByLabel('Email').fill(lead.email);
        await guest.getByRole('button', { name: 'Next' }).click();

        await expect(guest.getByRole('heading', { name: 'Pick a plan' })).toBeVisible();
        const plans = guest.getByRole('radiogroup', { name: 'Which plan?' });
        // the chosen lucide icon is on the card
        await expect(plans.getByRole('radio', { name: 'Starter' }).locator('svg.lucide-rocket')).toBeVisible();
        // without "Enterprise" the conditional step is skipped: this is the last step
        await expect(guest.getByRole('button', { name: 'Submit' })).toBeVisible();
        await plans.getByRole('radio', { name: 'Starter' }).click();
        await expect(plans.getByRole('radio', { name: 'Starter' })).toHaveAttribute('aria-checked', 'true');
        await expect(guest.getByRole('button', { name: 'Submit' })).toBeVisible();

        // "Enterprise" reveals step 3 (a single choice moves on by itself)
        await plans.getByRole('radio', { name: 'Enterprise' }).click();
        await expect(guest.getByRole('heading', { name: 'Team details' })).toBeVisible();
        await guest.getByLabel('How many seats?').fill(lead.seats);

        // Submits are throttled per IP (10/min). Parallel suites on one machine share that budget
        // (127.0.0.1, file cache): on 429 the form says so, and sending again later works.
        test.setTimeout(150_000);
        await expect(async () => {
            const submitted = guest.waitForResponse((r) => r.url().endsWith(`${publicUrl}/submit`) && r.request().method() === 'POST');
            await guest.getByRole('button', { name: 'Submit' }).click();
            const status = (await submitted).status();
            if (status === 429) await expect(guest.getByText('Too many tries — please wait a minute and send again.')).toBeVisible();
            expect(status).toBeLessThan(300);
        }).toPass({ intervals: [10_000], timeout: 120_000 });
        await expect(guest.getByRole('heading', { name: 'Thank you!' })).toBeVisible();

        expect(errors, 'uncaught errors on the public form').toEqual([]);
        await context.close();
    });

    test('the lead lands on the form’s leads board with its answers; analytics count it', async ({ page }) => {
        test.skip(!formUrl, 'needs the form');
        await page.goto(`${formUrl}?tab=leads`);
        const card = page.getByRole('button', { name: `Lead #1: ${lead.name}` });
        await expect(card).toBeVisible();
        await expect(card).toContainText(lead.email);

        await card.click();
        const details = page.getByRole('dialog');
        await expect(details.getByText('Enterprise')).toBeVisible();
        await expect(details.getByText(lead.seats, { exact: true })).toBeVisible();
        await page.keyboard.press('Escape');

        await page.getByRole('navigation', { name: 'Lead form sections' }).getByRole('button', { name: 'Analytics' }).click();
        await expect(page.getByRole('heading', { name: 'Analytics' })).toBeVisible();
        await expect(page.getByText('How far visitors get')).toBeVisible();
        await expect(page.getByText('Could not load the analytics.')).toHaveCount(0);
    });

    // Regression (bug found by this suite, fixed in BRAI-96): the submit limit (throttle:10,1) shares its counter with every other plain `throttle:N,M`
    // route of a guest IP — Laravel keys them all sha1('|'.ip). The form page (GET /f/{slug}) and its
    // analytics events (POST /f/{slug}/events: view, start, step…) count against the 10 submits, so a
    // visitor who opened the form a few times (or clicked through a long form) gets 429 "Too many
    // tries" on the very first submit. Fix: a per-route prefix / named limiter for lead-forms.submit.
    test('a visitor who reloaded the form a few times can still submit once', async ({ browser }) => {
        test.skip(!publicUrl, 'needs the published form');
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const guest = await context.newPage();
        for (let visit = 0; visit < 6; visit++) {
            const viewed = guest.waitForResponse((r) => r.url().endsWith(`${publicUrl}/events`));
            await guest.goto(publicUrl);
            await viewed;
        }
        await guest.getByLabel('Full name').fill(lead.name);
        await guest.getByLabel('Email').fill(`again-${lead.email}`);
        await guest.getByRole('button', { name: 'Next' }).click();
        await guest.getByRole('radio', { name: 'Starter' }).click();
        const submitted = guest.waitForResponse((r) => r.url().endsWith(`${publicUrl}/submit`));
        await guest.getByRole('button', { name: 'Submit' }).click();
        expect((await submitted).status(), 'first submit of this visitor').toBeLessThan(300);
        await expect(guest.getByRole('heading', { name: 'Thank you!' })).toBeVisible();
        await context.close();
    });
});

test.describe('plans', () => {
    test('create a Gantt plan, add a row with a bar, and it survives a reload', async ({ page }) => {
        const planName = uid('plan');
        const rowName = uid('row');
        await page.goto(urls.project());
        await projectSectionAdd(page, 'Plans').click();
        const dialog = page.getByRole('dialog', { name: 'Create Plan' });
        await dialog.getByPlaceholder('e.g., Roadmap 2027').fill(planName);
        await expect(dialog.getByRole('radio', { name: /Blank plan/ })).toHaveAttribute('aria-checked', 'true');
        await dialog.getByRole('button', { name: 'Create', exact: true }).click();
        await expect(page).toHaveURL(/\/plans\/[^/]+$/);
        await expect(page.getByRole('heading', { level: 1, name: planName })).toBeVisible();

        // a row, named inline
        await page.getByRole('toolbar', { name: 'Plan tools' }).getByRole('button', { name: 'Row', exact: true }).click();
        const nameInput = page.getByRole('textbox', { name: 'name', exact: true });
        await nameInput.fill(rowName);
        await nameInput.press('Enter');
        const row = page.getByText(rowName, { exact: true });
        await expect(row).toBeVisible();

        // draw a bar on its timeline, right of the (sticky) grid
        const grid = await row.boundingBox();
        expect(grid).not.toBeNull();
        // the dashed "Add row" button is exactly as wide as the grid
        const addRow = await page.getByRole('button', { name: /^Add row/ }).boundingBox();
        const gridRight = addRow!.x + addRow!.width;
        const y = grid!.y + grid!.height / 2;
        const saved = page.waitForResponse((r) => r.url().includes('/document') && r.request().method() === 'PUT' && r.ok());
        await page.mouse.move(gridRight + 60, y);
        await page.mouse.down();
        await page.mouse.move(gridRight + 160, y, { steps: 8 });
        await page.mouse.move(gridRight + 260, y, { steps: 8 });
        await page.mouse.up();
        const bar = page.locator(`[data-bar-id][title^="${rowName}:"]`);
        await expect(bar).toBeVisible();
        await saved;
        await expect(page.getByText('Saving…')).toHaveCount(0);

        await page.reload();
        await expect(page.getByText(rowName, { exact: true })).toBeVisible();
        await expect(page.locator(`[data-bar-id][title^="${rowName}:"]`)).toHaveCount(1);
    });
});

test.describe('releases', () => {
    test('create a release and add an issue to it', async ({ page }) => {
        const issueTitle = uid('release-issue');
        const releaseName = uid('v');
        const key = await createIssue(page, issueTitle);

        await page.goto(`${urls.project()}/releases`);
        await expect(page.getByRole('heading', { name: 'Releases' })).toBeVisible();
        await page.getByRole('button', { name: 'Create release' }).click();
        const dialog = page.getByRole('dialog', { name: 'Create release' });
        await dialog.getByLabel('Name').fill(releaseName);
        await dialog.getByLabel('Release date').fill(isoDay(14));
        await dialog.getByRole('button', { name: 'Create release' }).click();
        await expect(page).toHaveURL(/\/releases\/\d+$/);
        await expect(page.getByRole('heading', { name: releaseName })).toBeVisible();

        await page.getByRole('button', { name: 'Add issues' }).click();
        const add = page.getByRole('dialog', { name: 'Add issues' });
        await add.getByLabel('Search issues').fill(issueTitle);
        await add.getByLabel(`Select ${key}`).check();
        await add.getByRole('button', { name: 'Add 1 issue' }).click();
        await expect(add).toBeHidden();
        await expect(page.getByRole('tab', { name: 'Issues (1)' })).toBeVisible();
        await expect(page.getByRole('link', { name: issueTitle })).toBeVisible();

        // listed with its issue count on the releases page
        await page.goto(`${urls.project()}/releases`);
        await expect(page.getByRole('link', { name: new RegExp(releaseName) })).toContainText('1 issue');
    });
});

test.describe('sprints', () => {
    test('on a new scrum board: create a sprint, plan an issue into it and start it', async ({ page }) => {
        const boardName = uid('scrum');
        const issueTitle = uid('sprint-issue');
        const sprintName = uid('sprint');

        await page.goto(urls.project());
        await projectSectionAdd(page, 'Boards').click();
        const dialog = page.getByRole('dialog', { name: 'Create Board' });
        await dialog.getByPlaceholder('e.g., Sprint Board').fill(boardName);
        await dialog.getByRole('button', { name: 'Create', exact: true }).click();
        await expect(page).toHaveURL(/\/boards\/[^/]+$/);
        const boardUrl = new URL(page.url()).pathname;

        // turn sprints on
        await page.goto(`${boardUrl}/edit`);
        await page.getByRole('checkbox', { name: /Use sprints \(Scrum\)/ }).check();
        const saved = page.waitForResponse((r) => new URL(r.url()).pathname === boardUrl && r.request().method() === 'PUT');
        await page.getByRole('button', { name: 'Save Changes' }).first().click();
        expect((await saved).status()).toBeLessThan(400);

        await page.goto(`${boardUrl}/backlog`);
        await page.getByRole('button', { name: 'Create issue' }).click();
        const quickAdd = page.locator('form').filter({ has: page.getByPlaceholder('What needs to be done?') });
        await quickAdd.getByPlaceholder('What needs to be done?').fill(issueTitle);
        await quickAdd.getByRole('button', { name: 'Create', exact: true }).click();
        const issueRow = page.getByRole('listitem').filter({ hasText: issueTitle });
        await expect(issueRow).toBeVisible();

        await page.getByRole('button', { name: 'Create sprint' }).click();
        const sprint = page.locator('section').filter({ has: page.getByRole('button', { name: `${SEED.key} Sprint 1` }) });
        await expect(sprint).toBeVisible();
        await expect(sprint.getByRole('button', { name: 'Start sprint' })).toBeDisabled();

        // drag the issue from the backlog into the sprint
        const ranked = page.waitForResponse((r) => r.url().includes('/backlog/rank') && r.request().method() === 'POST');
        await issueRow.dragTo(sprint.getByText('Plan this sprint by dragging issues here from the backlog.'));
        await ranked;
        await expect(sprint.getByRole('listitem').filter({ hasText: issueTitle })).toBeVisible();
        await expect(sprint.getByText('(1 issue)')).toBeVisible();

        // start it with explicit dates
        await sprint.getByRole('button', { name: 'Start sprint' }).click();
        const start = page.getByRole('dialog', { name: 'Start sprint' });
        await start.getByLabel('Sprint name').fill(sprintName);
        await start.getByLabel('Start date').fill(isoDay(0));
        await start.getByLabel('End date').fill(isoDay(7));
        await start.getByRole('button', { name: 'Start', exact: true }).click();

        // the board now shows the active sprint with the issue
        await expect(page).toHaveURL(new RegExp(`${boardUrl}$`));
        await expect(page.getByText(sprintName).first()).toBeVisible();
        await expect(boardCard(page, issueTitle)).toBeVisible();
    });
});

test.describe('search', () => {
    test('the search endpoint finds a seeded issue and board case-insensitively', async ({ page }) => {
        const issues = await (await page.request.get('/search?q=checkout')).json();
        expect(issues.items.map((i: { title: string }) => i.title)).toContain('Checkout bug');

        const boards = await (await page.request.get('/search?q=main%20board')).json();
        expect(boards.boards.map((b: { title: string }) => b.title)).toContain('Main Board');
    });

    test('command palette (Ctrl+K) opens a seeded issue', async ({ page }) => {
        await page.goto(urls.home);
        await page.keyboard.press('Control+k');
        const input = page.getByRole('combobox', { name: 'Search or type a command' });
        await expect(input).toBeVisible();
        await input.fill('checkout');
        const result = page.getByRole('option', { name: /Checkout bug/ });
        await expect(result).toBeVisible();
        await result.click();

        await expect(page).toHaveURL(new RegExp(`/boards/${SEED.board}`));
        await expect(page.getByRole('dialog', { name: /Checkout bug/ })).toBeVisible();
    });

    test('command palette finds the Main Board by "main board" and opens it', async ({ page }) => {
        await page.goto(urls.home);
        await page.keyboard.press('Control+k');
        const input = page.getByRole('combobox', { name: 'Search or type a command' });
        await input.fill('main board');
        const result = page.getByRole('option', { name: /^Main Board Website/ });
        await expect(result).toBeVisible();
        await result.click();
        await expect(page).toHaveURL(new RegExp(`${urls.board()}$`));
        await expect(page.getByText('Homepage hero')).toBeVisible();
    });
});

test.describe('notifications', () => {
    test('assigning an issue to Max shows up in his notifications', async ({ page, memberPage }) => {
        const title = uid('assign');
        const key = await createIssue(page, title);

        const card = boardCard(page, title);
        await card.getByTitle('Unassigned — click to assign').click();
        await page.getByRole('button', { name: 'Max Member' }).click();
        await expect(card.getByTitle(/Assignee: Max Member/)).toBeVisible();

        await memberPage.goto('/notifications');
        const entry = memberPage.getByText(`assigned you to ${key}`);
        await expect(entry).toBeVisible();
        await expect(memberPage.getByRole('link', { name: key })).toBeVisible();
    });
});
