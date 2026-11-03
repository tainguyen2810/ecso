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
    await page.getByTestId('report-group-sales').getByRole('link', { name: 'Doanh thu & lợi nhuận theo ngày' }).click();
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

test('report centre: every group opens its detail reports on the same period', async ({ page }) => {
    const dir = 'docs/screenshots/reports-centre';
    const snap = async (name: string) => {
        await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
        await baseShot(page, name, dir);
    };
    await login(page, 'owner@demo.test');
    await page.goto('/reports');
    for (const group of ['sales', 'returns', 'customers', 'finance', 'purchases', 'stock']) {
        await expect(page.getByTestId(`report-group-${group}`)).toBeVisible();
    }
    await page.getByRole('button', { name: '30 ngày' }).click();
    await expect(page).toHaveURL(/from=/);
    await snap('01-report-centre');

    // The period chosen on the centre carries over to the detail report.
    const from = new URL(page.url()).searchParams.get('from');
    await page.getByTestId('report-group-sales').getByRole('link', { name: 'Theo sản phẩm' }).click();
    await expect(page).toHaveURL(new RegExp(`from=${from}`));
    await expect(page.getByTestId('report-table')).toBeVisible();
    await snap('02-sales-by-product');
    await page.getByPlaceholder('Tìm SKU, tên sản phẩm').fill('ATC');
    await expect(page.getByTestId('report-table').locator('tbody tr').first()).toContainText('ATC');

    await page.getByTestId('back-to-reports').click();
    await page.getByRole('link', { name: 'Báo cáo lãi lỗ' }).click();
    await expect(page.getByTestId('pl-profit')).toBeVisible();
    await snap('03-profit-loss');

    await page.goto('/reports/sales?by=customer');
    await expect(page.getByTestId('sales-table')).toBeVisible();
    await snap('04-sales-by-customer');

    for (const [report, name, query] of [
        ['stock-on-hand', '05-stock-on-hand', ''],
        ['low-stock', '06-low-stock', ''],
        ['over-stock', '06b-over-stock', ''],
        ['purchases', '07-purchases-by-supplier', '?by=supplier'],
        ['payables', '08-supplier-debts', ''],
        ['payments', '09-payments-by-method', '?by=method'],
        ['customers', '10-customers', ''],
        ['returns', '11-returns', '?by=product'],
    ] as const) {
        await page.goto(`/reports/view/${report}${query}`);
        await expect(page.getByTestId('export-csv')).toBeVisible();
        await expect(page.locator('.animate-pulse')).toHaveCount(0);
        await snap(name);
    }
    await expect(page.getByTestId('tab-product')).toHaveAttribute('aria-selected', 'true');
});
