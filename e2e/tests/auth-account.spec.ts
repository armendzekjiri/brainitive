import type { BrowserContextOptions } from '@playwright/test';
import { expect, test, urls } from '../fixtures';
import { PASSWORD, USERS } from '../env';
import { GUEST, guestContext, newAccount, openUserMenu, register, skipWelcome, submitLogin, type Account } from '../helpers/auth-account';

/*
 * Authentication and the user account (BRAI-96).
 * Seeded users are only read, or signed in with a fresh extra session: their name, email,
 * password and existing sessions stay untouched. Destructive steps run on an account the suite
 * registers itself.
 *
 * Throttles: unnamed `throttle:N,1` middlewares share ONE counter per guest IP, so register
 * (10/min) and forgot-password (6/min) eat from the same budget. Keep guest POSTs to those
 * routes to a minimum: one sign-up for the whole file, two reset requests. Validation errors
 * are exercised on the (unthrottled) profile and password forms instead.
 */

const BAD_CREDENTIALS = 'These credentials do not match our records.';
const WRONG_PASSWORD = 'The password is incorrect.';


test.describe('as a guest', () => {
    test.use({ storageState: GUEST });

    test('app pages send a guest to login, which returns them to the page they wanted; signing out locks the app again', async ({ page }) => {
        for (const path of [urls.home, urls.board(), '/my-work', '/profile']) {
            await page.goto(path);
            await expect(page).toHaveURL(/\/login$/);
        }

        // /profile was asked for last: logging in lands there (a fresh, extra member session)
        await page.getByLabel('Email').fill(USERS.member.email);
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).toHaveURL(/\/profile$/);
        await expect(page.getByLabel('Name')).toHaveValue(USERS.member.name);

        // signing out ends only this session (the saved member session keeps working)
        await openUserMenu(page, USERS.member.name);
        await page.getByRole('menuitem', { name: 'Sign out' }).click();
        await expect(page).toHaveURL(/\/$/);

        await page.goto(urls.board());
        await expect(page).toHaveURL(/\/login$/);
    });

    test('a wrong password shows an error and stays on the login page', async ({ page }) => {
        const email = newAccount('nobody').email;
        await submitLogin(page, email, 'definitely-not-the-password');

        await expect(page.getByText(BAD_CREDENTIALS)).toBeVisible();
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByLabel('Email')).toHaveValue(email);
        await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
    });

    test('forgot password gives the same answer for known and unknown addresses', async ({ page }) => {
        const confirmation = 'If an account exists for that address, we sent a link to reset the password.';

        await page.goto('/login');
        await page.getByRole('link', { name: 'Forgot your password?' }).click();
        await expect(page).toHaveURL(/\/forgot-password$/);
        // labelled (sr-only) since BRAI-96
        // the email field has no label; it is the only textbox on the page
        const email = page.getByLabel('Email');
        await email.fill(newAccount('unknown').email);
        await page.getByRole('button', { name: 'Email Password Reset Link' }).click();
        await expect(page.getByText(confirmation)).toBeVisible();

        // a reset link only stores a token (and logs a mail): the member's password is unchanged
        await page.reload();
        await expect(page.getByText(confirmation)).toBeHidden();
        await email.fill(USERS.member.email);
        await page.getByRole('button', { name: 'Email Password Reset Link' }).click();
        await expect(page.getByText(confirmation)).toBeVisible();
        await expect(page).toHaveURL(/\/forgot-password$/);
    });

    test('the theme toggle on the guest pages switches and remembers dark mode', async ({ page }) => {
        await page.emulateMedia({ colorScheme: 'light' });
        await page.goto('/login');
        const html = page.locator('html');
        await expect(html).not.toHaveClass(/\bdark\b/);

        await page.getByTitle('Switch to dark mode').click();
        await expect(html).toHaveClass(/\bdark\b/);

        await page.goto('/register');
        await expect(html).toHaveClass(/\bdark\b/);
        await page.getByTitle('Switch to light mode').click();
        await expect(html).not.toHaveClass(/\bdark\b/);
        await expect(page.getByTitle('Switch to dark mode')).toBeVisible();
    });
});

test.describe('signed in as the owner', () => {
    test('the appearance menu sets dark, light and system themes', async ({ page }) => {
        await page.emulateMedia({ colorScheme: 'light' });
        await page.goto(urls.home);
        const html = page.locator('html');

        await openUserMenu(page, USERS.owner.name);
        await page.getByRole('menuitem', { name: 'Dark' }).click();
        await expect(html).toHaveClass(/\bdark\b/);

        // remembered by this browser (local storage) across reloads
        await page.reload();
        await expect(html).toHaveClass(/\bdark\b/);

        await openUserMenu(page, USERS.owner.name);
        await page.getByRole('menuitem', { name: 'Light' }).click();
        await expect(html).not.toHaveClass(/\bdark\b/);

        // System follows the OS setting
        await page.getByRole('menuitem', { name: 'System' }).click();
        await page.emulateMedia({ colorScheme: 'dark' });
        await expect(html).toHaveClass(/\bdark\b/);
        await page.emulateMedia({ colorScheme: 'light' });
        await expect(html).not.toHaveClass(/\bdark\b/);
    });

    test('profile settings open from the account menu with the account details', async ({ page }) => {
        await page.goto(urls.home);
        await openUserMenu(page, USERS.owner.name);
        await page.getByRole('menuitem', { name: 'Profile settings' }).click();

        await expect(page).toHaveURL(/\/profile$/);
        await expect(page.getByRole('heading', { name: 'Profile', exact: true })).toBeVisible();
        await expect(page.getByLabel('Name')).toHaveValue(USERS.owner.name);
        await expect(page.getByLabel('Email')).toHaveValue(USERS.owner.email);
        // verified: no re-send prompt
        await expect(page.getByText('Your email address is unverified.')).toHaveCount(0);
        for (const title of ['Profile information', 'Notifications', 'Update password', 'Connected apps', 'Delete account']) {
            await expect(page.getByText(title, { exact: true }).first()).toBeVisible();
        }
    });
});

/*
 * One account, signed up through the form, walked through its life: unverified sign-up, profile
 * edits, password change, deletion. Serial: each step builds on the previous one.
 */
test.describe('a new account', () => {
    test.describe.configure({ mode: 'serial' });

    let account: Account;
    let session: BrowserContextOptions['storageState'];

    test('signing up lands on verify-email and the app stays locked until the address is verified', async ({ browser }) => {
        const context = await guestContext(browser);
        const page = await context.newPage();
        account = await register(page, newAccount('signup'));

        for (const path of [urls.home, urls.board(), '/my-work']) {
            await page.goto(path);
            await expect(page).toHaveURL(/\/verify-email$/);
        }

        await page.getByRole('button', { name: 'Resend verification email' }).click();
        await expect(page.getByText('A new verification link has been sent to your email address.')).toBeVisible();

        session = await context.storageState();
        await context.close();
    });

    test('the profile can be edited before verification, with validation', async ({ browser }) => {
        const context = await guestContext(browser, session);
        const page = await context.newPage();
        await page.goto('/profile');
        await skipWelcome(page);

        await expect(page.getByText('Your email address is unverified.')).toBeVisible();
        await expect(page.getByText('No apps are connected to your account.')).toBeVisible();
        await expect(page.getByLabel('Name')).toHaveValue(account.name);

        // somebody else's address is refused
        await page.getByLabel('Email').fill(USERS.owner.email);
        await page.getByRole('button', { name: 'Save', exact: true }).first().click();
        await expect(page.getByText('The email has already been taken.')).toBeVisible();
        await page.getByLabel('Email').fill(account.email);

        const name = `${account.name} renamed`;
        const bio = 'Writes e2e tests for a living.';
        await page.getByLabel('Name').fill(name);
        await page.getByLabel('Bio (optional)').fill(bio);
        await page.getByRole('button', { name: 'Save', exact: true }).first().click();
        await expect(page.getByText('Saved.').first()).toBeVisible();
        await expect(page.getByText('The email has already been taken.')).toBeHidden();
        account = { ...account, name };

        await page.reload();
        await expect(page.getByLabel('Name')).toHaveValue(name);
        await expect(page.getByLabel('Email')).toHaveValue(account.email);
        await expect(page.getByLabel('Bio (optional)')).toHaveValue(bio);
        // the sidebar's account button shows the new name
        await expect(page.getByRole('button', { name: new RegExp(name) })).toBeVisible();

        // morning task summary: off, and back on
        await page.getByRole('button', { name: 'Turn off' }).click();
        await expect(page.getByText('Morning task summary turned off')).toBeVisible();
        await page.reload();
        await page.getByRole('button', { name: 'Turn on' }).click();
        await expect(page.getByText('Morning task summary turned on')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Turn off' })).toBeVisible();

        await context.close();
    });

    test('changing the password signs out other browsers and retires the old password', async ({ browser }) => {
        const context = await guestContext(browser, session);
        const page = await context.newPage();
        const oldPassword = account.password;
        const newPassword = `${oldPassword}-new`;

        // a second browser signed in to the same account
        const other = await guestContext(browser);
        const otherPage = await other.newPage();
        await submitLogin(otherPage, account.email, account.password);
        await expect(otherPage).toHaveURL(/\/verify-email$/);

        await page.goto('/profile');
        const current = page.getByLabel('Current Password');
        const fresh = page.getByLabel('New Password');
        const confirm = page.getByLabel('Confirm Password');
        const save = page.getByRole('button', { name: 'Save', exact: true }).last();

        await current.fill(account.password);
        await fresh.fill(newPassword);
        await confirm.fill(`${newPassword}-typo`);
        await save.click();
        await expect(page.getByText(/password field confirmation does not match/i)).toBeVisible();

        await current.fill('not-my-password');
        await fresh.fill(newPassword);
        await confirm.fill(newPassword);
        await save.click();
        await expect(page.getByText(WRONG_PASSWORD)).toBeVisible();

        await current.fill(account.password);
        await fresh.fill(newPassword);
        await confirm.fill(newPassword);
        await save.click();
        await expect(page.getByText('Saved.').first()).toBeVisible();
        await expect(current).toHaveValue('');
        account = { ...account, password: newPassword };

        // this browser stays signed in, the other one is out
        await page.reload();
        await expect(page).toHaveURL(/\/profile$/);
        await otherPage.goto('/profile');
        await expect(otherPage).toHaveURL(/\/login$/);

        // the old password no longer works, the new one does
        await submitLogin(otherPage, account.email, oldPassword);
        await expect(otherPage.getByText(BAD_CREDENTIALS)).toBeVisible();
        await submitLogin(otherPage, account.email, newPassword);
        // back to the page it was bounced from (/profile is open to unverified accounts)
        await expect(otherPage).toHaveURL(/\/profile$/);
        await expect(otherPage.getByLabel('Name')).toHaveValue(account.name);

        await other.close();
        await context.close();
    });

    test('deleting the account needs the password, signs out and removes the login', async ({ browser }) => {
        const context = await guestContext(browser, session);
        const page = await context.newPage();
        await page.goto('/profile');

        await page.getByRole('button', { name: 'Delete Account' }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText('Are you sure you want to delete your account?')).toBeVisible();

        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden();
        await page.getByRole('button', { name: 'Delete Account' }).click();

        await dialog.getByLabel('Password').fill('not-my-password');
        await dialog.getByRole('button', { name: 'Delete Account' }).click();
        await expect(dialog.getByText(WRONG_PASSWORD)).toBeVisible();

        await dialog.getByLabel('Password').fill(account.password);
        await dialog.getByRole('button', { name: 'Delete Account' }).click();
        await expect(page).toHaveURL(/\/$/);

        await page.goto('/profile');
        await expect(page).toHaveURL(/\/login$/);
        await submitLogin(page, account.email, account.password);
        await expect(page.getByText(BAD_CREDENTIALS)).toBeVisible();

        await context.close();
    });
});
