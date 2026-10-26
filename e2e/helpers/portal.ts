import { expect, type Page } from '@playwright/test';
import { uid } from '../fixtures';
import { mailLink } from '../mail';

/** A fresh guest (outside every workspace) for the feedback / client portals (v1.42.0). */
export function newGuest(prefix = 'guest'): { name: string; email: string } {
    const id = uid(prefix);
    return { name: `Guest ${id}`, email: `${id}@example.test` };
}

/**
 * Sign a guest in like a person would: the sign-in form, the emailed link, the confirm button.
 * Ends back on `returnTo` (a /feedback/… or /client/… path).
 * `page` must belong to a context without a Brainitive session (guestContext()).
 */
export async function portalSignIn(page: Page, guest: { name: string; email: string }, returnTo: string): Promise<void> {
    await page.goto(`/guest/login?return_to=${encodeURIComponent(returnTo)}`);
    await page.getByLabel('Email').fill(guest.email);
    await page.getByLabel('Your name').fill(guest.name);
    await page.getByRole('button', { name: 'Email me a sign-in link' }).click();
    await expect(page.getByTestId('portal-login-sent')).toBeVisible();

    await page.goto(await mailLink(guest.email, /\/guest\/login\/[A-Za-z0-9]{48}/));
    await page.getByTestId('portal-login-confirm').click();
    await page.waitForURL((url) => url.pathname === returnTo.split('?')[0]);
}
