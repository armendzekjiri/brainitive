import { expect, type Page } from '@playwright/test';
import { SEED } from '../env';

/** Helpers for the admin insights journeys (tests/insights.spec.ts, BRAI-105). */

export type InsightsTab = 'overview' | 'team' | 'projects' | 'helpdesk' | 'usage';

export const insightsUrl = (tab: InsightsTab) => `/workspaces/${SEED.workspace}/insights/${tab}`;

/** Click a tab of the Insights navigation and wait until it is the current one. */
export async function openTab(page: Page, label: string, tab: InsightsTab): Promise<void> {
    await page.getByRole('navigation', { name: 'Insights' }).getByRole('link', { name: label, exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/insights/${tab}(\\?|$)`));
    await expect(page.getByRole('navigation', { name: 'Insights' }).getByRole('link', { name: label, exact: true })).toHaveAttribute('aria-current', 'page');
}

/** Limit Insights to one project (the shared e2e workspace has many, so numbers are only exact per project). */
export async function pickProject(page: Page, name: string): Promise<void> {
    await page.getByRole('combobox', { name: 'Project' }).click();
    await page.getByRole('option', { name, exact: true }).click();
    await expect(page).toHaveURL(/[?&]project=\d+/);
    await expect(page.getByRole('combobox', { name: 'Project' })).toHaveText(name);
}
