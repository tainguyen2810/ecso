import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-11';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test.describe.configure({ mode: 'serial' });

test('purchasing: order, receive in parts, pay, supplier debt', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.getByRole('link', { name: 'Sản phẩm' }).first().click();
    await page.getByRole('link', { name: 'Nhập hàng', exact: true }).first().click();
    await expect(page.getByTestId('po-table').locator('tbody tr')).toHaveCount(4);
    await shot(page, '01-purchase-orders');

    // New order for Minh Long.
    await page.getByTestId('new-po').click();
    await page.getByTestId('po-supplier').selectOption({ label: 'NCC-000001 · Xưởng May Minh Long' });
    await page.getByTestId('sku-picker').fill('ATC-TRANG-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('sku-picker').fill('QKK-REU-30');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('po-qty-0').fill('20');
    await page.getByTestId('po-cost-0').fill('60000');
    await page.getByTestId('po-qty-1').fill('10');
    await page.getByTestId('po-cost-1').fill('90000');
    await expect(page.getByTestId('po-total')).toContainText('2.100.000');
    await shot(page, '02-purchase-order-form');
    await page.getByTestId('po-place').click();
    await expect(page.getByTestId('po-status')).toHaveText('Đã đặt hàng');

    // First delivery: 12 shirts at a higher price, no polos yet.
    await page.getByTestId('po-receive').click();
    await page.getByTestId('rcv-qty-0').fill('12');
    await page.getByTestId('rcv-cost-0').fill('62000');
    await page.getByTestId('rcv-qty-1').fill('0');
    await shot(page, '03-receive-dialog');
    await page.getByTestId('rcv-save').click();
    await expect(page.getByTestId('po-status')).toHaveText('Nhận một phần');
    await expect(page.getByTestId('po-received-value')).toContainText('744.000');

    // Pay part of what is owed.
    await page.getByTestId('po-pay').click();
    await page.getByTestId('sp-amount').fill('500000');
    await page.getByTestId('sp-save').click();
    await expect(page.getByTestId('po-due')).toContainText('244.000');
    await shot(page, '04-purchase-order-partial');

    // Supplier list and detail with the running debt.
    await page.goto('/purchasing/suppliers');
    await expect(page.getByTestId('supplier-table')).toContainText('Xưởng May Minh Long');
    await shot(page, '05-suppliers');
    await page.getByTestId('supplier-table').getByText('Xưởng May Minh Long').click();
    await expect(page.getByTestId('supplier-debt')).toBeVisible();
    await shot(page, '06-supplier-detail');
});

test('purchasing: return to supplier and reorder suggestions', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/purchasing?status=received');
    await page.getByTestId('po-table').locator('tbody tr').first().getByRole('link').first().click();
    await page.getByTestId('po-return').click();
    await page.getByTestId('ret-qty-0').fill('1');
    await page.getByTestId('ret-reason').fill('Sai màu');
    await page.getByTestId('ret-save').click();
    await expect(page.getByText(/Đã xuất trả TNC-/)).toBeVisible();
    await shot(page, '07-purchase-order-returned');

    await page.goto('/purchasing/reorder');
    await expect(page.getByRole('heading', { name: 'Gợi ý nhập hàng' })).toBeVisible();
    await page.waitForLoadState('networkidle');
    await shot(page, '08-reorder-suggestions');
});

test('purchasing: VAT, purchase costs in the landed cost, printed order', async ({ page }) => {
    const dir = 'docs/screenshots/purchase-costs';
    await login(page, 'owner@demo.test');
    await page.goto('/purchasing/new');
    await page.getByTestId('po-supplier').selectOption({ label: 'NCC-000002 · Công ty TNHH Phụ Kiện An Phát' });
    await page.getByLabel('VAT mặc định cho dòng mới').selectOption('10');
    for (const sku of ['ATC-TRANG-M', 'QKK-REU-30']) {
        await page.getByTestId('sku-picker').fill(sku);
        await page.getByTestId('sku-picker').press('Enter');
    }
    await page.getByTestId('po-qty-0').fill('10');
    await page.getByTestId('po-cost-0').fill('100000');
    await page.getByTestId('po-qty-1').fill('20');
    await page.getByTestId('po-cost-1').fill('50000');
    await page.getByTestId('po-vat-1').selectOption('8');
    await page.getByTestId('po-extra-cost').fill('200000');
    await expect(page.getByTestId('po-tax')).toContainText('180.000');
    await expect(page.getByTestId('po-total')).toContainText('2.180.000');
    await expect(page.getByTestId('po-landed-0')).toHaveText('110.000');
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, '01-po-form-vat-costs', dir);
    await page.getByTestId('po-place').click();
    await expect(page.getByTestId('po-status')).toHaveText('Đã đặt hàng');
    await expect(page.getByTestId('po-cost-summary')).toContainText('2.180.000');

    await baseShot(page, '02-po-detail-vat-costs', dir);

    // The printed order: grab the print frame before it is removed and render it on its own page.
    await page.getByTestId('po-print').click();
    await expect.poll(async () => (await Promise.all(page.frames().map((f) => f.content().catch(() => '')))).some((c) => c.includes('ĐƠN ĐẶT HÀNG')), { timeout: 3000 }).toBeTruthy();
    const printed = (await Promise.all(page.frames().map((f) => f.content().catch(() => '')))).find((c) => c.includes('ĐƠN ĐẶT HÀNG'))!;
    expect(printed).toContain('An Phát');
    const sheet = await page.context().newPage();
    await sheet.setViewportSize({ width: 794, height: 1123 });
    await sheet.setContent(printed);
    await sheet.screenshot({ path: `${dir}/04-printed-purchase-order.png`, fullPage: true });
    await sheet.close();

    await page.getByTestId('po-receive').click();
    await page.getByTestId('rcv-save').click();
    await expect(page.getByTestId('po-status')).toHaveText('Đã nhận đủ');
    await expect(page.getByTestId('po-due')).toContainText('2.180.000');
    await expect(page.getByTestId('po-cost-summary')).toContainText('200.000');
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, '03-po-received-landed-cost', dir);
});

test('purchasing like Sapo: discount, invoice details, Tạo & nhập hàng with payment, Đặt hàng nhập list', async ({ page }) => {
    const dir = 'docs/screenshots/purchase-sapo';
    await login(page, 'owner@demo.test');
    await page.goto('/purchasing/new');
    await page.getByTestId('po-supplier').selectOption({ label: 'NCC-000001 · Xưởng May Minh Long' });
    await expect(page.getByTestId('po-supplier-card')).toContainText('Công nợ');
    await page.getByTestId('po-invoice-date').fill('2026-11-04');
    await page.getByTestId('po-reference').fill('HĐ 0001254');
    await page.getByTestId('sku-picker').fill('ATC-TRANG-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('po-qty-0').fill('10');
    await page.getByTestId('po-cost-0').fill('60000');
    await page.getByTestId('po-discount').fill('50000');
    await expect(page.getByTestId('po-total')).toContainText('550.000');
    await expect(page.getByTestId('po-landed-0')).toHaveText('55.000');
    await page.getByTestId('po-pay-amount').fill('500000');
    await expect(page.getByTestId('po-remaining')).toContainText('50.000');
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, '01-po-form', dir);
    await page.getByTestId('po-create-receive').click();

    await expect(page.getByTestId('po-status')).toHaveText('Đã nhận đủ');
    await expect(page.getByTestId('po-steps')).toContainText('Hoàn thành');
    await expect(page.getByTestId('po-due')).toContainText('50.000');
    await expect(page.getByTestId('po-info')).toContainText('HĐ 0001254');
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, '02-po-detail', dir);

    await page.goto('/purchasing');
    await page.getByTestId('po-search').fill('0001254');
    await expect(page.getByTestId('po-table').locator('tbody tr')).toHaveCount(1);
    await page.getByTestId('po-search').fill('');
    await baseShot(page, '03-po-list', dir);

    await page.goto('/purchasing/orders');
    await expect(page.getByRole('heading', { name: 'Đặt hàng nhập' })).toBeVisible();
    await page.getByTestId('new-po').click();
    await expect(page.getByTestId('po-create-receive')).toHaveCount(0);
    await expect(page.getByTestId('po-place')).toContainText('Đặt hàng');
    await baseShot(page, '04-ordering-form', dir);
});
