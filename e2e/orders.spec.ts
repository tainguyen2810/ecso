import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-3';
const shot = (page: Page, name: string) => baseShot(page, name, DIR);

test.describe.configure({ mode: 'serial' });

test('marketplaces: capability matrix, fake shop simulator, webhook monitor', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.getByRole('link', { name: 'Kết nối sàn' }).first().click();
    await expect(page.getByTestId('account-list')).toContainText('Shop Demo');
    await expect(page.getByTestId('capability-matrix')).toContainText('UNKNOWN');
    await shot(page, '01-marketplaces');

    // The simulator plays the marketplace: new orders arrive through signed webhooks.
    await page.getByTestId('sim-count').fill('2');
    await page.getByTestId('sim-create').click();
    await expect(page.getByText(/Sàn giả lập đã tạo 2 đơn/)).toBeVisible();
    await expect(page.getByTestId('webhook-table')).toContainText('processed');
});

test('listings: SKU mapping with exact-SKU suggestions only', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/marketplaces');
    await page.getByRole('link', { name: /Liên kết SKU/ }).first().click();
    await expect(page.getByTestId('listing-table')).toBeVisible();
    await page.getByRole('button', { name: /Chưa map/ }).click();
    await expect(page.getByTestId('listing-table')).toContainText('Chưa map');
    await shot(page, '02-listing-mapping');

    await page.getByRole('button', { name: 'Chọn SKU' }).first().click();
    await expect(page.getByRole('dialog')).toContainText('Chọn SKU nội bộ');
    await page.getByRole('dialog').getByPlaceholder(/Tìm SKU/).fill('ATC');
    await expect(page.getByRole('dialog').getByRole('button', { name: /ATC/ }).first()).toBeVisible();
    await shot(page, '03-sku-picker');
    await page.keyboard.press('Escape');
});

test('orders: tabs, filters, bulk actions and packing slips', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.getByRole('link', { name: 'Đơn hàng' }).first().click();
    await expect(page.getByTestId('order-table')).toBeVisible();
    await shot(page, '04-orders');

    await page.getByTestId('order-tabs').getByRole('button', { name: /Chờ đóng gói/ }).click();
    await expect(page.getByTestId('order-table').locator('tbody tr').first()).toContainText('Chờ đóng gói');
    await page.getByTestId('flag-overdue').click();
    await shot(page, '05-orders-filtered');
    await page.getByTestId('flag-overdue').click();
    await page.waitForLoadState('networkidle');

    await page.getByLabel('Chọn tất cả').check();
    await expect(page.getByTestId('bulk-bar')).toBeVisible();
    const download = page.waitForEvent('download');
    await page.getByTestId('print-slips').click();
    expect((await download).suggestedFilename()).toMatch(/^phieu-dong-goi-.*\.pdf$/);
    await shot(page, '06-orders-bulk');

    await page.getByTestId('bulk-bar').getByRole('button', { name: 'Đóng gói', exact: true }).click();
    await expect(page.getByText(/Đã xử lý \d+ đơn|Kết quả xử lý hàng loạt/)).toBeVisible();
});

test('order detail: items, reservations, history, actions and notes', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/orders?flags=shortage');
    await page.getByTestId('order-table').locator('tbody tr', { hasText: /Chờ (đóng gói|bàn giao)/ }).first().click();
    await expect(page.getByTestId('order-items')).toContainText('thiếu');
    await expect(page.getByTestId('order-history')).toBeVisible();
    const note = `Gọi khách xác nhận màu trước khi gửi (${Date.now().toString().slice(-4)})`;
    await page.getByTestId('note-input').fill(note);
    await page.getByRole('button', { name: 'Thêm' }).click();
    await expect(page.getByText(note)).toBeVisible();
    await shot(page, '07-order-detail-shortage');

    await page.goto('/orders?tab=to_handover');
    await page.getByTestId('order-table').locator('tbody tr').first().click();
    await page.getByTestId('action-handover').click();
    await expect(page.getByTestId('order-status')).toHaveText('Đã bàn giao');
    await shot(page, '08-order-detail-handed-over');
});

test('returns: receive and inspect restocks sellable units only', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.getByRole('link', { name: 'Trả hàng' }).first().click();
    await expect(page.getByTestId('return-table')).toBeVisible();
    await shot(page, '09-returns');

    await page.getByTestId('return-table').getByText('Đã nhận, chờ kiểm').first().click();
    await expect(page.getByTestId('return-lines')).toBeVisible();
    const sellable = page.getByTestId('return-lines').locator('tbody tr').first().locator('input').nth(1);
    const damaged = page.getByTestId('return-lines').locator('tbody tr').first().locator('input').nth(2);
    const qty = Number(await sellable.inputValue());
    if (qty > 1) {
        await sellable.fill(String(qty - 1));
        await damaged.fill('1');
    }
    await shot(page, '10-return-inspect');
    await page.getByTestId('submit-inspect').click();
    await expect(page.getByTestId('return-status')).toHaveText('Đã kiểm');
});

test('dashboard shows order KPIs and recent orders', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await expect(page.getByTestId('recent-orders')).toBeVisible();
    await shot(page, '11-dashboard');
});
