import { expect, test } from '@playwright/test';
import { login } from './helpers';

// Thanh trên cùng: tìm kiếm chung (Ctrl + K), chuông thông báo, ô chọn ngày dd/mm/yyyy.

test('global search opens with Ctrl+K and jumps to an order', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.keyboard.press('Control+k');
    const search = page.getByTestId('global-search');
    await expect(search).toBeVisible();
    // Without a query: pages and actions to jump to.
    await expect(search.getByTestId('search-row').first()).toBeVisible();

    await page.getByTestId('global-search-input').fill('SO-0');
    await expect(search.getByTestId('search-row').filter({ hasText: 'SO-0' }).first()).toBeVisible();
    await page.getByTestId('global-search-input').press('Enter');
    await expect(search).toBeHidden();
    await expect(page).toHaveURL(/\/orders\/[0-9A-Z]{26}$/);

    // The opened order is now under "Mở gần đây".
    await page.getByTestId('open-search').click();
    await expect(search.getByText('Mở gần đây')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(search).toBeHidden();
});

test('the bell lists what waits and opens the filtered list', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.getByTestId('inbox-bell').click();
    const panel = page.getByTestId('inbox-panel');
    await expect(panel).toBeVisible();
    const first = panel.getByTestId('inbox-item').first();
    if (await first.count()) {
        await first.click();
        await expect(panel).toBeHidden();
        await expect(page).not.toHaveURL(/\/$/);
    } else {
        await expect(panel.getByText('Không có việc nào đang chờ.')).toBeVisible();
    }

    await page.getByTestId('inbox-bell').click();
    await page.getByTestId('inbox-settings').click();
    await expect(panel.getByText('Đơn giao thất bại')).toBeVisible();
});

test('date fields read and type dd/mm/yyyy', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/finance');
    await page.getByTestId('finance-range').click();
    const range = page.getByTestId('range-popover');
    await expect(range).toBeVisible();
    await range.getByRole('button', { name: 'Tháng trước' }).click();
    await expect(range).toBeHidden();
    await expect(page.getByTestId('finance-range')).toHaveText(/\d{2}\/\d{2}\/\d{4} – \d{2}\/\d{2}\/\d{4}/);

    await page.goto('/orders/new');
    const deliver = page.getByTestId('form-deliver-on');
    await deliver.fill('151126');
    await deliver.press('Enter');
    await expect(deliver).toHaveValue('15/11/2026');
});
