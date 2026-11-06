import { expect, test, uid } from '../fixtures';
import { createBoard, createCard, openCard } from '../helpers/boards';

/**
 * The parent issue's subtask list: the circle in front of a subtask ticks it done (moves it to the
 * board's done column) and opens it again; the title still opens the subtask.
 */
test('tick a subtask done from its parent and open it again', async ({ page }) => {
    const boardUrl = await createBoard(page, uid('Subtask board'));
    await page.goto(boardUrl);
    const parent = uid('Parent');
    await createCard(page, parent);
    const dialog = await openCard(page, parent);

    const child = uid('Child');
    await dialog.getByPlaceholder(/^Add a? ?subtask/).fill(child);
    await dialog.getByPlaceholder(/^Add a? ?subtask/).press('Enter');
    const row = dialog.getByTestId('subtask-row').filter({ hasText: child });
    await expect(row).toBeVisible();

    const box = row.getByRole('checkbox');
    await expect(box).toHaveAttribute('aria-checked', 'false');
    await box.click();
    await expect(box).toHaveAttribute('aria-checked', 'true');
    await expect(dialog.getByText('1/1')).toBeVisible();

    await box.click();
    await expect(box).toHaveAttribute('aria-checked', 'false');
    await expect(dialog.getByText('0/1')).toBeVisible();
});
