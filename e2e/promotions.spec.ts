import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

/**
 * Khuyến mãi (C4): create a coupon promotion under Khách hàng → Khuyến mãi, then use its code on a new order.
 * A code promotion only applies when typed, so it cannot change the totals other tests expect.
 */
const DIR = 'docs/screenshots/promotions';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test('promotions: create a coupon, apply it on an order, see it on the order', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'owner@demo.test');
    const code = `E2E${Date.now().toString().slice(-6)}`;

    await page.goto('/customers');
    await page.getByRole('link', { name: 'Khuyến mãi', exact: true }).first().click();
    await expect(page).toHaveURL(/\/promotions$/);
    await page.getByTestId('add-promotion').first().click();

    await page.getByTestId('kind-code').click();
    await page.getByTestId('p-name').fill('Khách mới giảm 50K');
    await page.getByTestId('p-code').fill(code.toLowerCase());
    await page.getByTestId('p-value').fill('50000');
    await page.getByTestId('p-kind').selectOption('amount');
    await expect(page.getByTestId('p-per')).toHaveValue('1');
    await expect(page.getByTestId('promotion-summary')).toContainText(`Khách đọc mã ${code}`);
    await shot(page, '01-tao-ma-giam-gia');
    await page.getByTestId('promotion-save').click();

    await expect(page).toHaveURL(/\/promotions$/);
    const row = page.getByTestId('promotion-table').locator('tr', { hasText: code });
    await expect(row).toContainText('Đang chạy');
    await shot(page, '02-danh-sach-khuyen-mai');

    // A new order: a wrong code says why, the right one takes 50.000 ₫ off.
    await page.goto('/orders/new');
    await page.getByTestId('sku-picker').fill('ATC-TRANG-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('order-coupon').fill('KHONGCO');
    await expect(page.getByTestId('coupon-error')).toContainText('không đúng');
    await page.getByTestId('order-coupon').fill(code);
    // Per-customer limit: the code asks for a customer first.
    await expect(page.getByTestId('coupon-error')).toContainText('Chọn khách hàng trước');
    await page.getByTestId('new-customer').click();
    await page.getByTestId('nc-name').fill('Khách Khuyến Mãi');
    await page.getByTestId('nc-phone').fill(`09${Date.now().toString().slice(-8)}`);
    await page.getByTestId('nc-save').click();
    await expect(page.getByTestId('order-promotion')).toContainText('50.000');
    await shot(page, '03-ma-tren-don');
    await page.getByTestId('submit-order').click();

    await expect(page.getByTestId('order-status')).toHaveText('Chờ xác nhận');
    await expect(page.getByTestId('order-promotions')).toContainText(`Mã ${code}`);
    await shot(page, '04-don-co-khuyen-mai');

    await page.goto('/promotions');
    await expect(page.getByTestId('promotion-table').locator('tr', { hasText: code })).toContainText('50.000');
});
