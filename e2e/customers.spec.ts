import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/customers';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test.describe.configure({ mode: 'serial' });

test('customer groups: list with terms and customer counts, add a group', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/customers/groups');
    await expect(page.getByTestId('group-table')).toContainText('Khách VIP');
    await expect(page.getByTestId('group-table')).toContainText('Giá bán buôn');

    await page.getByTestId('add-group').click();
    await page.getByTestId('g-name').fill('Khách online');
    await page.getByTestId('g-save').click();
    await expect(page.getByTestId('group-table')).toContainText('KHACHONLINE');
    await shot(page, '01-customer-groups');
});

test('customer list: views, extra filters, column picker and bulk tag', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/customers');
    await expect(page.getByTestId('customer-table')).toContainText('Lê Thị Hoa');

    await page.getByTestId('customer-view').getByRole('button', { name: 'Ngừng giao dịch' }).click();
    await expect(page.getByTestId('customer-table')).toContainText('Ngô Thanh Hà');
    await expect(page.getByTestId('customer-table')).not.toContainText('Lê Thị Hoa');
    await page.getByTestId('customer-view').getByRole('button', { name: 'Tất cả' }).click();

    await page.getByTestId('more-filters').click();
    await page.getByLabel('Tag').selectOption('Khách quen');
    await expect(page.getByTestId('customer-table').locator('tbody tr')).toHaveCount(2);
    await expect(page).toHaveURL(/tag=/);

    await page.getByTestId('column-picker').click();
    await page.getByRole('checkbox', { name: 'Tags' }).check();
    await page.getByRole('checkbox', { name: 'Phụ trách' }).check();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('customer-table').locator('thead')).toContainText('Tags');
    await shot(page, '02-customer-filters');

    await page.getByLabel('Chọn tất cả trên trang').check();
    await page.getByTestId('bulk-action').selectOption('add_tag');
    await page.getByTestId('bulk-value').fill('Tết 2027');
    await shot(page, '03-customer-bulk');
    await page.getByTestId('bulk-apply').click();
    await expect(page.getByTestId('customer-table')).toContainText('Tết 2027');
});

test('customer detail: profile, group terms, debt book, addresses and notes', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/customers');
    await page.getByTestId('customer-table').getByRole('link', { name: 'Phạm Minh Tuấn' }).click();
    await expect(page.getByText('Giá bán buôn')).toBeVisible();
    await expect(page.getByTestId('customer-debt')).not.toHaveText('0 ₫');
    await shot(page, '04-customer-detail');

    await page.getByTestId('customer-tabs').getByRole('button', { name: /Công nợ/ }).click();
    await expect(page.getByTestId('customer-ledger')).toContainText('Ghi nhận đơn hàng');
    await page.getByTestId('debt-adjust').click();
    await page.getByTestId('de-amount').fill('20000');
    await page.getByTestId('de-reason').fill('Phí giao lại lần hai');
    await page.getByTestId('de-save').click();
    await expect(page.getByTestId('customer-ledger')).toContainText('Điều chỉnh công nợ: Phí giao lại lần hai');
    await shot(page, '05-customer-ledger');

    await page.getByTestId('customer-tabs').getByRole('button', { name: /Trả hàng/ }).click();
    await page.getByTestId('customer-tabs').getByRole('button', { name: /Ghi chú và hoạt động/ }).click();
    await expect(page.getByTestId('customer-activity')).toContainText('Điều chỉnh công nợ');

    await page.getByTestId('customer-tabs').getByRole('button', { name: /Địa chỉ/ }).click();
    await expect(page.getByText('Kho Long Biên')).toBeVisible();
    await page.getByTestId('add-address').click();
    await page.getByTestId('a-address').fill('99 Phố Huế, Hai Bà Trưng, Hà Nội');
    await page.getByTestId('a-save').click();
    await expect(page.getByText('99 Phố Huế, Hai Bà Trưng, Hà Nội')).toBeVisible();

    await page.getByTestId('customer-tabs').getByRole('button', { name: /Ghi chú/ }).click();
    await page.getByTestId('note-input').fill('Khách muốn nhận báo giá sỉ mới qua Zalo.');
    await page.getByTestId('note-add').click();
    await expect(page.getByText('Khách muốn nhận báo giá sỉ mới qua Zalo.')).toBeVisible();
    await shot(page, '06-customer-notes');

    await page.getByTestId('edit-customer').click();
    await expect(page.getByRole('dialog')).toContainText('Nhân viên phụ trách');
    await shot(page, '07-customer-form');
});

test('customer import: template, preview with row errors', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/customers');
    await page.getByTestId('customer-import').click();
    await page.getByTestId('customer-import-file').setInputFiles({
        name: 'khach-hang.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from('﻿Tên khách hàng,Số điện thoại,Nhóm khách hàng,Ngày sinh\nKhách A,0901000001,VIP,01/02/1990\nKhách B,0901000001,Không có,31/02/1990\n'),
    });
    await expect(page.getByTestId('customer-import-preview')).toContainText('Dòng 3');
    await shot(page, '08-customer-import-errors');
});

test('duplicate customers: listed for review, merge previewed before anything moves', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/customers/duplicates');
    await expect(page.getByTestId('duplicate-groups')).toContainText('Trùng mã số thuế');
    await expect(page.getByTestId('duplicate-groups')).toContainText('Công ty TNHH Thương mại PMT');
    await page.getByTestId('duplicate-groups').getByRole('button', { name: /Gộp vào/ }).first().click();
    await expect(page.getByTestId('merge-preview')).toContainText('Thông tin khác nhau');
    await shot(page, '09-customer-merge');
    await page.getByRole('dialog').getByRole('button', { name: 'Hủy' }).click();
});
