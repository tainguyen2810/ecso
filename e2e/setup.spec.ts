import { expect, test, type Page } from '@playwright/test';
import { login, shot as baseShot } from './helpers';

/** A2: setup steps for a new business, then the sample store made, shown with its banner and deleted. */
const DIR = 'docs/screenshots/setup';
async function shot(page: Page, name: string) {
    await page.evaluate(() => document.querySelectorAll<HTMLElement>('[role="status"]').forEach((t) => t.click()));
    await baseShot(page, name, DIR);
}

test('a new business goes through the setup steps, then tries the sample store and deletes it', async ({ page }) => {
    test.setTimeout(300_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'owner@demo.test');

    await page.getByTestId('tenant-switcher').click();
    await page.getByRole('link', { name: 'Tạo doanh nghiệp mới' }).click();
    await expect(page.getByTestId('setup')).toBeVisible();

    // 1. Store details: the next button waits for a name and a phone.
    await expect(page.getByTestId('setup-next')).toBeDisabled();
    await page.getByTestId('setup-name').fill('Zz Thời Trang Thiết Lập');
    await page.getByTestId('setup-phone').fill('0909123456');
    await page.getByTestId('setup-industry').getByRole('button', { name: 'Mỹ phẩm' }).click();
    await shot(page, '01-thong-tin');
    await page.getByTestId('setup-next').click();

    // 2. Where it sells: Shopee and Facebook/Zalo, no shop counter, one person.
    await expect(page.getByRole('heading', { name: 'Bạn đang bán hàng ở đâu?' })).toBeVisible();
    await page.getByTestId('setup-channels').getByRole('button', { name: /Shopee/ }).click();
    await page.getByTestId('setup-channels').getByRole('button', { name: /Facebook, Zalo/ }).click();
    await page.getByRole('button', { name: 'Chỉ mình tôi' }).click();
    await shot(page, '02-kenh-ban');
    await page.getByTestId('setup-next').click();

    // 3. Start empty.
    await expect(page.getByRole('heading', { name: 'Bạn muốn bắt đầu thế nào?' })).toBeVisible();
    await shot(page, '03-bat-dau');
    await page.getByRole('radio', { name: /Bắt đầu với cửa hàng trống/ }).click();
    await page.getByTestId('setup-finish').click();

    await expect(page.getByTestId('tenant-switcher')).toContainText('Zz Thời Trang Thiết Lập');
    await expect(page.getByRole('heading', { name: /Xin chào/ })).toBeVisible();
    // No shop counter: the POS pages leave the menu; Shopee comes right after the products in "Bắt đầu".
    await page.getByRole('button', { name: 'Đơn hàng', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Bán tại quầy' })).toHaveCount(0);
    const steps = page.getByText('Kết nối sàn TMĐT');
    await expect(steps.first()).toBeVisible();
    await expect(page.getByText('Mời nhân viên và phân quyền')).toHaveCount(0);
    await shot(page, '04-tong-quan-moi');

    // The sample store, from Cấu hình.
    await page.getByRole('link', { name: 'Cấu hình', exact: true }).click();
    await page.getByRole('link', { name: /Cửa hàng mẫu/ }).click();
    await page.getByTestId('demo-create').click();
    await expect(page.getByTestId('demo-banner')).toBeVisible({ timeout: 240_000 });
    await expect(page.getByTestId('tenant-switcher')).toContainText('Cửa hàng mẫu');
    await expect(page.getByTestId('demo-banner')).toContainText('Dữ liệu giả để bấm thử', { timeout: 120_000 });
    await shot(page, '05-cua-hang-mau');

    await page.getByTestId('demo-delete').click();
    await shot(page, '06-xoa-mau');
    await page.getByTestId('demo-delete-confirm').click();
    await expect(page.getByTestId('demo-banner')).toHaveCount(0);
    await page.getByTestId('tenant-switcher').click();
    await expect(page.getByRole('button', { name: /MẪU/ })).toHaveCount(0);
});
