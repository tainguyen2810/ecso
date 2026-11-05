import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/shipping-s1';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test('failed delivery: redeliver, correct the tracking number, then the COD statement marks the order delivered', async ({ page }) => {
    // Tracking numbers are unique per business: a run of its own does not clash with an earlier one.
    const run = Date.now().toString().slice(-6);
    await login(page, 'owner@demo.test');
    await page.goto('/orders/new');
    await page.getByTestId('sku-picker').fill('ATC-TRANG-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('stage-confirmed').check();
    await page.getByTestId('submit-order').click();
    await expect(page.getByTestId('order-status')).toHaveText('Chờ đóng gói');

    await page.getByTestId('action-handover').click();
    await page.getByTestId('tracking-input').fill(`GHN${run}A`);
    await page.getByTestId('confirm-handover').click();
    await expect(page.getByTestId('order-status')).toHaveText('Đã bàn giao');

    await page.getByTestId('action-fail_delivery').click();
    await expect(page.getByTestId('order-status')).toHaveText('Giao thất bại');
    await expect(page.getByTestId('action-redeliver')).toBeVisible();
    await shot(page, '01-giao-that-bai-co-giao-lai');

    await page.getByTestId('action-redeliver').click();
    await expect(page.getByTestId('order-status')).toHaveText('Đang giao');

    // The tracking number was typed wrong: corrected on the order.
    await page.getByTestId('edit-shipping').click();
    await page.getByTestId('shipping-tracking-input').fill(`GHN${run}B`);
    await shot(page, '02-sua-ma-van-don');
    await page.getByTestId('save-shipping').click();
    await expect(page.getByText(`GHN${run}B`)).toBeVisible();
    const orderUrl = page.url();

    // The carrier's statement shows the COD collected: the order can be marked delivered while booking it.
    await page.goto('/cash/cod');
    await page.getByTestId('cod-import').click();
    await page.getByTestId('cod-carrier').selectOption('ghn');
    const csv = `Mã đơn GHN,Tiền thu hộ (COD),Tổng phí\nGHN${run}B,1.000,18.000\n`;
    await page.getByTestId('cod-file').setInputFiles({ name: 'doi-soat.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf-8') });
    await expect(page.getByTestId('cod-preview')).toContainText(`GHN${run}B`);
    await expect(page.getByTestId('cod-mark-delivered')).toContainText('1 đơn');
    await shot(page, '03-doi-soat-danh-dau-da-giao');
    await page.getByTestId('cod-confirm').click();
    await expect(page.getByTestId('cod-detail')).toBeVisible();

    await page.goto(orderUrl);
    await expect(page.getByTestId('order-status')).toHaveText('Đã giao');
    await shot(page, '04-don-da-giao-sau-doi-soat');
});
