import { expect, test } from '@playwright/test';
import { login, shot } from './helpers';

const DIR = 'docs/screenshots/cash-cod';

test('COD reconciliation: import a carrier file, preview line by line, book it', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/cash/cod');
    await page.getByTestId('cod-import').click();
    await expect(page.getByTestId('cod-import-dialog')).toBeVisible();
    await page.getByTestId('cod-carrier').selectOption('ghn');

    // A GHN-like statement: one parcel the shop does not know, fees only.
    const csv = 'Mã đơn GHN,Người nhận,Tiền thu hộ (COD),Tổng phí\nGHNE2E0001,Khách A,250.000,22.000\n';
    await page.getByTestId('cod-file').setInputFiles({ name: 'doi-soat-ghn.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf-8') });
    await expect(page.getByTestId('cod-preview')).toContainText('Không thấy đơn');
    await expect(page.getByTestId('cod-totals')).toContainText('228.000');
    await shot(page, '01-xem-truoc', DIR);

    await page.getByTestId('cod-confirm').click();
    await expect(page.getByTestId('cod-detail')).toBeVisible();
    await expect(page.getByTestId('cod-detail')).toContainText('GHNE2E0001');
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('cod-table')).toContainText('DS-000001');
    await shot(page, '02-danh-sach', DIR);

    // Imported by mistake: void the whole statement, its fee voucher goes with it.
    await page.getByTestId('cod-table').getByText('DS-000001').click();
    await page.getByTestId('cod-void').click();
    await page.getByTestId('cod-void-reason').fill('Nhập nhầm file');
    await shot(page, '03-huy-phien', DIR);
    await page.getByTestId('cod-void-confirm').click();
    await expect(page.getByTestId('cod-voided')).toContainText('Nhập nhầm file');
    await shot(page, '04-phien-da-huy', DIR);
});
