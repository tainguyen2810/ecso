import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-10';
async function shot(page: Page, name: string, dir = DIR) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, dir);
}
const SHIFTS = 'docs/screenshots/cash-shifts';

test.describe.configure({ mode: 'serial' });

test('POS: open a shift, scan, change for cash, pay, count the drawer and close', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.evaluate(() => localStorage.removeItem('ecso.pos.carts'));
    await page.getByRole('link', { name: 'Bán tại quầy' }).first().click();
    await expect(page.getByTestId('pos-products').locator('button').first()).toBeVisible();

    // No open shift: the counter asks for the opening float first.
    await expect(page.getByTestId('shift-open-form')).toBeVisible();
    await page.getByTestId('shift-opening').fill('500000');
    await shot(page, '01-open-shift', SHIFTS);
    await page.getByTestId('shift-open').click();
    await expect(page.getByTestId('pos-shift')).toContainText('Ca CA-');

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

    // Take cash to the safe, then count the drawer and close.
    await page.getByTestId('pos-shift').click();
    await expect(page.getByTestId('shift-panel')).toBeVisible();
    await page.getByTestId('shift-cash-out').click();
    await page.getByTestId('shift-move-amount').fill('100000');
    await page.getByTestId('shift-move-reason').fill('Nộp két sắt');
    await page.getByTestId('shift-move-save').click();
    await expect(page.getByTestId('shift-panel')).toContainText('Nộp két sắt');
    await shot(page, '02-shift-running', SHIFTS);

    const expected = Number((await page.getByTestId('shift-expected').innerText()).replace(/\D/g, ''));
    await page.getByTestId('shift-close-start').click();
    await page.getByTestId('shift-note-500000').fill(String(Math.floor(expected / 500000)));
    await page.getByTestId('shift-note-10000').fill(String(Math.floor((expected % 500000) / 10000)));
    await expect(page.getByTestId('shift-diff-live')).toBeVisible();
    const live = await page.getByTestId('shift-diff-live').innerText();
    if (live !== 'Khớp') {
        await page.getByTestId('shift-close').click();
        await expect(page.getByTestId('shift-error')).toContainText('lý do');
        await page.getByTestId('shift-close-note').fill('Tiền lẻ dưới 10.000 để riêng');
    }
    await page.getByTestId('shift-handed-over').fill('500000');
    await shot(page, '03-shift-count', SHIFTS);
    await page.getByTestId('shift-close').click();
    await expect(page.getByText(/Đã đóng ca CA-/).first()).toBeVisible();
    await expect(page.getByTestId('shift-difference')).toBeVisible();
    await shot(page, '04-shift-closed', SHIFTS);
    await page.getByRole('button', { name: 'Xong' }).click();
    await expect(page.getByTestId('pos-shift')).toContainText('Mở ca');

    // Selling needs an open shift.
    await page.getByTestId('pos-pay').click();
    await expect(page.getByTestId('shift-open-form')).toBeVisible();
    await page.keyboard.press('Escape');

    await page.getByTestId('pos-shift-history').click();
    await expect(page.getByTestId('shift-list')).toContainText('CA-');
    await shot(page, '05-shift-history', SHIFTS);
});
