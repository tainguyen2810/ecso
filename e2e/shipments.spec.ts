import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/shipping-s3';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test('shipments: open one on the order, record pickup and a failed delivery, then work it from the shipments page', async ({ page }) => {
    const run = Date.now().toString().slice(-6);
    await login(page, 'owner@demo.test');
    await page.goto('/orders/new');
    await page.getByTestId('sku-picker').fill('ATC-TRANG-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('stage-confirmed').check();
    await page.getByTestId('submit-order').click();
    await expect(page.getByTestId('order-status')).toHaveText('Chờ đóng gói');

    // "Tạo vận đơn": the shop made the parcel on GHN's app and pastes its number.
    await page.getByTestId('new-shipment').click();
    await page.getByTestId('new-shipment-tracking').fill(`GHN${run}S`);
    await page.getByTestId('new-shipment-save').click();
    const card = page.getByTestId('order-shipments');
    await expect(card).toContainText(`GHN${run}S`);
    await expect(card).toContainText('Chờ lấy hàng');

    // The carrier picked it up: the order is handed over (stock leaves) through its own step.
    await card.getByTestId('shipment-action-pick_up').click();
    await expect(page.getByTestId('order-status')).toHaveText('Đã bàn giao');
    await expect(card).toContainText('Đã lấy hàng');

    // From the shipment's history: the carrier reports a failed delivery, with its reason.
    await card.getByTestId('shipment-open').click();
    const drawer = page.getByTestId('shipment-drawer');
    await drawer.getByTestId('shipment-action-fail').click();
    await page.getByTestId('shipment-action-reason').fill('Khách hẹn giao hôm khác');
    await page.getByTestId('shipment-action-confirm').click();
    await expect(drawer.getByTestId('drawer-status')).toHaveText('Giao thất bại');
    await expect(drawer.getByTestId('shipment-timeline')).toContainText('Khách hẹn giao hôm khác');
    await drawer.getByRole('button', { name: 'Đóng' }).click();
    await expect(page.getByTestId('order-status')).toHaveText('Giao thất bại');
    await shot(page, '03-van-don-tren-don-hang');

    // The shipments page: the failed parcel is counted, found by its number, and redelivered from the drawer.
    await page.goto('/shipments?tab=all');
    await expect(page.getByTestId('shipment-kpis')).toContainText('Giao thất bại');
    await page.getByTestId('shipment-search').fill(`GHN${run}S`);
    await expect(page.getByTestId('shipment-table')).toContainText(`GHN${run}S`);
    await page.getByTestId('shipment-search').fill('');
    await expect(page.getByTestId('shipment-table')).toBeVisible();
    await shot(page, '01-danh-sach-van-don');

    await page.getByTestId('shipment-search').fill(`GHN${run}S`);
    await page.getByTestId('shipment-table').getByText(`GHN${run}S`).click();
    await expect(drawer).toContainText('Phiếu trả');
    await shot(page, '02-chi-tiet-van-don');
    await drawer.getByTestId('shipment-action-redeliver').click();
    await expect(drawer.getByTestId('drawer-status')).toHaveText('Giao lại');

    // A typo in the number is corrected from the drawer; the order keeps the same number.
    await drawer.getByTestId('shipment-edit').click();
    await page.getByTestId('shipment-edit-tracking').fill(`GHN${run}T`);
    await page.getByTestId('shipment-edit-save').click();
    await expect(drawer.getByTestId('drawer-tracking')).toHaveText(`GHN${run}T`);
});

test('shipments: tracking numbers made on the carrier site come in from a file', async ({ page }) => {
    const run = Date.now().toString().slice(-6);
    await login(page, 'owner@demo.test');
    await page.goto('/orders/new');
    await page.getByTestId('sku-picker').fill('ATC-TRANG-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('stage-confirmed').check();
    await page.getByTestId('submit-order').click();
    await expect(page.getByTestId('order-status')).toHaveText('Chờ đóng gói');
    const number = ((await page.locator('h1').first().textContent()) ?? '').replace('Đơn', '').trim();

    await page.goto('/shipments?tab=all');
    await page.getByTestId('shipment-import').click();
    await page.getByTestId('shipment-import-file').setInputFiles({
        name: 'van-don.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(`Mã đơn hàng,Mã vận đơn,Đơn vị vận chuyển\n${number},VTP${run}F,Viettel Post\nKHONGCO${run},X${run},GHN\n`),
    });
    const counts = page.getByTestId('shipment-import-counts');
    await expect(counts).toContainText('1 tạo mới');
    await expect(counts).toContainText('1 lỗi');
    await shot(page, '04-nhap-ma-van-don');
    await page.getByTestId('shipment-import-confirm').click();
    // The bad row stays on screen; the good one is saved.
    await expect(page.getByTestId('shipment-import-preview')).toContainText('Không tìm thấy đơn hàng');
    await page.getByRole('button', { name: 'Đóng', exact: true }).first().click();
    await page.getByTestId('shipment-search').fill(`VTP${run}F`);
    await expect(page.getByTestId('shipment-table')).toContainText('Chờ lấy hàng');
});
