import { expect, type Browser, type BrowserContext, type BrowserContextOptions, type Page } from '@playwright/test';
import { cacheBuildAssets } from '../assets';
import { uid } from '../fixtures';

/** No cookies, no local storage: a visitor who never signed in. */
export const GUEST = { cookies: [], origins: [] };

export type Account = { name: string; email: string; password: string };

/** A fresh, unique account (not yet in the database). */
export function newAccount(prefix = 'acct'): Account {
    const id = uid(prefix);
    return { name: `E2E ${id}`, email: `${id}@example.test`, password: `Pw-${id}-x9` };
}

/** A new browser context (by default without any session), with cached build assets. */
export async function guestContext(browser: Browser, storageState: BrowserContextOptions['storageState'] = GUEST): Promise<BrowserContext> {
    const context = await browser.newContext({ storageState });
    await cacheBuildAssets(context);
    return context;
}

/**
 * Sign up through the register form. New accounts are unverified, so the app parks them on the
 * verify-email page (the instance requires verification).
 */
export async function register(page: Page, account: Account = newAccount()): Promise<Account> {
    await page.goto('/register');
    await page.getByLabel('Name').fill(account.name);
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByLabel('Confirm Password').fill(account.password);
    await page.getByRole('button', { name: 'Register' }).click();
    await expect(page).toHaveURL(/\/verify-email$/);
    await expect(page.getByRole('heading', { name: 'Verify your email address' })).toBeVisible();
    return account;
}

/** Fill and submit the login form (no assertion on where it lands). */
export async function submitLogin(page: Page, email: string, password: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
}

/** Open the account menu in the sidebar footer (the button shows the user's name). */
export async function openUserMenu(page: Page, name: string): Promise<void> {
    await page.getByRole('button', { name: new RegExp(name) }).last().click();
    await expect(page.getByRole('menu')).toBeVisible();
}

/** New accounts get the onboarding welcome dialog on their first app page: skip it (persists). */
export async function skipWelcome(page: Page): Promise<void> {
    const welcome = page.getByRole('dialog', { name: /Welcome to Brainitive/ });
    await expect(welcome).toBeVisible();
    await welcome.getByRole('button', { name: 'Skip' }).click();
    await expect(welcome).toBeHidden();
}
