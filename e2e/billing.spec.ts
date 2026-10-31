import { expect, test, type Page } from '@playwright/test';
import { login, loginAdmin, shot as baseShot } from './helpers';

const DIR = 'docs/screenshots/phase-6';
const shot = (page: Page, name: string) => baseShot(page, name, DIR);

test.describe.configure({ mode: 'serial' });

test('tenant upgrades by bank transfer, admin confirms, plan and entitlements change', async ({ browser }) => {
    const tenantPage = await browser.newPage();
    await login(tenantPage, 'owner@demo.test');
    await tenantPage.goto('/subscription');
    await expect(tenantPage.getByTestId('current-plan')).toContainText('Starter');
    await expect(tenantPage.getByTestId('usage')).toContainText('Đơn mỗi tháng');
    await shot(tenantPage, '01-subscription');

    // Upgrade to Pro: the quote credits the unused days of the paid Starter period.
    await tenantPage.getByTestId('choose-pro').click();
    await expect(tenantPage.getByTestId('quote')).toContainText('Trừ thời gian chưa dùng');
    await tenantPage.screenshot({ path: `${DIR}/02-upgrade-quote.png` });
    await tenantPage.getByTestId('confirm-change').click();

    const invoice = tenantPage.getByTestId('open-invoice');
    await expect(invoice).toBeVisible();
    await expect(tenantPage.getByTestId('bank-instructions')).toBeVisible();
    await expect(tenantPage.getByTestId('current-plan')).toContainText('Starter'); // not changed until paid
    const code = (await tenantPage.getByTestId('transfer-code').textContent())?.trim() ?? '';
    expect(code).toMatch(/^ECSO[A-Z0-9]{6}$/);
    await invoice.scrollIntoViewIfNeeded();
    await shot(tenantPage, '03-invoice-bank-transfer');

    // Advanced reports are not in Starter.
    await tenantPage.goto('/reports');
    await expect(tenantPage.getByText('Cần gói có Báo cáo nâng cao')).toBeVisible();

    // Super admin confirms the transfer seen on the bank statement.
    const adminPage = await browser.newPage();
    await loginAdmin(adminPage);
    await adminPage.goto('/admin/billing');
    await expect(adminPage.getByTestId('billing-summary')).toContainText('MRR');
    await adminPage.getByTestId('invoice-search').fill(code);
    await expect(adminPage.getByTestId('admin-invoices')).toContainText(code);
    await shot(adminPage, '04-admin-billing');
    await adminPage.getByTestId('confirm-payment').first().click();
    const reference = `FT${Date.now()}`;
    await adminPage.getByTestId('payment-reference').fill(reference);
    await adminPage.screenshot({ path: `${DIR}/05-admin-confirm-payment.png` });
    await adminPage.getByTestId('submit-payment').click();
    await expect(adminPage.getByText('Đã ghi nhận: Đã ghi nhận.')).toBeVisible();

    await tenantPage.goto('/subscription');
    await expect(tenantPage.getByTestId('current-plan')).toContainText('Pro');
    await expect(tenantPage.getByTestId('open-invoice')).toHaveCount(0);
    await expect(tenantPage.getByTestId('invoices')).toContainText('Đã thanh toán');

    await tenantPage.goto('/reports');
    await expect(tenantPage.getByTestId('slow-movers')).not.toContainText('Cần gói có Báo cáo nâng cao');
    await shot(tenantPage, '06-reports');
});

test('finance dashboard separates revenue from money received', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await page.goto('/finance');
    await expect(page.getByTestId('finance-kpis')).toContainText('Doanh thu đặt hàng');
    await expect(page.getByTestId('fees-unknown')).toContainText('Chưa xác định');
    await expect(page.getByText('không phải tiền thực nhận')).toBeVisible();
    await shot(page, '07-finance');
});
