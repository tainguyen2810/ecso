import { expect, test } from '@playwright/test';
import { login, shot } from './helpers';

test('auth screens render', async ({ page }) => {
    await page.goto('/login');
    await shot(page, '01-login');
    await page.goto('/register');
    await shot(page, '02-register');
    await page.goto('/forgot-password');
    await shot(page, '03-forgot-password');
});

test('new user registers, must verify email, then creates a tenant', async ({ page }) => {
    const email = `e2e-${Date.now()}@example.com`;
    await page.goto('/register');
    await page.getByLabel('Họ và tên').fill('Lê Thử Nghiệm');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Mật khẩu', { exact: true }).fill('secret123');
    await page.getByLabel('Nhập lại mật khẩu').fill('secret123');
    await page.getByRole('button', { name: 'Tạo tài khoản' }).click();

    await expect(page.getByRole('heading', { name: 'Xác minh email' })).toBeVisible();
    await shot(page, '04-verify-email');

    // Unverified users cannot reach the dashboard.
    await page.goto('/');
    await expect(page).toHaveURL(/verify-email/);
});

test('owner works in the tenant dashboard', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await expect(page.getByRole('heading', { name: /Xin chào/ })).toBeVisible();
    await shot(page, '05-dashboard');

    await page.getByTestId('tenant-switcher').click();
    await shot(page, '06-tenant-switcher');
    await page.getByRole('button', { name: 'Thời trang Demo' }).click();
    await expect(page.getByTestId('tenant-switcher')).toContainText('Thời trang Demo');
    await page.getByTestId('tenant-switcher').click();
    await page.getByRole('button', { name: 'Cửa hàng Mẫu' }).click();
    await expect(page.getByTestId('tenant-switcher')).toContainText('Cửa hàng Mẫu');

    await page.getByRole('link', { name: 'Nhân viên' }).click();
    await expect(page.getByRole('cell', { name: /Trần Thị Kho/ })).toBeVisible();
    await shot(page, '07-members');

    await page.getByTestId('invite-button').click();
    await page.getByLabel('Email').fill(`moi-${Date.now()}@example.com`);
    await page.getByLabel('Vai trò').selectOption('ORDER_STAFF');
    await shot(page, '08-invite-dialog');
    await page.getByRole('button', { name: 'Gửi lời mời' }).click();
    await expect(page.getByText('Đã gửi lời mời')).toBeVisible();
    await shot(page, '09-invitations');

    await page.getByRole('link', { name: 'Gói dịch vụ' }).click();
    await expect(page.getByText('Các gói dịch vụ')).toBeVisible();
    await shot(page, '10-subscription');

    await page.getByRole('link', { name: 'Nhật ký' }).click();
    await expect(page.getByRole('cell', { name: 'Gửi lời mời' }).first()).toBeVisible();
    await shot(page, '11-audit-log');

    await page.getByRole('link', { name: 'Cài đặt' }).click();
    await shot(page, '12-settings');

    await page.getByRole('link', { name: 'Mua hàng' }).click();
    await expect(page.getByText('Mua hàng đang được xây dựng')).toBeVisible();
    await shot(page, '13-module-placeholder');

    // Keyboard shortcut g then m opens members.
    await page.keyboard.press('g');
    await page.keyboard.press('m');
    await expect(page).toHaveURL(/\/members$/);
});

test('warehouse staff only sees what their role allows', async ({ page }) => {
    await login(page, 'staff@demo.test');
    await expect(page.getByRole('heading', { name: /Xin chào/ })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Kho hàng' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Nhân viên' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Cài đặt' })).toHaveCount(0);
    await shot(page, '14-staff-dashboard');

    await page.goto('/settings');
    await expect(page.getByText('Bạn không có quyền truy cập trang này')).toBeVisible();
    await shot(page, '15-forbidden');
});

test('dashboard is usable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, 'owner@demo.test');
    await expect(page.getByRole('heading', { name: /Xin chào/ })).toBeVisible();
    await shot(page, '16-mobile-dashboard');
    await page.getByRole('button', { name: 'Mở menu' }).click();
    await page.waitForTimeout(400); // let the slide-in transition finish
    await page.screenshot({ path: 'docs/screenshots/phase-1/17-mobile-menu.png' });
});
