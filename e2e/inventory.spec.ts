import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-2';
const shot = (page: Page, name: string) => baseShot(page, name, DIR);

test.describe.configure({ mode: 'serial' });

test('products: list, create with generated variants, import', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.getByRole('link', { name: 'Sản phẩm' }).first().click();
    await expect(page.getByTestId('product-table')).toBeVisible();
    await page.getByRole('button', { name: 'Xem biến thể' }).first().click();
    await shot(page, '01-products');

    // SKU search finds by variant SKU.
    await page.getByTestId('product-search').fill('QJS-XANH');
    await expect(page.getByTestId('product-table').getByRole('link', { name: 'Quần jean slim fit' })).toBeVisible();
    await page.getByTestId('product-search').fill('');

    await page.getByTestId('new-product').click();
    const suffix = Date.now().toString().slice(-5);
    await page.getByTestId('product-name').fill(`Áo hoodie nỉ ${suffix}`);
    await page.getByTestId('option-values-0').fill('Xám, Đen');
    await page.getByTestId('option-values-1').fill('M, L');
    await page.getByPlaceholder('Tiền tố SKU').fill(`HD${suffix}`);
    await page.getByTestId('generate-variants').click();
    await expect(page.getByTestId('variant-table').locator('tbody tr')).toHaveCount(4);
    await page.getByTestId('variant-table').locator('tbody tr').first().locator('input[type=number]').first().fill('299000');
    await shot(page, '02-product-form');

    // Duplicate SKU is flagged live.
    await page.getByTestId('sku-0').fill('ATC-TRANG-M');
    await expect(page.getByText(/Đã dùng cho/)).toBeVisible();
    await page.getByTestId('sku-0').fill(`HD${suffix}-XAM-M`);
    await page.getByTestId('save-product').click();
    await expect(page).toHaveURL(/\/products\/[0-9A-Z]{26}$/);
    await expect(page.getByText('Đã tạo sản phẩm')).toBeVisible();

    await page.goto('/products');
    await page.getByTestId('import-button').click();
    const csv = ['product_key,product_name,sku,variant_name,ref_price', `VO${suffix},Vớ thể thao,VO${suffix}-TRANG,Trắng,49000`, `VO${suffix},Vớ thể thao,VO${suffix}-DEN,Đen,49000`, `,Sai,,,abc`].join('\n');
    await page.getByTestId('import-file').setInputFiles({ name: 'san-pham.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    await expect(page.getByTestId('import-preview')).toContainText('lỗi');
    await shot(page, '03-import-errors');

    const good = csv.split('\n').slice(0, 3).join('\n');
    await page.getByTestId('import-file').setInputFiles({ name: 'san-pham.csv', mimeType: 'text/csv', buffer: Buffer.from(good) });
    await expect(page.getByTestId('import-preview')).toContainText('File hợp lệ');
    await shot(page, '04-import-preview');
    await page.getByTestId('import-confirm').click();
    await expect(page.getByText(/Đã nhập 2 SKU mới/)).toBeVisible();
});

test('inventory: stock list, stock card, receipt', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/inventory');
    await expect(page.getByTestId('stock-table')).toBeVisible();
    await shot(page, '05-stock-list');

    await page.getByTestId('stock-table').getByRole('link', { name: 'ATC-TRANG-M' }).click();
    await expect(page.getByTestId('movement-table')).toBeVisible();
    await shot(page, '06-stock-card');

    await page.goto('/inventory/receipts/new');
    await page.getByTestId('sku-picker').fill('ATC-DEN');
    await page.getByRole('button', { name: /ATC-DEN-M/ }).click();
    await page.getByTestId('sku-picker').fill('POL-NAVY-XL');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('qty-0').fill('24');
    await page.getByTestId('cost-0').fill('70000');
    await page.getByTestId('qty-1').fill('12');
    await page.getByTestId('cost-1').fill('112000');
    await page.getByLabel('Ghi chú').fill('Nhập hàng đợt 2 tháng 11');
    await shot(page, '07-receipt-form');
    await page.getByTestId('submit-document').click();
    await expect(page.getByRole('heading', { name: /Phiếu nhập kho RCV-/ })).toBeVisible();
    await shot(page, '08-receipt-detail');
});

test('adjustments: staff creates, staff cannot approve, owner approves', async ({ page }) => {
    await login(page, 'staff@demo.test');
    await page.goto('/inventory/adjustments/new');
    await page.getByTestId('sku-picker').fill('QKK-KEM-30');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('qty-0').fill('20');
    await page.getByLabel('Lý do').selectOption('stocktake');
    await page.getByLabel('Ghi chú').fill('Kiểm kê kệ B2');
    await shot(page, '09-adjustment-form');
    await page.getByTestId('submit-document').click();
    await expect(page.getByTestId('document-status')).toHaveText('Chờ duyệt');
    await expect(page.getByTestId('approve')).toHaveCount(0);
    const url = page.url();

    await page.getByRole('button', { name: 'Đăng xuất' }).click();
    await login(page, 'owner@demo.test');
    await page.goto('/inventory/adjustments');
    await expect(page.getByTestId('document-table')).toBeVisible();
    await shot(page, '10-adjustment-list');

    await page.goto(url);
    await shot(page, '11-adjustment-pending');
    await page.getByTestId('approve').click();
    await expect(page.getByTestId('document-status')).toHaveText('Đã duyệt');
    await expect(page.getByTestId('detail-lines')).not.toContainText('Chờ duyệt');
    await shot(page, '12-adjustment-approved');
});

test('transfers and warehouses', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/inventory/transfers');
    await page.getByTestId('document-table').getByText('Đang chuyển').first().click();
    await expect(page.getByTestId('receive')).toBeVisible();
    await shot(page, '13-transfer-in-transit');
    await page.getByTestId('receive').click();
    await expect(page.getByTestId('document-status')).toHaveText('Đã nhận');
    await shot(page, '14-transfer-received');

    await page.goto('/inventory/transfers/new');
    await page.getByTestId('sku-picker').fill('SML-BE-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('qty-0').fill('3');
    await shot(page, '15-transfer-form');

    await page.goto('/inventory/warehouses');
    await expect(page.getByTestId('warehouse-list')).toContainText('Kho Hà Nội');
    await shot(page, '16-warehouses');

    await page.goto('/');
    await expect(page.getByText('SKU sắp hết hàng')).toBeVisible();
    await shot(page, '17-dashboard');
});

test('stock list on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, 'owner@demo.test');
    await page.goto('/inventory');
    await expect(page.getByTestId('stock-table')).toBeVisible();
    await shot(page, '18-stock-mobile');
});
