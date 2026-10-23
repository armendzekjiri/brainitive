import { expect, test as setup } from '@playwright/test';
import { PASSWORD, USERS, authFile, type Role } from '../env';

/** Log the seeded users in once; specs reuse the saved session (storageState). */
for (const role of Object.keys(USERS) as Role[]) {
    setup(`log in as ${role}`, async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email').fill(USERS[role].email);
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).toHaveURL(/\/workspaces/);
        await page.context().storageState({ path: authFile(role) });
    });
}
