import type { Locator, Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import {
    boardColumn, createHelpdesk, createProject, createWorkspace, projectKey, embedUrl, fillPublicForm, guestPage, pageProps, statusSelect, ticketCard, ticketDialog, typeRich,
} from '../helpers/helpdesk';

/*
 * Helpdesk: the agents' ticket board and the public side (widget / embed, support page, customer
 * portal). Public submissions, replies and uploads are `throttle:20,1`, and Laravel keys every
 * plain `throttle:` route by IP alone, so guests share ONE bucket across all public throttled routes
 * (helpdesk, lead forms, share links). This file makes 9 such requests per run, so
 * `--repeat-each=2` stays below 20 — other specs' guest requests in the same minute count too.
 */

const PNG_1PX = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

/** A labelled block in the "New ticket" dialog (labels there are not bound to their inputs). */
const block = (root: Locator, label: string) =>
    root.locator('div.space-y-1\\.5').filter({ has: root.page().locator('label', { hasText: new RegExp(`^${label}`) }) });

/**
 * Tests that create tickets get a project of their own. Ticket keys used to be handed out without
 * a lock (fixed in BRAI-96, Project::bumpCounter) — the race has its own regression test below.
 */
const ownProject = (page: Page) => createProject(page, uid('Helpdesk project'), projectKey());

/** The helpdesk's card on the project page (the sidebar has a second link with the same name). */
const helpdeskCardLink = (page: Page, name: string) =>
    page.getByRole('link').filter({ has: page.getByRole('heading', { level: 3, name, exact: true }) });

async function openTicket(page: Page, subject: string) {
    await ticketCard(page, subject).click();
    const dialog = ticketDialog(page);
    await expect(dialog.getByRole('heading', { level: 1, name: subject })).toBeVisible();
    return dialog;
}

async function moveTo(page: Page, dialog: Locator, column: string) {
    await statusSelect(dialog).click();
    await page.getByRole('option', { name: column, exact: true }).click();
}

test.describe('helpdesk', () => {
    test('a helpdesk created from a template gets the template columns and form fields', async ({ page }) => {
        const name = uid('Support desk');
        await page.goto(urls.project());
        await page.getByRole('heading', { name: 'Helpdesks', exact: true }).locator('..').getByRole('button', { name: 'Add' }).click();

        const dialog = page.getByRole('dialog', { name: 'Create Helpdesk' });
        await dialog.getByRole('radio', { name: /Customer support/ }).click();
        // the preview lists what the template sets up
        await expect(dialog.getByText('Waiting on customer', { exact: true })).toBeVisible();
        await dialog.getByPlaceholder('e.g., Customer Support').fill(name);
        await dialog.getByRole('button', { name: 'Create', exact: true }).click();

        await expect(page).toHaveURL(/\/helpdesks\/support-desk-/);
        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
        for (const column of ['New', 'Open', 'Waiting on customer', 'Resolved']) {
            await expect(page.getByRole('heading', { level: 3, name: column, exact: true })).toBeVisible();
        }

        // the template's form fields are in the settings
        await page.getByRole('link').filter({ has: page.locator('svg.lucide-settings') }).click();
        await expect(page.getByRole('heading', { name: 'Helpdesk Settings' })).toBeVisible();
        await page.getByRole('tab', { name: 'Fields' }).click();
        await expect(page.getByText('Order or account number').first()).toBeVisible();
        await expect(page.getByText('Category').first()).toBeVisible();

        // and it is listed on the project page
        await page.goto(urls.project());
        await expect(helpdeskCardLink(page, name)).toBeVisible();
    });

    test('custom form fields added in the settings show up on the public form', async ({ page, browser }) => {
        const hd = await createHelpdesk(page, uid('Fields desk'));
        await page.goto(`${hd.url}/edit`);
        await page.getByRole('tab', { name: 'Fields' }).click();

        // a required text field
        await page.getByRole('button', { name: 'Add field' }).first().click();
        let dialog = page.getByRole('dialog', { name: 'Add form field' });
        await dialog.getByLabel('Label').fill('Order number');
        await dialog.getByRole('combobox').selectOption('text');
        await dialog.getByLabel('Required').check();
        await dialog.getByRole('button', { name: 'Add field' }).click();
        await expect(dialog).toBeHidden();

        // a dropdown with options
        await page.getByRole('button', { name: 'Add field' }).first().click();
        dialog = page.getByRole('dialog', { name: 'Add form field' });
        await dialog.getByLabel('Label').fill('Product area');
        await dialog.getByRole('combobox').selectOption('select');
        await dialog.getByLabel('Options (one per line)').fill('Web app\nMobile app\nAPI');
        await dialog.getByRole('button', { name: 'Add field' }).click();
        await expect(dialog).toBeHidden();

        const preview = page.locator('div.rounded-lg', { has: page.getByText('Form preview (order on submit form)') });
        await expect(preview.getByText('Order number')).toBeVisible();
        await expect(preview.getByText('Product area')).toBeVisible();

        // the Embed tab hands out the iframe URL; a visitor opening it sees both fields
        await page.getByRole('tab', { name: 'Embed' }).click();
        const iframeUrl = (await page.locator('pre', { hasText: `/widget/helpdesks/${hd.uuid}/embed` }).first().textContent())!.trim();
        expect(iframeUrl).toMatch(new RegExp(`/widget/helpdesks/${hd.uuid}/embed$`));

        const guest = await guestPage(browser);
        await guest.page.goto(iframeUrl);
        await expect(guest.page.getByLabel('Order number *')).toBeVisible();
        await expect(guest.page.getByLabel('Order number *')).toHaveAttribute('required', '');
        const area = guest.page.getByLabel('Product area');
        await expect(area.locator('option')).toHaveText(['— Select —', 'Web app', 'Mobile app', 'API']);
        await guest.close();
    });

    test('customer submits the public form, the agent replies and moves it, the customer answers in the portal', async ({ page, browser }) => {
        test.slow(); // guest + agent sessions, two reloads
        const hd = await createHelpdesk(page, uid('Journey desk'), {
            project: await ownProject(page),
            fields: [{ label: 'Order number', type: 'text', required: true }, { label: 'Product area', type: 'select', options: ['Web app', 'API'], onCard: true }],
        });
        const subject = uid('Cannot log in');
        const email = `${uid('customer')}@example.test`;

        // 1. the customer fills in the public form
        const guest = await guestPage(browser);
        await guest.page.goto(embedUrl(hd.uuid));
        await fillPublicForm(guest.page, guest.page, {
            name: 'Casey Customer',
            email,
            subject,
            message: 'The login button does nothing.',
            fields: { 'Order number': 'ORD-4711' },
            selects: { 'Product area': 'API' },
        });
        await guest.page.getByRole('button', { name: 'Submit' }).click();

        // ... and lands on the ticket's portal page (the confirmation carries the access link)
        await expect(guest.page).toHaveURL(/\/portal\/tickets\/[0-9a-f-]+\?token=/);
        await expect(guest.page.getByText(subject)).toBeVisible();
        await expect(guest.page.getByText('The login button does nothing.')).toBeVisible();
        await expect(guest.page.getByText('No replies yet. Add a reply below.')).toBeVisible();
        const portalUrl = guest.page.url();

        // 2. the agent sees it in the first column, with the form data
        await page.goto(hd.url);
        await expect(boardColumn(page, 'Open').getByText(subject)).toBeVisible();
        await expect(ticketCard(page, subject).getByText('Product area: API')).toBeVisible();
        let dialog = await openTicket(page, subject);
        await expect(dialog.getByText('ORD-4711')).toBeVisible();
        await expect(dialog.getByText('Casey Customer').first()).toBeVisible();

        // replies to the customer and moves the ticket to In Progress
        const reply = 'Thanks Casey, we are looking into it.';
        await typeRich(page, dialog.locator('[contenteditable="true"]'), reply);
        const posted = page.waitForResponse((r) => r.url().endsWith('/replies') && r.request().method() === 'POST');
        await dialog.getByRole('button', { name: 'Reply', exact: true }).click();
        expect((await posted).status(), 'a success or the Inertia redirect after it').toBeLessThan(400);

        const moved = page.waitForResponse((r) => r.url().includes('/move') && r.request().method() === 'PATCH');
        await moveTo(page, dialog, 'In Progress');
        expect((await moved).status(), 'a success or the Inertia redirect after it').toBeLessThan(400);

        // both survive a reload
        await page.reload();
        await expect(boardColumn(page, 'In Progress').getByText(subject)).toBeVisible();
        await expect(boardColumn(page, 'Open').getByText(subject)).toHaveCount(0);
        dialog = await openTicket(page, subject);
        await expect(dialog.getByText(reply)).toBeVisible();
        await page.keyboard.press('Escape');

        // 3. the customer sees the reply and the new status, and answers
        await guest.page.goto(portalUrl);
        await expect(guest.page.getByText(reply)).toBeVisible();
        await expect(guest.page.getByText('Support ticket · In Progress')).toBeVisible();
        const answer = 'It works again after clearing the cache.';
        await typeRich(guest.page, guest.page.locator('[contenteditable="true"]'), answer);
        await guest.page.getByRole('button', { name: 'Send reply' }).click();
        await expect(guest.page.locator('li').filter({ hasText: answer })).toBeVisible();
        await expect(guest.page.locator('li').filter({ hasText: answer })).toContainText('Casey Customer');
        await guest.close();

        // 4. the agent sees the customer's answer on the ticket
        await page.reload();
        dialog = await openTicket(page, subject);
        await expect(dialog.getByText(answer)).toBeVisible();
    });

    test('the agent creates a ticket by hand and resolving it asks for a closing message', async ({ page }) => {
        await createHelpdesk(page, uid('Phone desk'), { project: await ownProject(page) });
        const subject = uid('Called about invoice');

        await page.getByRole('button', { name: 'New ticket', exact: true }).click();
        const form = page.getByRole('dialog', { name: 'New ticket' });
        await form.getByPlaceholder('customer@example.com').fill(`${uid('caller')}@example.test`);
        await block(form, 'Name').getByRole('textbox').fill('Pat Phone');
        await block(form, 'Subject').getByRole('textbox').fill(subject);
        await block(form, 'Message').getByRole('textbox').fill('Invoice 2026-10 is missing the discount.');
        await form.getByRole('button', { name: 'Create ticket' }).click();
        await expect(form).toBeHidden();
        await expect(boardColumn(page, 'Open').getByText(subject)).toBeVisible();

        // moving it to the done column opens the resolve dialog with a prefilled message
        const dialog = await openTicket(page, subject);
        await moveTo(page, dialog, 'Resolved');
        const resolve = page.getByRole('dialog', { name: /^Resolve / });
        await expect(resolve.getByLabel('Message to the customer')).toHaveValue(/Hi Pat Phone,/);
        const closing = 'The corrected invoice is on its way. Have a nice day!';
        await resolve.getByLabel('Message to the customer').fill(closing);
        await resolve.getByLabel(/that the ticket is resolved/).uncheck();
        await resolve.getByRole('button', { name: 'Resolve ticket' }).click();
        await expect(resolve).toBeHidden();

        await page.reload();
        await expect(boardColumn(page, 'Resolved').getByText(subject)).toBeVisible();
        const reopened = await openTicket(page, subject);
        await expect(reopened.getByText(closing)).toBeVisible();
    });

    // Regression (bug found by this suite, fixed in BRAI-96): the custom fields in the ticket dialog's Details sidebar are dead inputs —
    // resources/js/Pages/Helpdesks/Show.tsx (TicketEditModal) starts `fieldValues` as {} (so the
    // customer's value is never shown there, only "Optional"/"Required") and its onBlur is an empty
    // stub ("Save field value via API"), so nothing reaches tickets.fields.update and edits are lost.
    test('the agent corrects a custom field in the ticket sidebar and it is saved', async ({ page }) => {
        await createHelpdesk(page, uid('Field edit desk'), { project: await ownProject(page), fields: [{ label: 'Order number', type: 'text' }] });
        const subject = uid('Wrong order number');

        await page.getByRole('button', { name: 'New ticket', exact: true }).click();
        const form = page.getByRole('dialog', { name: 'New ticket' });
        await form.getByPlaceholder('customer@example.com').fill(`${uid('typo')}@example.test`);
        await block(form, 'Subject').getByRole('textbox').fill(subject);
        await block(form, 'Order number').getByRole('textbox').fill('ORD-1');
        await form.getByRole('button', { name: 'Create ticket' }).click();
        await expect(form).toBeHidden();

        const dialog = await openTicket(page, subject);
        const sidebarField = dialog.locator('div.min-h-\\[40px\\]').filter({ hasText: /^Order number/ }).getByRole('textbox');
        await expect(sidebarField).toHaveValue('ORD-1'); // fails today: empty
        await sidebarField.fill('ORD-2');
        await Promise.all([
            page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().endsWith('/fields')),
            sidebarField.blur(),
        ]);

        await page.reload();
        const reopened = await openTicket(page, subject);
        await expect(reopened.locator('dl').getByText('ORD-2')).toBeVisible();
    });

    test('the widget works inside an iframe on another page: submit, then reply in the frame', async ({ page, browser }) => {
        const hd = await createHelpdesk(page, uid('Iframe desk'), { project: await ownProject(page) });
        const subject = uid('Embedded question');

        const guest = await guestPage(browser);
        await guest.page.goto(`/embed-test/${hd.uuid}`);
        await expect(guest.page.getByRole('heading', { name: `Embed test: ${hd.name}` })).toBeVisible();
        const frame = guest.page.frameLocator('iframe[title="Contact support"]');
        await expect(frame.getByLabel('Email *')).toBeVisible();
        await fillPublicForm(frame, guest.page, { email: `${uid('framed')}@example.test`, subject, message: 'Asked from inside an iframe.' });
        await frame.getByRole('button', { name: 'Submit' }).click();

        // inside a frame the ticket opens in the embed itself (the portal refuses to be framed)
        await expect(frame.getByText(subject)).toBeVisible();
        await expect(frame.getByRole('button', { name: 'Send reply' })).toBeVisible();
        expect(guest.page.url()).toContain(`/embed-test/${hd.uuid}`);
        const frameUrl = guest.page.frames().find((f) => f !== guest.page.mainFrame())!.url();
        expect(frameUrl).toMatch(new RegExp(`/widget/helpdesks/${hd.uuid}/embed\\?ticket=[0-9a-f-]+&token=`));

        await frame.getByLabel('Reply').fill('One more detail from the frame.');
        await frame.getByRole('button', { name: 'Send reply' }).click();
        await expect(frame.getByText('One more detail from the frame.')).toBeVisible();
        await expect(frame.getByText(/· You/)).toBeVisible();
        await guest.close();

        await page.reload();
        const dialog = await openTicket(page, subject);
        await expect(dialog.getByText('One more detail from the frame.')).toBeVisible();
    });

    test('public uploads: scripts are refused, text files and images are attached', async ({ page, browser }) => {
        const hd = await createHelpdesk(page, uid('Upload desk'), { project: await ownProject(page) });
        const subject = uid('Screenshot attached');

        const guest = await guestPage(browser);
        await guest.page.goto(embedUrl(hd.uuid));
        await fillPublicForm(guest.page, guest.page, { email: `${uid('uploader')}@example.test`, subject });
        const fileInput = guest.page.locator('input[type="file"]');

        // a script is refused as soon as it is picked
        await fileInput.setInputFiles({ name: 'invoice.pdf.php', mimeType: 'application/x-php', buffer: Buffer.from('<?php echo "hi";') });
        await expect(guest.page.getByText(`"invoice.pdf.php": Programs and scripts can't be attached here.`)).toBeVisible();
        await expect(guest.page.getByRole('button', { name: 'Remove file' })).toHaveCount(0);

        // harmless files are listed and uploaded with the ticket
        await fileInput.setInputFiles([
            { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('steps to reproduce\n') },
            { name: 'pixel.png', mimeType: 'image/png', buffer: PNG_1PX },
        ]);
        await expect(guest.page.getByRole('button', { name: 'Remove file' })).toHaveCount(2);
        const uploads: number[] = [];
        guest.page.on('response', (r) => { if (r.url().endsWith('/attachments')) uploads.push(r.status()); });
        await guest.page.getByRole('button', { name: 'Submit' }).click();
        await expect(guest.page).toHaveURL(/\/portal\/tickets\/[0-9a-f-]+\?token=/);
        expect(uploads).toEqual([200, 200]);

        // the server refuses programs too, whatever the browser lets through
        const portal = new URL(guest.page.url());
        const ticketUuid = portal.pathname.split('/').pop()!;
        const refused = await guest.page.request.post(`/widget/helpdesks/${hd.uuid}/tickets/${ticketUuid}/attachments`, {
            headers: { Accept: 'application/json' },
            multipart: {
                access_token: portal.searchParams.get('token')!,
                file: { name: 'setup.exe', mimeType: 'application/octet-stream', buffer: Buffer.concat([Buffer.from('MZ'), Buffer.alloc(64)]) },
            },
        });
        expect(refused.status()).toBe(422);
        expect(JSON.stringify(await refused.json())).toContain("Programs and scripts can't be attached here.");
        await guest.close();

        // the agent sees exactly the two harmless files
        await page.reload();
        const dialog = await openTicket(page, subject);
        await expect(dialog.getByRole('heading', { name: /Attachments\s*\(2\)/ })).toBeVisible();
        await expect(dialog.getByText('notes.txt')).toBeVisible();
        await expect(dialog.getByText('pixel.png')).toBeVisible();
        await expect(dialog.getByText('setup.exe')).toHaveCount(0);
    });

    test('a contact form on its own support page ends on the thank-you message', async ({ page, browser }) => {
        const hd = await createHelpdesk(page, uid('Contact desk'), { template: 'contact-form', project: await ownProject(page) });
        const address = uid('contact').toLowerCase();

        // give it a short public address
        await page.goto(`${hd.url}/edit`);
        await page.getByRole('tab', { name: 'Embed' }).click();
        await page.getByLabel('Address').fill(address);
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.locator('code', { hasText: `/support/${address}` })).toBeVisible();

        const guest = await guestPage(browser);
        await guest.page.goto(`/support/${address}`);
        await expect(guest.page.getByRole('heading', { level: 1, name: hd.name })).toBeVisible();
        // the header names the workspace; since BRAI-156 the operator line under the form does too
        await expect(guest.page.getByText('E2E Workspace', { exact: true }).first()).toBeVisible();
        await expect(guest.page.getByTestId('operator-name')).toHaveText('E2E Workspace');
        await expect(guest.page.getByText('Get in touch')).toBeVisible();
        // the template hides the subject (filled in automatically)
        await expect(guest.page.getByLabel(/^Subject/)).toHaveCount(0);

        const sender = uid('Sam Sender');
        await fillPublicForm(guest.page, guest.page, {
            name: sender,
            email: `${uid('sam')}@example.test`,
            message: 'Do you offer a yearly plan?',
            selects: { Topic: 'Sales' },
        });
        await guest.page.getByRole('button', { name: 'Send message' }).click();
        await expect(guest.page.getByRole('status')).toHaveText('Thanks for reaching out! We’ll reply within one business day.');
        await guest.close();

        // the agent gets it in "New" under the automatic subject
        await page.goto(hd.url);
        await expect(boardColumn(page, 'New').getByText(`Website contact — ${sender}`)).toBeVisible();
        await expect(ticketCard(page, `Website contact — ${sender}`).getByText('Topic: Sales')).toBeVisible();
    });

    test('project members see the helpdesk; guests get neither the board nor a ticket without its token', async ({ page, memberPage, browser }) => {
        const hd = await createHelpdesk(page, uid('Shared desk'));
        const subject = uid('Members only');
        await page.getByRole('button', { name: 'New ticket', exact: true }).click();
        const form = page.getByRole('dialog', { name: 'New ticket' });
        await form.getByPlaceholder('customer@example.com').fill(`${uid('someone')}@example.test`);
        await block(form, 'Subject').getByRole('textbox').fill(subject);
        await form.getByRole('button', { name: 'Create ticket' }).click();
        await expect(form).toBeHidden();

        await page.reload();
        const props = await pageProps<{ helpdesk: { columns: { tickets: { uuid: string; subject: string }[] }[] } }>(page);
        const ticketUuid = props.helpdesk.columns.flatMap((c) => c.tickets).find((t) => t.subject === subject)!.uuid;

        // the member (project editor) finds it on the project page and works the board
        await memberPage.goto(urls.project());
        await helpdeskCardLink(memberPage, hd.name).click();
        await expect(memberPage.getByRole('heading', { level: 1, name: hd.name })).toBeVisible();
        await expect(ticketCard(memberPage, subject)).toBeVisible();

        // a guest is sent to the login page, and a ticket link needs its token
        const guest = await guestPage(browser);
        await guest.page.goto(hd.url);
        await expect(guest.page).toHaveURL(/\/login$/);
        const portal = await guest.page.goto(`/portal/tickets/${ticketUuid}?token=not-the-token`);
        expect(portal?.status()).toBe(403);
        await expect(guest.page.getByRole('heading', { name: 'No access' })).toBeVisible();
        await expect(guest.page.getByText(subject)).toHaveCount(0);
        const json = await guest.page.request.get(`/widget/tickets/${ticketUuid}?token=not-the-token`);
        expect(json.status()).toBe(403);
        expect(await json.text()).not.toContain(subject);
        await guest.close();
    });

    // Regression (bug found by this suite, fixed in BRAI-96): ticket keys are handed out without a lock. Project::getNextTicketKey() builds the key from
    // the `next_ticket_number` loaded with the request's Project model and only then increments it,
    // so two tickets created at the same moment in one project get the same key and the second
    // insert fails on `tickets_key_unique` → HTTP 500 for that customer (seen on Postgres:
    // "duplicate key value violates unique constraint tickets_key_unique, Key (key)=(WEB-T5)").
    // getNextItemKey() has the same pattern for board issues (items_project_id_key_unique).
    test('customers submitting at the same moment all get a ticket', async ({ page, browser }) => {
        const hd = await createHelpdesk(page, uid('Busy desk'), { project: await ownProject(page) });
        const guest = await guestPage(browser);
        const results = await Promise.all(Array.from({ length: 6 }, (_, i) => guest.page.request.post(`/widget/helpdesks/${hd.uuid}/tickets`, {
            headers: { Accept: 'application/json' },
            data: { contact_email: `${uid('rush')}@example.test`, subject: `Rush hour ${i}` },
        })));
        const statuses = results.map((r) => r.status());
        await guest.close();
        expect(statuses).toEqual([200, 200, 200, 200, 200, 200]);
    });

    // Regression (bug found by this suite, fixed in BRAI-96): `tickets.key` is unique across the whole instance (create_tickets_table: unique('key')),
    // but project keys are only unique per workspace. A project with the same key in another
    // workspace (e.g. two teams both using "WEB") can never create the ticket number the first one
    // already has: the insert fails on tickets_key_unique → 500.
    test('projects with the same key in two workspaces can both take tickets', async ({ page }) => {
        const key = projectKey();
        const first = await createProject(page, uid('Key project'), key);
        const second = await createProject(page, uid('Key project'), key, await createWorkspace(page, uid('Second workspace')));

        for (const project of [first, second]) {
            await createHelpdesk(page, uid('Same key desk'), { project });
            const subject = uid('First ticket');
            await page.getByRole('button', { name: 'New ticket', exact: true }).click();
            const form = page.getByRole('dialog', { name: 'New ticket' });
            await form.getByPlaceholder('customer@example.com').fill(`${uid('first')}@example.test`);
            await block(form, 'Subject').getByRole('textbox').fill(subject);
            await form.getByRole('button', { name: 'Create ticket' }).click();
            await expect(form).toBeHidden();
            await expect(boardColumn(page, 'Open').getByText(subject)).toBeVisible();
            await expect(ticketCard(page, subject).getByText(`${key}-T1`)).toBeVisible();
        }
    });
});
