import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-17';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test.describe.configure({ mode: 'serial' });

test('order list: to-handle strip, quick view, advanced filters, bulk actions, export', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/orders');
    await expect(page.getByTestId('order-table')).toBeVisible();
    await expect(page.getByTestId('pipeline-to_confirm')).not.toContainText('…');
    await shot(page, '01-order-list-pipeline');

    // A tile of the strip filters the list.
    const reloaded = page.waitForResponse((r) => r.url().includes('/api/v1/orders?') && r.url().includes('payment_due'));
    await page.getByTestId('pipeline-payment_due').click();
    await reloaded;
    await expect(page.locator('.animate-pulse')).toHaveCount(0);
    await expect(page.getByTestId('flag-payment_due')).toHaveClass(/border-primary/);
    await expect(page.getByTestId('pipeline-payment_due')).toHaveClass(/ring-primary/);

    // Quick view opens under the row.
    await page.getByTestId('quick-view-toggle').first().click();
    await expect(page.getByTestId('order-quick-view')).toContainText('Khách phải trả', { timeout: 15000 });
    await shot(page, '02-order-quick-view');
    await page.getByTestId('quick-view-toggle').first().click();

    // Advanced filters.
    await page.getByTestId('more-filters').click();
    await expect(page.getByTestId('advanced-filters')).toBeVisible();
    await page.getByLabel('Trạng thái thanh toán').selectOption('unpaid');
    await page.getByTestId('filters-apply').click();
    await expect(page.getByTestId('advanced-filters')).toBeHidden();
    await expect(page.getByTestId('filter-chip')).toHaveText(['Chưa thanh toán']);
    await expect(page.getByRole('button', { name: 'Xóa bộ lọc' })).toBeVisible();
    await shot(page, '03-order-advanced-filters');
    await page.getByRole('button', { name: 'Xóa bộ lọc' }).click();

    // Compact list: one short line per order, remembered after a reload.
    await page.getByTestId('density-compact').click();
    await expect(page.getByTestId('order-table')).toHaveAttribute('data-density', 'compact');
    await page.reload();
    await expect(page.getByTestId('order-table')).toHaveAttribute('data-density', 'compact');
    await page.getByRole('button', { name: 'Đầy đủ' }).click();

    // Bulk bar with quick payment.
    await page.locator('[data-testid="order-table"] tbody input[type="checkbox"]').first().check();
    await expect(page.getByTestId('bulk-bar')).toBeVisible();
    await page.getByTestId('bulk-pay').click();
    await expect(page.getByRole('dialog')).toContainText('Tổng thu');
    await shot(page, '04-order-bulk-quick-pay');
    await page.getByTestId('confirm-bulk-pay').click();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    // Export.
    const download = page.waitForEvent('download');
    await page.getByTestId('export-orders').click();
    expect((await download).suggestedFilename()).toMatch(/^don-hang-.*\.xlsx$/);
});

test('order detail: progress, buyer summary, copy into a new order with shortcuts', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/orders?source=manual');
    await page.locator('[data-testid="order-table"] tbody tr').first().click();
    await expect(page.getByTestId('order-workflow')).toBeVisible();
    await shot(page, '05-order-detail-workflow');

    await page.getByTestId('copy-order').click();
    await expect(page.getByRole('heading', { name: /sao chép từ/ })).toBeVisible();
    await expect(page.getByTestId('order-lines')).toBeVisible();
    await page.keyboard.press('F7');
    await expect(page.getByTestId('shipping-fee')).toBeFocused();
    await page.keyboard.press('F3');
    await expect(page.locator('[data-shortcut="sku"] input')).toBeFocused();
    await shot(page, '06-order-copy-shortcuts');
});

test('returns: goods not received yet', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/returns');
    await page.getByRole('button', { name: 'Chưa nhận hàng' }).click();
    await expect(page.locator('.animate-pulse')).toHaveCount(0);
    await shot(page, '07-returns-not-received');
});
