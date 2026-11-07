import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

/** Carrier connections with the built-in test carrier ("Hãng giả lập"): connect, compare prices, push, print, cancel. */
const DIR = 'docs/screenshots/carriers';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

async function newOrderWithAddress(page: Page, name: string) {
    await page.goto('/orders/new');
    await page.getByTestId('sku-picker').fill('ATC-TRANG-M');
    await page.getByTestId('sku-picker').press('Enter');
    await page.getByTestId('new-customer').click();
    await page.getByTestId('nc-name').fill(name);
    await page.getByTestId('nc-phone').fill(`09${Date.now().toString().slice(-8)}`);
    await page.locator('#nc-addr-quick').fill('20 Pasteur, P. Bến Nghé, Quận 1, TP.HCM');
    await page.locator('#nc-addr-quick').press('Enter');
    await expect(page.locator('#nc-addr-ward')).toHaveValue('Phường Sài Gòn');
    await page.getByTestId('nc-save').click();
    await expect(page.getByTestId('customer-delivery')).toContainText('20 Pasteur');
}

test('carriers: connect in one step, push from the order form and from the waiting list, then print and cancel', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'owner@demo.test');

    // Connect (one step): pick the carrier, paste the token.
    await page.goto('/shipments');
    await expect(page.getByTestId('push-queue')).toBeVisible();
    await page.getByTestId('open-carrier-connect').click();
    await page.getByTestId('carrier-pick-fake').click();
    await page.getByTestId('carrier-cred-token').fill('demo-token');
    await page.getByTestId('carrier-connect').click();
    await expect(page.getByTestId('carrier-accounts')).toContainText('Tài khoản giả lập');
    await shot(page, '01-ket-noi-hang');
    await page.getByRole('button', { name: 'Đóng', exact: true }).first().click();
    await expect(page.getByTestId('carrier-chips')).toContainText('Hãng giả lập');

    // Order form: "Đẩy qua hãng vận chuyển" with prices next to the parcel.
    await newOrderWithAddress(page, 'Trần Thị Mai');
    await page.getByTestId('delivery-api').check();
    await page.getByTestId('api-cod').check();
    await expect(page.getByTestId('quote-cheapest')).toContainText('Hãng giả lập');
    await page.getByTestId('quote-row-express').click();
    await shot(page, '02-tao-don-day-qua-hang');
    await page.getByTestId('submit-order').click();
    await expect(page.getByTestId('order-status')).toHaveText('Chờ đóng gói');
    await expect(page.getByTestId('order-shipments')).toContainText('FAKE');
    await expect(page.getByTestId('order-shipments')).toContainText('Nhanh');
    await shot(page, '03-don-da-day');

    // Two more orders to ship later, then pushed together from "Chờ đẩy".
    const codes: string[] = [];
    for (const name of ['Lê Văn Hùng', 'Phạm Thu Hà']) {
        await newOrderWithAddress(page, name);
        await page.getByTestId('submit-order').click();
        await expect(page.getByTestId('order-status')).toHaveText('Chờ xác nhận');
        codes.push(((await page.locator('h1').first().textContent()) ?? '').replace('Đơn', '').trim());
    }
    await page.goto('/shipments');
    const rows = page.getByTestId('queue-row');
    for (const code of codes) await expect(rows.filter({ hasText: code }).getByTestId('queue-chip-fake')).toBeVisible();
    await page.getByTestId('quick-cheapest').click();
    for (const code of codes) await rows.filter({ hasText: code }).getByRole('checkbox').check();
    await expect(page.getByTestId('queue-bulk-bar')).toContainText('Đã chọn 2 đơn');
    await shot(page, '04-cho-day-so-sanh-gia');
    await page.getByTestId('queue-push').click();
    for (const code of codes) await expect(rows.filter({ hasText: code })).toHaveCount(0);

    // The pushed parcels wait for pickup; one is cancelled at the carrier from the drawer.
    await page.goto('/shipments?tab=awaiting');
    await page.getByTestId('shipment-search').fill(codes[0]!);
    await page.getByTestId('shipment-table').locator('tbody tr', { hasText: codes[0]! }).locator('td').nth(1).click();
    const drawer = page.getByTestId('shipment-drawer');
    await expect(drawer.getByTestId('drawer-carrier-actions')).toBeVisible();
    await expect(drawer.getByTestId('shipment-action-cancel')).toHaveCount(0);
    await shot(page, '05-van-don-thao-tac-tren-hang');
    page.once('dialog', (d) => d.accept());
    await drawer.getByTestId('drawer-carrier-cancel').click();
    await expect(drawer.getByTestId('drawer-status')).toHaveText('Hủy vận đơn');
});
