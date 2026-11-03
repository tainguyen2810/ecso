import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-12';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test.describe.configure({ mode: 'serial' });

test('low-stock alert, stocktake and write-off', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await expect(page.getByTestId('low-stock')).toBeVisible();
    await shot(page, '01-dashboard-low-stock');

    await page.goto('/inventory/stocktake');
    await expect(page.getByTestId('stocktake-table')).toBeVisible();
    for (let i = 0; i < 3; i++) {
        await page.getByTestId('stocktake-scan').fill('ATC-TRANG-M');
        await page.getByTestId('stocktake-scan').press('Enter');
    }
    await expect(page.getByTestId('count-ATC-TRANG-M')).toHaveValue('3');
    // Counted the rest of the shelf by hand: one shirt missing; the other SKU matches the system.
    await page.getByTestId('count-ATC-TRANG-M').fill('66');
    await page.getByTestId('count-ATC-DEN-L').fill('68');
    await expect(page.getByTestId('stocktake-progress')).toContainText('2 /');
    await shot(page, '02-stocktake');
    await page.getByTestId('stocktake-submit').click();
    await expect(page.getByTestId('document-status')).toHaveText('Chờ duyệt');
    await page.getByTestId('approve').click();
    await expect(page.getByTestId('document-status')).toHaveText('Đã duyệt');
    await shot(page, '03-stocktake-approved');

    await page.goto('/inventory/adjustments');
    await page.getByTestId('new-write-off').click();
    await expect(page.getByRole('heading', { name: 'Phiếu xuất hủy' })).toBeVisible();
});

test('reports: sales and profit, stock ledger, cashbook', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.getByRole('link', { name: 'Báo cáo' }).first().click();
    await expect(page.getByTestId('sales-kpis')).toBeVisible();
    await page.getByRole('button', { name: '7 ngày' }).click();
    await expect(page.getByTestId('sales-table').locator('tbody tr')).toHaveCount(7);
    await shot(page, '04-sales-by-day');
    await page.getByTestId('sales-by-channel').click();
    await expect(page.getByTestId('sales-table')).toContainText('Bán tại cửa hàng');
    await shot(page, '05-sales-by-channel');

    await page.getByRole('link', { name: 'Xuất nhập tồn' }).click();
    await page.getByTestId('ledger-detail').check();
    await expect(page.getByTestId('ledger-table')).toContainText('Hủy / mất');
    await shot(page, '06-stock-ledger');

    await page.getByRole('link', { name: 'Sổ quỹ' }).click();
    await expect(page.getByTestId('cashbook-table')).toContainText('Chi trả nhà cung cấp');
    await shot(page, '07-cashbook');
});
