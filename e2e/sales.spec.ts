import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-9';
async function shot(page: Page, name: string) {
    // Toasts stack over the right column in full-page shots: dismiss them first.
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test.describe.configure({ mode: 'serial' });

test('orders list mixes marketplace and own-channel orders, with payment status', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/orders');
    await page.getByTestId('filter-source').selectOption('manual');
    await expect(page.getByTestId('order-table')).toContainText('SO-0000');
    await expect(page.getByTestId('order-table')).toContainText('Thanh toán một phần');
    await shot(page, '01-orders-own-channels');
});

test('create a manual order for a new customer, then confirm, pack, hand over and collect payment', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/orders');
    await page.getByTestId('create-order').click();
    await expect(page.getByTestId('order-form')).toBeVisible();

    await page.getByTestId('order-channel').selectOption({ label: 'Fanpage Cửa hàng Mẫu · Facebook' });
    await page.getByTestId('sku-picker').fill('ATC-TRANG-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('sku-picker').fill('POL-NAVY-XL');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('line-qty-0').fill('2');

    await page.getByTestId('new-customer').click();
    await page.getByTestId('nc-name').fill('Hoàng Anh Thư');
    await page.getByTestId('nc-phone').fill('0938 123 456');
    // Pasted old-style address: split into the new units (P. Bến Nghé, Q.1 is now Phường Sài Gòn).
    await page.locator('#nc-addr-quick').fill('20 Pasteur, P. Bến Nghé, Quận 1, TP.HCM');
    await page.locator('#nc-addr-quick').press('Enter');
    await expect(page.locator('#nc-addr-ward')).toHaveValue('Phường Sài Gòn');
    await expect(page.locator('#nc-addr-street')).toHaveValue('20 Pasteur');
    await page.getByTestId('nc-save').click();
    await expect(page.getByTestId('customer-delivery')).toContainText('20 Pasteur, Phường Sài Gòn, Thành phố Hồ Chí Minh');
    await page.getByTestId('carrier-GHN').click();
    await page.getByTestId('shipping-fee').fill('30000');
    await page.getByTestId('order-discount').fill('10000');
    await shot(page, '02-order-form');

    await page.getByTestId('submit-order').click();
    await expect(page.getByTestId('order-status')).toHaveText('Chờ xác nhận');
    await expect(page.getByTestId('payment-status')).toHaveText('Chưa thanh toán');
    await shot(page, '03-order-detail-new');

    await page.getByTestId('action-confirm').click();
    await expect(page.getByTestId('order-status')).toHaveText('Chờ đóng gói');
    await page.getByTestId('action-pack').click();
    await expect(page.getByTestId('order-status')).toHaveText('Chờ bàn giao');
    await page.getByTestId('action-handover').click();
    await page.getByTestId('tracking-input').fill('GHN5566778');
    await page.getByTestId('confirm-handover').click();
    await expect(page.getByTestId('order-status')).toHaveText('Đã bàn giao');

    await page.getByTestId('add-payment').click();
    await page.getByTestId('pay-amount-input').fill('100000');
    await page.getByTestId('confirm-pay').click();
    await expect(page.getByTestId('payment-status')).toHaveText('Thanh toán một phần');
    await shot(page, '04-order-detail-shipped-partial-payment');
});

test('counter sale beyond stock is refused', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/orders/new');
    await page.getByTestId('sku-picker').fill('ATC-TRANG-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('line-qty-0').fill('100000');
    await page.getByTestId('stage-completed').check();
    await page.getByTestId('submit-order').click();
    await expect(page.getByTestId('stock-error')).toContainText('không đủ hàng');
    await shot(page, '05-counter-sale-out-of-stock');
});

test('customers: list with debt, detail with order history', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.getByRole('button', { name: 'Khách hàng', exact: true }).click();
    await page.getByRole('link', { name: 'Danh sách khách hàng' }).click();
    await expect(page.getByTestId('customer-table')).toContainText('Phạm Minh Tuấn');
    await shot(page, '06-customers');

    await page.getByTestId('customer-table').getByText('Phạm Minh Tuấn').click();
    await expect(page.getByTestId('customer-debt')).not.toHaveText('0 ₫');
    await shot(page, '07-customer-detail');

    await page.goto('/customers');
    await page.getByTestId('add-customer').click();
    await page.getByTestId('c-name').fill('Trùng số');
    await page.getByTestId('c-phone').fill('84912345678');
    await page.getByTestId('c-save').click();
    await expect(page.getByRole('dialog')).toContainText('đã thuộc khách');
    await shot(page, '08-customer-duplicate-phone');
});

test('sales channels settings', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/sales-channels');
    await expect(page.getByTestId('channel-table')).toContainText('Zalo OA');
    await shot(page, '09-sales-channels');
});
