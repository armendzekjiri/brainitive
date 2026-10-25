import type { Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { REMOTE } from '../env';
import { createHelpdesk, ticketCard, ticketDialog, typeRich } from '../helpers/helpdesk';

/*
 * Email → helpdesk through a connected IMAP mailbox (BRAI-79). The local e2e app fakes the IMAP /
 * SMTP connection (e2e/env.ts: HELPDESK_MAIL_FAKE; the password "wrong-password" fails the login),
 * and the e2e-only route `helpdesks.mailbox.e2e-receive` feeds a fixture from tests/Fixtures/mail to
 * InboundMail::receive() as if the poller had fetched it. dev.brainitive.app has neither: local only.
 */

async function xsrf(page: Page): Promise<Record<string, string>> {
    const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!cookie) throw new Error('no XSRF-TOKEN cookie: open an app page first');
    return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value), Accept: 'application/json' };
}

/** Setup shortcut: connect a (fake) mailbox through the section's own endpoint. */
async function connectMailbox(page: Page, helpdeskUrl: string) {
    const res = await page.request.put(`${helpdeskUrl}/mailbox`, {
        headers: await xsrf(page),
        data: {
            enabled: true, email_address: 'support@helpdesk.example', imap_host: 'imap.helpdesk.example', imap_port: 993,
            imap_encryption: 'ssl', imap_username: 'support@helpdesk.example', imap_password: 'secret', imap_folder: 'INBOX', smtp_mode: 'app',
        },
    });
    expect(res.ok(), `connect mailbox: ${res.status()}`).toBeTruthy();
}

async function receive(page: Page, helpdeskUrl: string, data: Record<string, string | number>) {
    const res = await page.request.post(`${helpdeskUrl}/mailbox/e2e-receive`, { headers: await xsrf(page), data });
    expect(res.ok(), `e2e-receive: ${res.status()}`).toBeTruthy();
    return (await res.json()) as { result: string; ticket_id: number | null; ticket_key: string | null };
}

test.describe('helpdesk connected mailbox', () => {
    test.skip(REMOTE, 'the fake mailbox and the e2e receive route exist only in the local e2e app');

    test('an editor connects a mailbox: test connection, save, the password stays write-only', async ({ page }) => {
        const hd = await createHelpdesk(page, uid('Mailbox desk'));
        await page.goto(`${hd.url}/edit?tab=email`);

        const section = page.getByRole('form', { name: 'Connected mailbox' });
        await expect(page.getByRole('heading', { name: 'Connected mailbox' })).toBeVisible();
        await section.getByLabel('Address customers write to').fill('support@helpdesk.example');
        await section.getByLabel('IMAP server').fill('imap.helpdesk.example');
        await section.getByLabel('IMAP user name').fill('support@helpdesk.example');
        await section.getByLabel('IMAP password').fill('wrong-password');

        // a failing login shows the server's answer
        await section.getByRole('button', { name: 'Test connection', exact: true }).click();
        await expect(section.getByRole('status')).toContainText('Login failed');

        await section.getByLabel('IMAP password').fill('app-password-1');
        await section.getByRole('button', { name: 'Test connection', exact: true }).click();
        await expect(section.getByRole('status')).toHaveText('Connected to INBOX: 0 unread messages.');

        await section.getByRole('button', { name: 'Save mailbox', exact: true }).click();
        await expect(page.getByText('Mailbox saved')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Connected mailbox' }).getByText('Active')).toBeVisible();

        // after a reload: settings are back, the password is not
        await page.reload();
        await expect(section.getByLabel('IMAP server')).toHaveValue('imap.helpdesk.example');
        await expect(section.getByLabel('IMAP password')).toHaveValue('');
        await expect(section.getByText('Saved. Leave empty to keep it.')).toBeVisible();

        // an invalid host name is refused (private networks: PHPUnit, e2e allows them for its fake server)
        await section.getByLabel('IMAP server').fill('not a host');
        await section.getByRole('button', { name: 'Save mailbox', exact: true }).click();
        await expect(section.getByText('The IMAP server field format is invalid.')).toBeVisible();
    });

    test('an email becomes a ticket; the agent reply is emailed and the customer answer threads back', async ({ page }) => {
        const hd = await createHelpdesk(page, uid('Inbox desk'));
        await connectMailbox(page, hd.url);
        const subject = uid('Printer on fire');

        const created = await receive(page, hd.url, { fixture: 'new-ticket', subject, message_id: `${uid('m')}@mail.customer.example` });
        expect(created.result).toBe('ticket_created');

        await page.goto(hd.url);
        await ticketCard(page, subject).click();
        const dialog = ticketDialog(page);
        await expect(dialog.getByRole('heading', { level: 1, name: subject })).toBeVisible();
        await expect(dialog.getByText('Grüße from München!')).toBeVisible();

        // the agent answers: it goes out by email (fake SMTP = the app's log mailer)
        const answer = 'Please restart the printer.';
        await typeRich(page, dialog.locator('[contenteditable="true"]'), answer);
        await dialog.getByRole('button', { name: 'Reply', exact: true }).click();
        await expect(dialog.getByText(answer)).toBeVisible();

        // the customer's answer to that email lands in the same ticket, quoted history removed
        const reply = await receive(page, hd.url, { fixture: 'reply-quoted', reply_to_ticket: created.ticket_id! });
        expect(reply.result).toBe('reply_added');

        await page.goto(hd.url);
        await ticketCard(page, subject).click();
        await expect(dialog.getByText('Thanks, restarting it helped!')).toBeVisible();
        await expect(dialog.getByText('Emailed')).toBeVisible();
        await expect(dialog.getByText('Via email').first()).toBeVisible();

        // the settings list what came in
        await page.goto(`${hd.url}/edit?tab=email`);
        const log = page.getByRole('list', { name: 'Recently received email' });
        await expect(log.getByText(subject)).toBeVisible();
        await expect(log.getByText('New ticket')).toBeVisible();
        await expect(log.getByText('Reply', { exact: true })).toBeVisible();
    });

    test('an auto-reply is ignored; a project editor manages the mailbox too', async ({ page, memberPage }) => {
        const hd = await createHelpdesk(page, uid('Loop desk'));
        await connectMailbox(page, hd.url);

        const ooo = await receive(page, hd.url, { fixture: 'auto-reply', message_id: `${uid('ooo')}@mail.customer.example` });
        expect(ooo.result).toBe('ignored');
        expect(ooo.ticket_id).toBeNull();

        // Max is a project editor on the seeded project: helpdesk editors may manage the mailbox
        await memberPage.goto(`${hd.url}/edit?tab=email`);
        await expect(memberPage.getByRole('heading', { name: 'Connected mailbox' })).toBeVisible();
        await expect(memberPage.getByLabel('IMAP server')).toHaveValue('imap.helpdesk.example');
    });
});
