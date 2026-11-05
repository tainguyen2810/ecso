import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/sync-center';
const shot = (page: Page, name: string) => baseShot(page, name, DIR);

test('sync center: overview, queue with task detail, problems, order log', async ({ page }) => {
    await login(page, 'owner@demo.test');
    // A couple of marketplace orders so the queue has webhooks and stock pushes to show.
    await page.goto('/marketplaces');
    await page.getByTestId('sim-count').fill('2');
    await page.getByTestId('sim-create').click();
    await expect(page.getByText(/Sàn giả lập đã tạo 2 đơn/)).toBeVisible();

    await page.getByRole('link', { name: 'Trung tâm đồng bộ' }).first().click();
    await expect(page).toHaveURL(/\/marketplaces\/sync/);
    await expect(page.getByTestId('sync-center-shops')).toContainText('Shop Demo');
    await expect(page.getByTestId('sync-center-task-tiles')).toContainText('Thành công');
    await shot(page, '01-tong-quan');

    await page.getByTestId('sync-center-tabs').getByRole('button', { name: 'Hàng đợi' }).click();
    await expect(page.getByTestId('sync-queue')).toContainText('Webhook');
    await page.getByTestId('sync-queue-row').first().click();
    await expect(page.getByTestId('sync-task-drawer')).toBeVisible();
    await expect(page.getByTestId('sync-task-drawer')).toContainText('Correlation ID');
    await shot(page, '02-hang-doi-chi-tiet');
    await page.keyboard.press('Escape');

    await page.getByTestId('sync-center-tabs').getByRole('button', { name: /Lỗi cần xử lý/ }).click();
    await expect(page.getByText('Đơn chưa đồng bộ được')).toBeVisible();
    await shot(page, '03-loi-can-xu-ly');

    await page.getByTestId('sync-center-tabs').getByRole('button', { name: 'Nhật ký đơn' }).click();
    await expect(page.getByTestId('sync-account')).toBeVisible();
});

test('sync center: stock allocation per shop with a preview before saving', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/marketplaces/sync?tab=allocation');
    await expect(page.getByTestId('allocation-shops')).toContainText('Toàn bộ');
    await shot(page, '04-phan-bo-ton');

    await page.getByTestId('allocation-edit-shop').first().click();
    await page.getByTestId('allocation-mode').getByRole('button', { name: 'Theo %' }).click();
    await page.getByTestId('allocation-value').fill('50');
    await page.getByTestId('allocation-buffer').fill('1');
    await expect(page.getByTestId('allocation-preview')).toBeVisible();
    await shot(page, '05-phan-bo-xem-truoc');
    await page.getByTestId('allocation-save').click();
    await expect(page.getByTestId('allocation-shops')).toContainText('50%');

    // Back to publishing everything, so other specs see the usual numbers.
    await page.getByTestId('allocation-edit-shop').first().click();
    await page.getByTestId('allocation-reset').click();
    await expect(page.getByTestId('allocation-shops')).toContainText('Toàn bộ');
});
