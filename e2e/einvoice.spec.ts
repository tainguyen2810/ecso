import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

/** E-invoice (C2) with the built-in test provider: connect under Cấu hình → Kết nối dịch vụ, then issue from an order. */
const DIR = 'docs/screenshots/einvoice';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test('e-invoice: connect the provider, pick the template, issue from the order with the buyer edited', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'owner@demo.test');

    await page.goto('/settings');
    await page.getByRole('link', { name: /Kết nối dịch vụ/ }).click();
    await expect(page.getByTestId('service-connections')).toBeVisible();
    await shot(page, '01-ket-noi-dich-vu');

    await page.getByTestId('svc-einvoice-open-fake').click();
    const dialog = page.getByTestId('einvoice-dialog');
    await dialog.getByTestId('einvoice-cred-client_id').fill('demo-client');
    await dialog.getByTestId('einvoice-cred-client_secret').fill('wrong');
    await dialog.getByTestId('einvoice-connect').click();
    await expect(dialog).toContainText('từ chối');
    await dialog.getByTestId('einvoice-cred-client_secret').fill('demo-secret');
    await dialog.getByTestId('einvoice-connect').click();
    await expect(dialog.getByTestId('einvoice-status')).toContainText('Lấy được 2 mẫu');
    await dialog.getByTestId('einvoice-template').selectOption({ label: '1 - C26TEZ - Hóa đơn giá trị gia tăng' });
    await shot(page, '02-cau-hinh-hoa-don');
    await dialog.getByTestId('einvoice-save').click();
    await expect(page.getByTestId('svc-einvoice-fake')).toContainText('Đã kết nối');

    // A counter sale with a company buyer typed on the order.
    await page.goto('/orders/new');
    await page.getByTestId('sku-picker').fill('ATC-TRANG-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('new-customer').click();
    await page.getByTestId('nc-name').fill('Nguyễn Văn Bình');
    await page.getByTestId('nc-phone').fill(`09${Date.now().toString().slice(-8)}`);
    await page.locator('#nc-addr-quick').fill('20 Pasteur, P. Bến Nghé, Quận 1, TP.HCM');
    await page.locator('#nc-addr-quick').press('Enter');
    await expect(page.locator('#nc-addr-ward')).toHaveValue('Phường Sài Gòn');
    await page.getByTestId('nc-save').click();
    await expect(page.getByTestId('customer-delivery')).toContainText('20 Pasteur');
    await page.getByTestId('submit-order').click();
    await expect(page.getByTestId('order-status')).toHaveText('Chờ xác nhận');
    const card = page.getByTestId('einvoice-card');
    await expect(card.getByTestId('einvoice-state')).toHaveText('Chưa xuất');
    await card.getByTestId('einvoice-edit').click();
    await card.getByTestId('einvoice-buyer-name').fill('Công ty TNHH Thương mại PMT');
    await card.getByTestId('einvoice-buyer-tax').fill('12345');
    await card.getByTestId('einvoice-issue').click();
    await expect(card).toContainText('Mã số thuế gồm 10 số');
    await card.getByTestId('einvoice-buyer-tax').fill('0109876543');
    await card.getByTestId('einvoice-issue').click();
    await expect(card.getByTestId('einvoice-state')).toHaveText('Đã xuất');
    await expect(card.getByTestId('einvoice-issued')).toContainText('C26TEZ');
    await expect(card.getByTestId('einvoice-buyer')).toContainText('0109876543');
    await expect(card.getByTestId('einvoice-issue')).toHaveCount(0);
    await card.scrollIntoViewIfNeeded();
    await shot(page, '03-don-da-xuat-hoa-don');
});
