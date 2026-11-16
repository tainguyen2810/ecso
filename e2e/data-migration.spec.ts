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

test('data migration: a Sapo file with two branches, and the Sapo GO bookmark file', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'owner@demo.test');
    await page.goto('/settings/data-migration');

    const stamp = Date.now().toString().slice(-6);
    const csv = [
        'Tên sản phẩm,Mã SKU,Barcode,Mô tả sản phẩm,Tags,PL_Giá bán lẻ,PL_Giá nhập,LC_Kho chính_Tồn kho ban đầu,LC_Kho chính_Giá vốn khởi tạo,LC_Chi nhánh Hà Nội_Tồn kho ban đầu',
        `Cáp HDMI 2m,SP${stamp} A,,<p>Dây bọc dù</p>,"phụ kiện, cáp","150,000","90,000",12,"88,000",5`,
        `Chuột không dây,SP${stamp}B,,,,"250,000","160,000",4,,2`,
    ].join('\n');
    await page.getByTestId('migrate-file').setInputFiles({ name: 'sapo-san-pham.csv', mimeType: 'text/csv', buffer: Buffer.from('﻿' + csv, 'utf-8') });
    await expect(page.getByTestId('migrate-source')).toHaveText('File Sapo');
    await expect(page.getByTestId('migrate-branches')).toContainText('Chi nhánh Hà Nội');
    await expect(page.getByTestId('migrate-summary')).toContainText('tạo mới 2');
    await shot(page, '03-sapo-chi-nhanh');
    await page.getByTestId('migrate-run').click();
    await expect(page.getByTestId('migrate-done')).toContainText('Đã tạo 2');

    await page.getByTestId('migrate-how-sapo').click();
    await expect(page.getByTestId('migrate-bookmarklet')).toHaveAttribute('href', /^javascript:/);
    const file = {
        format: 'ezsell-sapo-go', version: 1, locations: [{ id: 1, name: 'Chi nhánh mặc định' }],
        products: [{ id: 9, name: 'Combo 2 cáp', product_type: 'composite', status: 'active', images: [],
            variants: [{ sku: `CB${stamp}`, opts: ['Mặc định'], status: 'active', retail: 280000, composite_items: [{ sku: `SP${stamp}B`, quantity: 2 }], stock: [] }] },
        { id: 10, name: 'Bàn di chuột', product_type: 'normal', status: 'active', images: [],
            variants: [{ sku: `BD${stamp}`, opts: ['Mặc định'], status: 'active', retail: 90000, import: 40000, stock: [{ location_id: 1, on_hand: 20, mac: 41000 }] }] }],
    };
    await page.getByTestId('migrate-file').setInputFiles({ name: 'sapo-du-lieu-itso.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(file), 'utf-8') });
    await expect(page.getByTestId('migrate-summary')).toContainText('tạo mới 2');
    await expect(page.getByTestId('migrate-branches')).toContainText('Chi nhánh mặc định');
    await shot(page, '04-sapo-go-bookmark');
    await page.getByTestId('migrate-run').click();
    await expect(page.getByTestId('migrate-done')).toContainText('Đã tạo 2');
});
