import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-10';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test.describe.configure({ mode: 'serial' });

test('POS: scan, change for cash, pay, shift summary', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.evaluate(() => localStorage.removeItem('ecso.pos.carts'));
    await page.getByRole('link', { name: 'Bán tại quầy' }).first().click();
    await expect(page.getByTestId('pos-products').locator('button').first()).toBeVisible();

    // A barcode scanner types the code and presses Enter.
    for (let i = 0; i < 2; i++) {
        await page.getByTestId('pos-search').fill('ATC-TRANG-M');
        await page.getByTestId('pos-search').press('Enter');
        await expect(page.getByTestId('pos-search')).toHaveValue('');
    }
    await page.getByTestId('pos-products').locator('button:not([disabled])').nth(3).click();
    await expect(page.getByTestId('pos-qty-0')).toHaveValue('2');
    await page.getByTestId('pos-received').fill('1000000');
    await expect(page.getByTestId('pos-change')).toBeVisible();
    await shot(page, '01-pos-cart');

    // Park this sale and start another one.
    await page.getByTestId('pos-new-cart').click();
    await expect(page.getByTestId('pos-cart')).toContainText('Chưa có sản phẩm');
    await page.getByRole('button', { name: /Đơn 1/ }).click();

    await page.getByText('In hóa đơn K80').click(); // no print dialog in tests
    await page.getByTestId('pos-pay').click();
    await expect(page.getByText(/Đã bán SO-/)).toBeVisible();
    await expect(page.getByTestId('pos-cart')).toContainText('Chưa có sản phẩm');

    // Walk-in buyer paying less than the total is refused.
    await page.getByTestId('pos-search').fill('ATC-TRANG-M');
    await page.getByTestId('pos-search').press('Enter');
    await page.getByTestId('pos-received').fill('1000');
    await page.getByTestId('pos-pay').click();
    await expect(page.getByTestId('pos-error')).toContainText('thanh toán đủ');
    await shot(page, '02-pos-walk-in-must-pay');

    await page.getByTestId('pos-summary').click();
    await expect(page.getByTestId('pos-summary-body')).toContainText('Tiền mặt trong két');
    await shot(page, '03-pos-shift-summary');
});
