import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-4';
const shot = (page: Page, name: string) => baseShot(page, name, DIR);

test('Shopee: connector skeleton is visible, connecting is refused honestly without credentials', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/marketplaces');
    const card = page.getByTestId('real-marketplaces');
    await expect(card).toContainText('Shopee');
    await expect(card).toContainText('Chưa có credentials');

    await page.getByTestId('connect-shopee').click();
    await expect(page.getByTestId('connect-notice-shopee')).toContainText('chưa được cấu hình app credentials');
    await expect(page).toHaveURL(/\/marketplaces$/);
    await shot(page, '01-shopee-connect-refused');

    await page.getByTestId('capability-matrix').scrollIntoViewIfNeeded();
    await expect(page.getByTestId('capability-matrix')).toContainText('Kết nối shop (ủy quyền)');
});

test('callback with an unknown state is rejected', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/marketplaces/shopee/callback?state=forged&code=abc&shop_id=1');
    await expect(page.getByText('Không kết nối được')).toBeVisible();
    await shot(page, '02-callback-rejected');
});

test('TikTok Shop: skeleton connector, connect refused without credentials', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/marketplaces');
    await expect(page.getByTestId('real-marketplaces')).toContainText('TikTok Shop');
    await page.getByTestId('connect-tiktok').click();
    await expect(page.getByTestId('connect-notice-tiktok')).toContainText('chưa được cấu hình app credentials');
    await page.screenshot({ path: 'docs/screenshots/phase-5/01-tiktok-connect-refused.png', clip: { x: 0, y: 0, width: 1440, height: 640 } });
});

test('Lazada and Tiki: skeleton connectors, connect refused without credentials, manual stock update guidance', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/marketplaces');
    const card = page.getByTestId('real-marketplaces');
    await expect(card).toContainText('Lazada');
    await expect(card).toContainText('Tiki');
    await expect(card).not.toContainText('Chưa có connector');
    await expect(page.getByTestId('stock-note-tiki')).toContainText('cập nhật tay trên Seller Center');

    await page.getByTestId('connect-lazada').click();
    await expect(page.getByTestId('connect-notice-lazada')).toContainText('chưa được cấu hình app credentials');
    await page.getByTestId('connect-tiki').click();
    await expect(page.getByTestId('connect-notice-tiki')).toContainText('chưa được cấu hình app credentials');
    await card.scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'docs/screenshots/phase-7/01-lazada-tiki-connect-refused.png', clip: { x: 0, y: 0, width: 1440, height: 640 } });
});
