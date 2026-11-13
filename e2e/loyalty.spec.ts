import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

/** Tích điểm và hạng khách (C5): turn the program on with tiers, then give a customer points by hand. */
const DIR = 'docs/screenshots/loyalty';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test('loyalty: set up points and tiers, then give a customer points', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const pageErrors: string[] = [];
    page.on('pageerror', (e) => pageErrors.push(e.message));
    page.on('console', (m) => m.type() === 'error' && pageErrors.push(m.text()));
    await login(page, 'owner@demo.test');

    await page.goto('/customers');
    await page.getByRole('link', { name: 'Tích điểm và hạng', exact: true }).first().click();
    await expect(page).toHaveURL(/\/customers\/loyalty$/);
    await page.getByTestId('loyalty-enabled').check();
    await page.getByTestId('tier-add').click();
    await page.getByTestId('tier-name-0').fill('Thành viên');
    await page.getByTestId('tier-min-0').fill('0');
    await page.getByTestId('tier-add').click();
    await page.getByTestId('tier-name-1').fill('Bạc');
    await page.getByTestId('tier-min-1').fill('3000000');
    await shot(page, '01-cai-dat-tich-diem');
    await page.getByTestId('loyalty-save').click();
    await expect(page.getByText('Đã lưu tích điểm và hạng khách.')).toBeVisible();
    // Tier names sit in inputs, so the saved row is read from its input, not from the table text.
    await expect(page.getByTestId('tier-name-1')).toHaveValue('Bạc');

    await page.goto('/customers');
    await page.getByTestId('customer-q').fill('Võ Quốc Bảo');
    await page.getByTestId('customer-table').getByRole('link', { name: 'Võ Quốc Bảo' }).first().click();
    await expect(page).toHaveURL(/\/customers\/[0-9A-Za-z]{20,}/);
    const card = page.getByTestId('customer-loyalty');
    await card.waitFor({ timeout: 10000 }).catch(async () => {
        throw new Error(`No loyalty card. url=${page.url()} errors=${pageErrors.join(' | ')} page=${(await page.locator('main').innerText().catch(() => '')).slice(0, 400)}`);
    });
    const before = Number((await card.getByTestId('loyalty-points').innerText()).replace(/\D/g, '')) || 0;
    await card.getByTestId('loyalty-adjust').click();
    await card.getByTestId('loyalty-adjust-points').fill('20');
    await card.getByTestId('loyalty-adjust-note').fill('Bù đơn giao trễ');
    await card.getByTestId('loyalty-adjust-save').click();
    await expect(card.getByTestId('loyalty-points')).toHaveText(`${before + 20} điểm`);
    await expect(card.getByTestId('loyalty-history')).toContainText('Bù đơn giao trễ');
    await card.scrollIntoViewIfNeeded();
    await shot(page, '02-diem-tren-khach');
});
