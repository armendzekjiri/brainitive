import { expect, test, uid, urls } from '../fixtures';
import { createBlankProject, createWorkspace, openSettingsTab, projectKey } from '../helpers/workspaces-projects';
import { card, createCard } from '../helpers/boards';
import { SEED, USERS } from '../env';

/**
 * Workspaces, projects, members and permissions (BRAI-96).
 * Every journey creates its own workspace / project (uid names); the seeded workspace, project,
 * board and members are only read.
 */

test.describe('workspaces', () => {
    test('create a workspace: lands on it and it shows up in the workspace switcher', async ({ page }) => {
        const workspace = await createWorkspace(page);
        await expect(page).toHaveURL(urls.workspace(workspace.slug));

        // the sidebar account menu lists every workspace and switches between them
        await page.getByRole('button', { name: new RegExp(USERS.owner.name) }).click();
        await expect(page.getByRole('menuitem', { name: workspace.name })).toBeVisible();
        await page.getByRole('menuitem', { name: 'E2E Workspace' }).click();
        await expect(page).toHaveURL(urls.workspace());
        await expect(page.getByRole('heading', { level: 1, name: 'E2E Workspace' })).toBeVisible();

        await page.getByRole('button', { name: new RegExp(USERS.owner.name) }).click();
        await page.getByRole('menuitem', { name: workspace.name }).click();
        await expect(page).toHaveURL(urls.workspace(workspace.slug));

        // and the "All workspaces" index has a card for it
        await page.goto(urls.home);
        await expect(page.getByRole('heading', { name: workspace.name })).toBeVisible();
    });

    test('a second workspace with the same name is rejected', async ({ page }) => {
        const workspace = await createWorkspace(page);
        await page.goto('/workspaces/create');
        await page.getByLabel('Workspace Name').fill(workspace.name);
        await page.getByRole('button', { name: 'Create Workspace' }).click();
        await expect(page.getByText('The name has already been taken.')).toBeVisible();
        await expect(page).toHaveURL(/\/workspaces\/create$/);
    });

    test('workspace settings: save a description', async ({ page }) => {
        const workspace = await createWorkspace(page);
        await page.goto(`${urls.workspace(workspace.slug)}/edit`);
        await expect(page.getByRole('heading', { level: 1, name: workspace.name })).toBeVisible();

        const description = `About ${uid('desc')}`;
        await page.getByLabel('Description').fill(description);
        // (no assertion on the success toast: it is shown only sometimes, see the report)
        const saved = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(`/workspaces/${workspace.slug}`));
        await page.getByRole('button', { name: 'Save Changes' }).first().click();
        expect((await saved).ok()).toBe(true);

        await page.reload();
        await expect(page.getByLabel('Description')).toHaveValue(description);
    });

    test('workspace settings: any timezone in the world can be searched and saved', async ({ page }) => {
        const workspace = await createWorkspace(page);
        await page.goto(`${urls.workspace(workspace.slug)}/edit`);

        // the picker used to stop after the first 100 zones (somewhere in the Americas)
        await page.getByRole('combobox', { name: 'Timezone' }).click();
        await page.getByPlaceholder('Search city or region…').fill('auckland');
        await page.getByRole('option', { name: /Pacific\/Auckland/ }).click();
        await expect(page.getByRole('combobox', { name: 'Timezone' })).toHaveText('Pacific/Auckland');

        const saved = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(`/workspaces/${workspace.slug}`));
        await page.getByRole('button', { name: 'Save Changes' }).first().click();
        expect((await saved).ok()).toBe(true);

        await page.reload();
        await expect(page.getByRole('combobox', { name: 'Timezone' })).toHaveText('Pacific/Auckland');
    });
});

test.describe('workspace members', () => {
    test('invite an unknown email: confirm, the invitation is pending, then cancel it', async ({ page }) => {
        const workspace = await createWorkspace(page);
        await page.goto(`${urls.workspace(workspace.slug)}/edit`);
        await openSettingsTab(page, /Members/);

        const email = `${uid('invitee')}@example.test`;
        await page.getByRole('button', { name: 'Invite Members' }).first().click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Email Address').fill(email);
        await dialog.getByRole('combobox').click();
        await page.getByRole('option', { name: /Editor/ }).click();
        await dialog.getByRole('button', { name: 'Send Invite' }).click();

        // not a user yet: the app asks before sending a sign-up invitation (mail goes to the log)
        await expect(dialog.getByRole('heading', { name: 'Send invitation?' })).toBeVisible();
        await dialog.getByRole('button', { name: 'Send invitation' }).click();
        await expect(dialog).toBeHidden();

        await openSettingsTab(page, /Members/);
        await expect(page.getByText('Pending invitations')).toBeVisible();
        await expect(page.getByText(email)).toBeVisible();

        await page.getByRole('button', { name: 'Cancel invite' }).click();
        const cancelled = page.waitForResponse((r) => r.request().method() === 'DELETE' && r.url().includes('/invitations/'));
        await page.getByRole('alertdialog').getByRole('button', { name: 'Cancel invitation' }).click();
        await cancelled;
        await openSettingsTab(page, /Members/);
        await expect(page.getByText(email)).toHaveCount(0);
        await expect(page.getByText('Pending invitations')).toHaveCount(0);
    });

    test('add an existing user, change their role, then remove them', async ({ page }) => {
        const workspace = await createWorkspace(page);
        await page.goto(`${urls.workspace(workspace.slug)}/edit`);
        await openSettingsTab(page, /Members/);

        await page.getByRole('button', { name: 'Invite Members' }).first().click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Email Address').fill(USERS.member.email);
        await dialog.getByRole('combobox').click();
        await page.getByRole('option', { name: /Viewer/ }).click();
        await dialog.getByRole('button', { name: 'Send Invite' }).click();
        await expect(dialog).toBeHidden();

        await openSettingsTab(page, /Members/);
        const row = page.locator('div.rounded-lg').filter({ hasText: USERS.member.email }).filter({ has: page.getByRole('combobox') }).last();
        await expect(row.getByRole('combobox')).toHaveText('Viewer');
        await row.getByRole('combobox').click();
        const saved = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().includes('/members/'));
        await page.getByRole('option', { name: 'Editor' }).click();
        await saved;

        await page.reload();
        await openSettingsTab(page, /Members/);
        await expect(row.getByRole('combobox')).toHaveText('Editor');

        // clean up: the seeded member leaves the throwaway workspace again
        await row.getByRole('button').click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Remove' }).click();
        await expect(page.getByRole('tab', { name: /Members/ })).toHaveText(/Members\s*1$/);
        await openSettingsTab(page, /Members/);
        await expect(page.getByText(USERS.member.email)).toHaveCount(0);
    });
});

test.describe('projects', () => {
    test('the first issue of a new project is KEY-1', async ({ page }) => {
        // regression BRAI-97: blank projects used to start at KEY-2
        const workspace = await createWorkspace(page);
        const project = await createBlankProject(page, workspace.slug);
        const title = uid('first');
        await createCard(page, title);
        await expect(card(page, title).getByText(`${project.key}-1`, { exact: true })).toBeVisible();
    });

    test('create a blank project from the "New project" popup: lands on its Main Board', async ({ page }) => {
        const workspace = await createWorkspace(page);
        const name = uid('Blank');
        const key = projectKey();

        await page.getByRole('button', { name: 'New project' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'Create project' });
        await dialog.getByRole('button', { name: /Blank project/ }).click();
        await dialog.getByLabel('Project name').fill(name);
        await dialog.getByLabel('Project key').fill(key);
        await dialog.getByRole('button', { name: 'Create project', exact: true }).click();

        await expect(page).toHaveURL(new RegExp(`/workspaces/${workspace.slug}/projects/[^/]+/boards/main-board$`));
        for (const column of ['To Do', 'In Progress', 'Done']) {
            await expect(page.getByText(column, { exact: true }).first()).toBeVisible();
        }

        // the project is listed on its workspace page
        await page.goto(urls.workspace(workspace.slug));
        await expect(page.getByRole('link', { name: new RegExp(name) })).toBeVisible();
    });

    test('create a project from a template: the template content is there', async ({ page }) => {
        const workspace = await createWorkspace(page);
        const name = uid('Personal');

        await page.goto(`${urls.workspace(workspace.slug)}/projects/create`);
        await page.getByLabel('Search templates').fill('Personal tasks');
        await page.getByRole('button', { name: /Personal tasks/ }).click();
        await expect(page.getByRole('heading', { name: 'Personal tasks' })).toBeVisible();
        await page.getByRole('button', { name: 'Use template' }).click();

        await page.getByLabel('Project name').fill(name);
        await page.getByLabel('Project key').fill(projectKey());
        await page.getByRole('button', { name: 'Create project', exact: true }).click();

        // a template project lands on the project overview with its board, doc and checklist
        // (instantiating the template is the slowest request of this file)
        await expect(page).toHaveURL(new RegExp(`/workspaces/${workspace.slug}/projects/[^/]+$`), { timeout: 30_000 });
        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
        await expect(page.getByText('My tasks').first()).toBeVisible();
        await expect(page.getByText('How this board works').first()).toBeVisible();
        await expect(page.getByText('Weekly review').first()).toBeVisible();

        await page.locator('a[href*="/boards/"]').filter({ hasText: 'My tasks' }).first().click();
        await expect(page).toHaveURL(/\/boards\/[^/]+$/);
        for (const column of ['Inbox', 'Next', 'Waiting for', 'Someday']) {
            await expect(page.getByText(column, { exact: true }).first()).toBeVisible();
        }
        await expect(page.getByText('Plan the week')).toBeVisible();
        await expect(page.getByText('Book a dentist appointment')).toBeVisible();
    });

    test('rename a project and change its key in the settings', async ({ page }) => {
        const workspace = await createWorkspace(page);
        const project = await createBlankProject(page, workspace.slug);
        const renamed = uid('Renamed');
        const key = projectKey();

        await page.goto(`${urls.project(project.slug, workspace.slug)}/edit`);
        await expect(page.getByRole('heading', { name: 'Project Settings' })).toBeVisible();
        await page.getByLabel('Project name').fill(renamed);
        await page.getByLabel('Project key').fill(key);
        await page.getByRole('button', { name: 'Save Changes' }).click();

        await expect(page).toHaveURL(urls.project(project.slug, workspace.slug));
        await expect(page.getByRole('heading', { level: 1, name: renamed })).toBeVisible();
        await expect(page.getByText(key, { exact: true })).toBeVisible();
    });

    // Regression (bug found by this suite, fixed in BRAI-96): ProjectController::storeLabel answers every request with X-Requested-With: XMLHttpRequest
    // with JSON (201) — Inertia sends that header too, so the settings form gets a non-Inertia
    // response: the "Create Label" dialog stays open and the new label only shows after a reload.
    // Steps: Project settings → Labels → Add Label → name → Create Label.
    // Evidence: POST /workspaces/{w}/projects/{p}/labels → 201 application/json. (updateLabel likely the same.)
    test('add a label in the project settings', async ({ page }) => {
        const workspace = await createWorkspace(page);
        const project = await createBlankProject(page, workspace.slug);
        const label = uid('label').slice(0, 40);

        await page.goto(`${urls.project(project.slug, workspace.slug)}/edit`);
        await openSettingsTab(page, 'Labels');
        await page.getByRole('button', { name: 'Add Label' }).click();
        const dialog = page.getByRole('dialog', { name: 'Create Label' });
        await dialog.getByLabel('Label Name').fill(label);
        await dialog.getByRole('button', { name: 'Create Label' }).click();
        await expect(dialog).toBeHidden();

        await expect(page.getByText(label, { exact: true })).toBeVisible();
        await page.reload();
        await openSettingsTab(page, 'Labels');
        await expect(page.getByText(label, { exact: true })).toBeVisible();
    });

    test('delete a project from the danger zone', async ({ page }) => {
        const workspace = await createWorkspace(page);
        const project = await createBlankProject(page, workspace.slug);

        await page.goto(`${urls.project(project.slug, workspace.slug)}/edit`);
        await openSettingsTab(page, /Danger/);
        await page.getByRole('button', { name: 'Delete Project' }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Delete Project' }).click();

        await expect(page).toHaveURL(urls.workspace(workspace.slug));
        await expect(page.getByRole('link', { name: new RegExp(project.name) })).toHaveCount(0);

        const response = await page.goto(urls.project(project.slug, workspace.slug));
        expect(response?.status()).toBe(404);
        await expect(page.getByText('Page not found')).toBeVisible();
    });
});

test.describe('permissions', () => {
    test('the seeded member sees the board but not the admin settings', async ({ memberPage }) => {
        // workspace "member": no settings / invite / new-project controls, settings URL is a 403
        await memberPage.goto(urls.workspace());
        await expect(memberPage.getByRole('heading', { level: 1, name: 'E2E Workspace' })).toBeVisible();
        await expect(memberPage.getByRole('button', { name: 'New project' })).toHaveCount(0);
        await expect(memberPage.getByRole('button', { name: 'Invite' })).toHaveCount(0);
        await expect(memberPage.locator(`a[href$="/workspaces/${SEED.workspace}/edit"]`)).toHaveCount(0);

        let response = await memberPage.goto(`${urls.workspace()}/edit`);
        expect(response?.status()).toBe(403);
        await expect(memberPage.getByText('No access')).toBeVisible();

        response = await memberPage.goto(`${urls.workspace()}/projects/create`);
        expect(response?.status()).toBe(403);

        // project "editor": can open the project and its board, not its settings
        response = await memberPage.goto(`${urls.project()}/edit`);
        expect(response?.status()).toBe(403);
        await expect(memberPage.getByText('No access')).toBeVisible();

        await memberPage.goto(urls.board());
        await expect(memberPage.getByText('Homepage hero')).toBeVisible();
        await expect(memberPage.getByText('Pricing page copy')).toBeVisible();
    });

    // Regression (bug found by this suite, fixed in BRAI-96): links to admin-only settings are shown to users who get "No access" when they click them:
    // the gear on the project page (Projects/Show.tsx has no canUpdate check around it) and
    // "Workspace settings" in the account menu (AppLayout shows it for every role).
    // Steps: as Max Member (project editor, workspace member) open /workspaces/e2e-workspace/projects/website
    // → the gear links to …/website/edit → 403 "No access".
    test('admin-only settings links are hidden from the member', async ({ memberPage }) => {
        await memberPage.goto(urls.project());
        await expect(memberPage.getByRole('heading', { level: 1, name: 'Website' })).toBeVisible();
        await expect(memberPage.locator(`a[href$="/projects/${SEED.project}/edit"]`)).toHaveCount(0);

        await memberPage.getByRole('button', { name: new RegExp(USERS.member.name) }).click();
        await expect(memberPage.getByRole('menuitem', { name: 'Workspace settings' })).toHaveCount(0);
    });

    test('a user outside a workspace gets no access to it', async ({ page, memberPage }) => {
        const workspace = await createWorkspace(page);
        const project = await createBlankProject(page, workspace.slug);

        let response = await memberPage.goto(urls.workspace(workspace.slug));
        expect(response?.status()).toBe(403);
        await expect(memberPage.getByText('No access')).toBeVisible();

        response = await memberPage.goto(urls.board('main-board', project.slug, workspace.slug));
        expect(response?.status()).toBe(403);
    });
});
