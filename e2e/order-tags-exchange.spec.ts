import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-18';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test.describe.configure({ mode: 'serial' });

test('tags and promised delivery date on an order, then filter and save the view', async ({ page }) => {
    // Unique per run: the e2e database is reused between runs.
    const tag = `Khách VIP ${Date.now() % 10000}`;
    const day = String(10 + (Date.now() % 18));

    await login(page, 'owner@demo.test');
    await page.goto('/orders?source=manual');
    await page.locator('[data-testid="order-table"] tbody tr').first().click();
    await expect(page.getByTestId('order-meta')).toBeVisible();

    const saved = () => page.waitForResponse((r) => r.url().includes('/meta') && r.request().method() === 'PUT');
    let done = saved();
    await page.getByTestId('tag-input').fill(tag);
    await page.getByTestId('tag-input').press('Enter');
    await done;
    done = saved();
    await page.getByTestId('deliver-on').fill(`2026-11-${day}`);
    await page.getByTestId('deliver-on').blur();
    await done;
    await shot(page, '01-order-tags-delivery');

    await page.goto('/orders');
    await page.getByTestId('more-filters').click();
    await page.getByTestId('filter-tag').selectOption(tag);
    await expect(page.getByTestId('order-table').locator('tbody > tr')).toHaveCount(1);
    await expect(page.getByTestId('row-deliver-on')).toContainText(`${day}/11/26`);

    page.once('dialog', (d) => d.accept(tag));
    await page.getByTestId('save-view').click();
    await expect(page.getByTestId('saved-views')).toContainText(tag);

    await page.getByTestId('columns-button').click();
    await page.getByTestId('columns-menu').getByLabel('Người xử lý').uncheck();
    await page.getByTestId('columns-button').click();
    await shot(page, '02-order-list-saved-view');
});

test('exchange: a return pays for the new order', async ({ page }) => {
    await login(page, 'owner@demo.test');
    // A completed, paid counter order of the demo shop.
    await page.goto('/orders?source=manual&tab=done&payment_status=paid');
    await page.locator('[data-testid="order-table"] tbody tr').first().click();

    await page.getByTestId('create-return').click();
    await page.getByRole('dialog').getByRole('button', { name: 'Tạo phiếu' }).click();
    await expect(page.getByTestId('create-exchange')).toBeVisible();
    await page.getByTestId('create-exchange').first().click();

    await expect(page.getByTestId('exchange-banner')).toBeVisible();
    await page.locator('[data-shortcut="sku"] input').fill('ATC-DEN-M');
    await page.locator('[data-shortcut="sku"] input').press('Enter');
    await expect(page.getByTestId('order-lines')).toBeVisible();
    await expect(page.getByTestId('exchange-credit')).not.toHaveText('−0 ₫');
    await shot(page, '03-exchange-order-form');

    await page.getByTestId('submit-order').click();
    await expect(page.getByTestId('exchanged-from')).toBeVisible();
    await expect(page.getByTestId('payments-card')).toContainText('Bù trừ đổi hàng');
    await shot(page, '04-exchange-order-detail');
});
