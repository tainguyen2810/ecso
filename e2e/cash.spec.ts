import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-18';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test.describe.configure({ mode: 'serial' });

test('payment voucher, customer debt receipt, void and print, cashbook', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/cash');
    await expect(page.getByTestId('voucher-table')).toBeVisible();
    await shot(page, '01-voucher-list');

    // A payment voucher for rent, saved with the keyboard-friendly form.
    await page.getByTestId('new-payment').click();
    await page.getByTestId('party-kind-other').click();
    await page.getByTestId('v-party').fill('Chủ nhà mặt bằng');
    await page.getByTestId('v-amount').fill('5000000');
    await expect(page.getByTestId('v-amount')).toHaveValue('5.000.000');
    await expect(page.getByText('Năm triệu đồng')).toBeVisible();
    await page.getByTestId('v-type').selectOption({ label: 'Thuê mặt bằng' });
    await page.getByTestId('v-method').selectOption('bank_transfer');
    await page.getByTestId('v-note').fill('Tiền thuê tháng 11');
    await shot(page, '02-new-payment');
    await page.getByTestId('v-save').click();
    // The demo data may already hold a payment (a shift's drawer shortage), so find the rent voucher by its type.
    const rent = page.getByTestId('voucher-table').locator('tr', { hasText: 'Thuê mặt bằng' });
    await expect(rent).toContainText('PC-');

    // A receipt from a customer on credit shows how it would collect their unpaid orders, oldest first. Not saved:
    // later specs rely on the demo customer's debt (saving is covered by CashVoucherTest).
    await page.getByTestId('new-receipt').click();
    await page.getByTestId('party-kind-customer').click();
    await page.getByTestId('v-party').fill('Tuấn');
    await page.getByTestId('v-party-results').getByRole('button').first().click();
    await expect(page.getByTestId('v-debt-mode')).toBeChecked();
    await page.getByTestId('v-debt-all').click();
    await expect(page.getByTestId('v-debt')).toContainText('SO-');
    await shot(page, '03-customer-debt-receipt');
    await page.getByRole('dialog').getByRole('button', { name: 'Hủy' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    // Detail, then void the rent voucher with a reason.
    await page.getByTestId('voucher-direction').getByRole('button', { name: 'Phiếu chi' }).click();
    await rent.click();
    await expect(page.getByTestId('voucher-detail')).toContainText('Năm triệu đồng');
    await expect(page.getByTestId('print-voucher')).toBeVisible();
    // Keep the transfer slip with the voucher.
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAEAAAAAgCAIAAAAt/+nTAAAAKklEQVR42u3OMQEAAAQAMPJJIqgaxDBugwV2ZzKqnkwQEBAQEBAQEBAQEPgcLWABTwOAgPMAAAAASUVORK5CYII=', 'base64');
    await page.getByTestId('attachment-input').setInputFiles({ name: 'uy-nhiem-chi.png', mimeType: 'image/png', buffer: png });
    await expect(page.getByTestId('attachment-item')).toContainText('uy-nhiem-chi.png');
    await shot(page, '04-voucher-detail');
    await page.getByTestId('void-voucher').click();
    await page.getByTestId('void-reason').fill('Nhập nhầm số tiền');
    await page.getByTestId('void-confirm').click();
    await expect(page.getByTestId('voucher-detail')).toContainText('Nhập nhầm số tiền');
    await page.keyboard.press('Escape');

    await page.goto('/cash/types');
    await expect(page.getByTestId('types-payment')).toContainText('Chi phí quảng cáo');
    await shot(page, '05-voucher-types');

    await page.goto('/reports/cashbook');
    await expect(page.getByTestId('cashbook-table')).toBeVisible();
    await page.getByTestId('cashbook-tab-in').click();
    await expect(page.getByTestId('cashbook-table')).toContainText('Phạm Minh Tuấn');
    await page.getByTestId('cashbook-tab-all').click();
    await shot(page, '06-cashbook');
    await page.getByTestId('cashbook-glossary').click();
    await expect(page.getByRole('dialog')).toContainText('Tồn cuối kỳ');
    await shot(page, '07-cashbook-glossary');
    await page.keyboard.press('Escape');

    // Every row of the period as an Excel file written by the server.
    const download = page.waitForEvent('download');
    await page.getByTestId('cashbook-excel').click();
    expect((await download).suggestedFilename()).toMatch(/^so-quy-.*\.xlsx$/);

    // Money in and out by kind, month by month.
    await page.goto('/reports/view/cash-by-type');
    await expect(page.getByText('Khách trả tiền đơn hàng')).toBeVisible();
    await shot(page, '08-cash-by-type');
});
