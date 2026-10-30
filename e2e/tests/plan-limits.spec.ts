import { expect, test, uid, urls } from '../fixtures';
import { createWorkspace, openSettingsTab } from '../helpers/workspaces-projects';
import { PLAN_LIMITS, REMOTE } from '../env';
import type { Page } from '@playwright/test';

/**
 * Cloud plan limits (v1.46.0). The local e2e app runs the "config" Entitlements driver: Free limits
 * (3 members, 5 projects, enforced) only for workspaces whose slug starts with `plan-limit-`, so
 * the seeded workspace and every other journey stay unlimited like a self-hosted install.
 */
test.skip(REMOTE, 'dev.brainitive.app has no fixed e2e plan limits');

const planWorkspace = (page: Page) => createWorkspace(page, uid(PLAN_LIMITS.slugPrefix.replace(/-$/, '')));

/** Open the workspace settings' Members tab and start an invitation for `email`. */
async function startInvite(page: Page, slug: string, email: string) {
    await page.goto(`${urls.workspace(slug)}/edit`);
    await openSettingsTab(page, /Members/);
    await page.getByRole('button', { name: 'Invite Members' }).first().click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Email Address').fill(email);
    await dialog.getByRole('button', { name: 'Send Invite' }).click();
    return dialog;
}

/** Invite an unknown email (pending invitations count as members). */
async function invite(page: Page, slug: string, email: string) {
    const dialog = await startInvite(page, slug, email);
    await expect(dialog.getByRole('heading', { name: 'Send invitation?' })).toBeVisible();
    await dialog.getByRole('button', { name: 'Send invitation' }).click();
    await expect(dialog).toBeHidden();
}

test('a workspace without plan limits shows no plan UI', async ({ page }) => {
    await page.goto(`${urls.workspace()}/edit`);
    await expect(page.getByRole('tab', { name: /Members/ })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Plan & usage' })).toHaveCount(0);
    await expect(page.getByRole('status', { name: 'Plan usage warning' })).toHaveCount(0);
});

test('a Free workspace shows its plan and usage in the settings', async ({ page }) => {
    const workspace = await planWorkspace(page);
    expect(workspace.slug.startsWith(PLAN_LIMITS.slugPrefix), 'slug gets the plan limits').toBe(true);

    await page.goto(`${urls.workspace(workspace.slug)}/edit`);
    await openSettingsTab(page, 'Plan & usage');
    const panel = page.getByRole('tabpanel');
    await expect(panel.getByText(`This workspace is on the ${PLAN_LIMITS.plan} plan.`)).toBeVisible();

    // the owner alone, no projects yet; content and files have no limit in the e2e config
    await expect(panel.getByTestId('usage-members')).toHaveText(`1 of ${PLAN_LIMITS.members}`);
    await expect(panel.getByRole('progressbar', { name: 'Members' })).toHaveAttribute('aria-valuenow', '33');
    await expect(panel.getByTestId('usage-projects')).toHaveText(`0 of ${PLAN_LIMITS.projects}`);
    await expect(panel.getByRole('progressbar', { name: 'Projects' })).toHaveAttribute('aria-valuenow', '0');
    await expect(panel.getByTestId('usage-content')).toHaveText('0 B · unlimited');
    await expect(panel.getByTestId('usage-files')).toHaveText('0 B · unlimited');
    await expect(panel.getByText(/not measured yet/)).toBeVisible();
    await expect(panel.getByRole('link', { name: 'Plan & billing' })).toHaveAttribute('href', PLAN_LIMITS.billingUrl);

    // enforced in e2e: no "free during early access" note
    await expect(panel.getByText(/not enforced yet/)).toHaveCount(0);
});

test('at 90 % of a limit the admin sees a warning that stays dismissed', async ({ page }) => {
    const workspace = await planWorkspace(page);
    await page.goto(urls.workspace(workspace.slug));
    const banner = page.getByRole('status', { name: 'Plan usage warning' });
    await expect(page.getByRole('heading', { level: 1, name: workspace.name })).toBeVisible();
    await expect(banner).toHaveCount(0);

    // owner + 2 open invitations = 3 of 3 members
    await invite(page, workspace.slug, `${uid('seat')}@example.test`);
    await invite(page, workspace.slug, `${uid('seat')}@example.test`);

    await page.goto(urls.workspace(workspace.slug));
    await expect(banner).toBeVisible();
    await expect(banner).toContainText(`3 of ${PLAN_LIMITS.members} members`);
    await expect(banner.getByRole('link', { name: 'See plans' })).toHaveAttribute('href', PLAN_LIMITS.billingUrl);

    await banner.getByRole('button', { name: 'Dismiss plan usage warning' }).click();
    await expect(banner).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('heading', { level: 1, name: workspace.name })).toBeVisible();
    await expect(banner).toHaveCount(0);

    // unlimited workspaces never warn
    await page.goto(urls.workspace());
    await expect(page.getByRole('heading', { level: 1, name: 'E2E Workspace' })).toBeVisible();
    await expect(banner).toHaveCount(0);
});

// Needs LimitGate::ensureCanAddMembers() in WorkspaceController::inviteMember (feat/cloud-limit-checks).
test('inviting a 4th member opens the upgrade dialog', async ({ page }) => {
    const workspace = await planWorkspace(page);
    await invite(page, workspace.slug, `${uid('seat')}@example.test`);
    await invite(page, workspace.slug, `${uid('seat')}@example.test`);

    const fourth = `${uid('seat')}@example.test`;
    const inviteDialog = await startInvite(page, workspace.slug, fourth);
    const upgrade = page.getByRole('dialog', { name: 'Upgrade to continue' });
    // the gate may stop the first request or the confirmed one
    await expect(upgrade.or(inviteDialog.getByRole('heading', { name: 'Send invitation?' }))).toBeVisible();
    if (!(await upgrade.isVisible())) await inviteDialog.getByRole('button', { name: 'Send invitation' }).click();

    await expect(upgrade).toBeVisible();
    await expect(upgrade).toContainText(`up to ${PLAN_LIMITS.members} members`);
    await expect(upgrade.getByTestId('limit-usage')).toHaveText(`${PLAN_LIMITS.members} of ${PLAN_LIMITS.members} members`);
    await expect(upgrade.getByText(`${PLAN_LIMITS.plan} plan`, { exact: true })).toBeVisible();
    await expect(upgrade.getByRole('link', { name: 'See plans & upgrade' })).toHaveAttribute('href', PLAN_LIMITS.billingUrl);

    await upgrade.getByRole('button', { name: 'Not now' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    // nobody was invited
    await page.reload();
    await openSettingsTab(page, /Members/);
    await expect(page.getByText(fourth)).toHaveCount(0);
});

test('a 402 answer to any request opens the upgrade dialog, also on a phone', async ({ page }) => {
    const workspace = await planWorkspace(page);
    const message = 'The Free plan allows up to 3 members per workspace. Upgrade to invite more people.';
    const limitReached = { kind: 'members', limit: 3, usage: 3, plan: 'Free', workspace: workspace.slug, billingUrl: PLAN_LIMITS.billingUrl, message };
    // the server's LimitReached answer (402 + limit_reached), independent of which endpoint enforces it
    await page.route(`**/workspaces/${workspace.slug}/members`, (route) =>
        route.request().method() === 'POST'
            ? route.fulfill({ status: 402, contentType: 'application/json', json: { message, limit_reached: limitReached } })
            : route.fallback(),
    );

    await startInvite(page, workspace.slug, `${uid('seat')}@example.test`);
    const upgrade = page.getByRole('dialog', { name: 'Upgrade to continue' });
    await expect(upgrade).toBeVisible();
    await expect(upgrade.getByTestId('limit-usage')).toHaveText('3 of 3 members');
    // the invite dialog makes room, and no error toast on top
    await expect(page.getByRole('dialog')).toHaveCount(1);
    await expect(page.getByText('Something went wrong.')).toHaveCount(0);
    await upgrade.getByRole('button', { name: 'Not now' }).click();
    await expect(upgrade).toBeHidden();

    // any other axios request, on a phone: the dialog fits, buttons reachable, no sideways scrolling
    await page.setViewportSize({ width: 375, height: 740 });
    await page.evaluate((url) => (window as unknown as { axios: { post: (u: string, d: object) => Promise<unknown> } }).axios.post(url, {}).catch(() => undefined), `/workspaces/${workspace.slug}/members`);
    await expect(upgrade).toBeVisible();
    const box = await upgrade.boundingBox();
    expect(box && box.x >= 0 && box.x + box.width <= 375, 'dialog inside a 375 px viewport').toBe(true);
    await expect(upgrade.getByRole('link', { name: 'See plans & upgrade' })).toBeInViewport();
    await expect(upgrade.getByRole('button', { name: 'Not now' })).toBeInViewport();
    await upgrade.getByRole('button', { name: 'Not now' }).click();
    await expect(upgrade).toBeHidden();
});
