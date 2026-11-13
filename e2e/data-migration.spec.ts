import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

/** Chuyển dữ liệu (C7): upload a KiotViet product file as it is, check the columns it found, then import. */
const DIR = 'docs/screenshots/data-migration';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test('data migration: a KiotViet product file is read as it is and imported', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'owner@demo.test');

    await page.goto('/settings');
    await page.getByRole('link', { name: /Chuyển dữ liệu/ }).first().click();
    await expect(page).toHaveURL(/\/settings\/data-migration$/);

    const stamp = Date.now().toString().slice(-6);
    const csv = [
        'Mã hàng,Tên hàng,Nhóm hàng(3 Cấp),Giá bán,Giá vốn,Tồn kho,ĐVT,Thuộc tính,Mô tả',
        `KV${stamp}A,Dép quai ngang - Đen - 40,Thời trang>>Dép,"120,000","70,000",8,Đôi,MAUSAC:Đen|SIZE:40,`,
        `KV${stamp}B,Dép quai ngang - Đen - 41,Thời trang>>Dép,"120,000","70,000",6,Đôi,MAUSAC:Đen|SIZE:41,`,
        `,Dòng thiếu mã,,abc,,,,,`,
    ].join('\n');
    await page.getByTestId('migrate-file').setInputFiles({ name: 'kiotviet-hang-hoa.csv', mimeType: 'text/csv', buffer: Buffer.from('﻿' + csv, 'utf-8') });
    await expect(page.getByTestId('migrate-source')).toHaveText('File KiotViet');
    await expect(page.getByTestId('migrate-columns')).toContainText('Giá bán → Giá bán lẻ');
    await expect(page.getByTestId('migrate-errors')).toBeVisible();
    await expect(page.getByTestId('migrate-run')).toBeDisabled();

    await page.getByTestId('migrate-skip').check();
    await expect(page.getByTestId('migrate-summary')).toContainText('tạo mới 2');
    await shot(page, '01-kiem-tra-file');
    await page.getByTestId('migrate-run').click();
    await expect(page.getByTestId('migrate-done')).toContainText('Đã tạo 2');
    await expect(page.getByTestId('migrate-done')).toContainText('1 sản phẩm');
    await shot(page, '02-da-chuyen');
});
