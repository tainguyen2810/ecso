import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

/** A5: the setup steps after a shop is connected for the first time (fake marketplace only, not a real shop). */
const DIR = 'docs/screenshots/shop-setup';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test('a newly connected shop goes through linking, opening stock and switching sync on', async ({ page }) => {
    test.setTimeout(180_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'owner@demo.test');
    await page.goto('/marketplaces');

    await page.getByTestId('connect-fake').click();
    await page.getByRole('dialog').getByRole('textbox').first().fill('Zz Shop Thiết Lập');
    await page.getByTestId('connect-fake-setup').check();
    await page.getByTestId('connect-fake-submit').click();

    // 1. Products pulled from the shop.
    await expect(page.getByTestId('shop-setup')).toBeVisible();
    await expect(page.getByTestId('shop-setup-next')).toBeEnabled();
    await shot(page, '01-keo-san-pham');
    await page.getByTestId('shop-setup-next').click();

    // 2. Link by seller SKU.
    await expect(page.getByTestId('shop-setup-link')).toBeVisible();
    await shot(page, '02-ghep-sku');
    await page.getByTestId('shop-setup-link').click();

    // 3. Opening stock: the three choices; this run keeps stock sync off so other tests' stock is untouched.
    const stock = page.getByTestId('shop-setup-stock');
    await expect(stock.getByRole('radio')).toHaveCount(3);
    await shot(page, '03-ton-ban-dau');
    await stock.getByRole('radio', { name: /Chưa đồng bộ tồn/ }).click();
    await page.getByTestId('shop-setup-stock-save').click();

    // 4. Switch sync on.
    await expect(page.getByTestId('shop-setup-finish')).toBeVisible();
    await shot(page, '04-bat-dong-bo');
    await page.getByTestId('shop-setup-finish').click();
    await expect(page).toHaveURL(/\/marketplaces$/);

    // The shop leaves the setup state; disconnect it so later tests see the shops they expect.
    const row = page.locator('div', { hasText: 'Zz Shop Thiết Lập' }).filter({ has: page.getByRole('button', { name: 'Ngắt' }) }).last();
    await expect(row).not.toContainText('Chưa thiết lập xong');
    await row.getByRole('button', { name: 'Ngắt' }).click();
});
