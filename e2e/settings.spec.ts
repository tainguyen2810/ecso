import { expect, test } from '@playwright/test';
import { login, shot } from './helpers';

const DIR = 'docs/screenshots/settings';

test('Cấu hình like Sapo: store details, bank account, print template, sales rules', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.getByRole('link', { name: 'Cấu hình', exact: true }).click();
    await expect(page.getByTestId('settings-hub')).toBeVisible();
    await shot(page, '01-hub', DIR);

    await page.getByRole('link', { name: /Thông tin cửa hàng/ }).click();
    await page.getByTestId('store-phone').fill('0909123456');
    await page.getByTestId('store-tax-code').fill('0312345678');
    await page.getByTestId('store-address').fill('106 Đình Nghi Xuân');
    await page.getByTestId('logo-input').setInputFiles({
        name: 'logo.png',
        mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64'),
    });
    await expect(page.getByTestId('store-logo')).toBeVisible();
    await page.getByTestId('settings-save').click();
    await expect(page.getByText('Đã lưu cấu hình.')).toBeVisible();
    await page.reload();
    await expect(page.getByTestId('store-tax-code')).toHaveValue('0312345678');
    await shot(page, '02-store', DIR);

    await page.goto('/settings/payments');
    await page.getByTestId('add-bank').click();
    await page.getByTestId('bank-number-0').fill('0909123456');
    await page.getByTestId('bank-holder-0').fill('CONG TY DEMO');
    // Large payment vouchers wait for approval from 5 000 000 (owners approve their own).
    await page.getByTestId('approval-on').getByRole('switch').click();
    await expect(page.getByTestId('approval-threshold')).toHaveValue('5.000.000');
    await page.getByTestId('settings-save').click();
    await expect(page.getByText('Đã lưu cấu hình.')).toBeVisible();
    await shot(page, '03-payments', DIR);

    await page.goto('/settings/print');
    await page.getByTestId('print-footer').fill('Đổi trả trong 7 ngày kèm hóa đơn');
    await page.getByTestId('settings-save').click();
    await expect(page.getByText('Đã lưu cấu hình.')).toBeVisible();
    await shot(page, '04-print', DIR);

    await page.goto('/settings/sales');
    await page.getByTestId('sales-change-time').click();
    await page.getByTestId('add-reason').click();
    await page.getByTestId('reason-5').fill('Khách đặt nhầm');
    await page.getByTestId('settings-save').click();
    await expect(page.getByText('Đã lưu cấu hình.')).toBeVisible();
    await shot(page, '05-sales', DIR);

    // The rules apply where staff work: back-dating appears on the order form.
    await page.goto('/orders/new');
    await expect(page.getByTestId('form-placed-at')).toBeVisible();

    await page.goto('/settings/pricing');
    await shot(page, '06-pricing', DIR);
});
