import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-13';
const shot = (page: Page, name: string) => baseShot(page, name, DIR);

test.describe.configure({ mode: 'serial' });

test('product detail: info, prices, stock per warehouse, history, copy', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/products');
    await page.getByTestId('product-table').getByRole('link', { name: 'Quần jean slim fit' }).click();
    await expect(page.getByTestId('product-info')).toBeVisible();
    await expect(page.getByTestId('stock-by-warehouse')).toBeVisible();
    await shot(page, '01-product-detail');

    await page.getByRole('button', { name: 'Lịch sử kho' }).click();
    await expect(page.getByTestId('product-movements')).toBeVisible();
    await shot(page, '02-product-history');

    await page.getByTestId('copy-product').click();
    await expect(page.getByRole('heading', { name: 'Sao chép sản phẩm' })).toBeVisible();
    await expect(page.getByTestId('product-name')).toHaveValue(/\(bản sao\)$/);
    await expect(page.getByTestId('sku-0')).toHaveValue('');
});

test('create with unit, tags, wholesale price and opening stock', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/products/new');
    const suffix = Date.now().toString().slice(-5);
    await page.getByTestId('product-name').fill(`Nước suối 500ml ${suffix}`);
    await page.getByTestId('product-unit').fill('Chai');
    await page.getByTestId('product-tags').fill('Đồ uống');
    await page.getByTestId('product-tags').press('Enter');
    await page.getByLabel('Thuế GTGT').selectOption('8');
    await page.getByTestId('sku-0').fill(`NS${suffix}`);
    await page.getByLabel('Giá bán lẻ').first().fill('6000');
    await page.getByTestId('wholesale-0').fill('4800');
    await page.getByTestId('opening-qty-0').fill('48');
    await page.getByTestId('opening-cost-0').fill('3500');
    await shot(page, '03-product-form');
    await page.getByTestId('save-product').click();

    await expect(page).toHaveURL(/\/products\/[0-9A-Z]{26}$/);
    await expect(page.getByTestId('product-info')).toContainText('Chai');
    await expect(page.getByTestId('product-info')).toContainText('8%');
    await expect(page.getByTestId('stock-by-warehouse')).toContainText('48');
});

test('list filters, bulk actions and label printing', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/products');
    await page.getByTestId('more-filters').click();
    await page.getByLabel('Tag', { exact: true }).selectOption('Đồ uống');
    await expect(page.getByTestId('product-table').locator('tbody > tr')).toHaveCount(1);
    await expect(page).toHaveURL(/tag=/);
    await shot(page, '04-product-filters');
    await page.getByRole('button', { name: 'Xóa bộ lọc' }).click();

    await page.getByRole('checkbox', { name: 'Chọn tất cả' }).check();
    await expect(page.getByTestId('bulk-print')).toBeVisible();
    await page.getByTestId('bulk-print').click();
    await expect(page).toHaveURL(/\/products\/labels\?products=/);
    await expect(page.getByTestId('label-table')).toBeVisible();
    await expect(page.getByTestId('label-preview').locator('svg')).toBeVisible();
    await shot(page, '05-barcode-labels');

    const download = page.waitForEvent('download');
    await page.getByTestId('download-labels').click();
    expect((await download).suggestedFilename()).toMatch(/^tem-ma-vach-.*\.pdf$/);
});

test('categories and brands', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/products/taxonomy');
    const name = `Phụ kiện ${Date.now().toString().slice(-5)}`;
    await page.getByTestId('categories-form').getByLabel('Tên danh mục').fill(name);
    await page.getByTestId('categories-form').getByRole('button', { name: 'Thêm' }).click();
    await expect(page.getByTestId('categories-list')).toContainText(name);
    await shot(page, '06-taxonomy');
});
