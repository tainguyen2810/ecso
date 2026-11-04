import { expect, test } from '@playwright/test';
import { login, shot } from './helpers';

const DIR = 'docs/screenshots/cash-round-3';

test('count a fund, then close the books and see a closed-day voucher refused', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/cash/accounts');
    await expect(page.getByTestId('fund-cash')).toBeVisible();

    // Count the cash drawer 10,000 short: a payment voucher is booked and the balance follows.
    await page.getByTestId('count-cash').click();
    const balance = await page.getByTestId('fund-cash').locator('p.text-2xl').innerText();
    const system = Number(balance.replace(/\D/g, ''));
    await page.getByTestId('c-amount').fill(String(Math.max(system - 10000, 0)));
    await expect(page.getByTestId('c-diff')).toContainText('Thiếu');
    await page.getByTestId('c-note').fill('Kiểm két cuối ngày');
    await shot(page, '01-kiem-quy', DIR);
    await page.getByTestId('c-save').click();
    await expect(page.getByText(/Đã kiểm quỹ/)).toBeVisible();

    // Close the books up to yesterday, with the balances preview.
    await page.getByTestId('open-closing').click();
    await expect(page.getByTestId('cl-preview')).toBeVisible();
    await shot(page, '02-khoa-so', DIR);
    await page.getByTestId('cl-save').click();
    await expect(page.getByTestId('closing-history')).toContainText('Đang áp dụng');
    await shot(page, '03-lich-su-khoa-so', DIR);
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('closing-status')).toContainText('Sổ đã khóa đến hết ngày');
    await shot(page, '04-trang-quy', DIR);

    // Reopen so other tests can still backdate.
    await page.getByTestId('open-closing').click();
    await page.getByTestId('cl-reopen').click();
    await page.getByTestId('cl-reason').fill('Mở lại sau khi kiểm thử');
    await page.getByTestId('cl-reopen-confirm').click();
    await expect(page.getByTestId('closing-history')).toContainText('Đã mở lại');
});
