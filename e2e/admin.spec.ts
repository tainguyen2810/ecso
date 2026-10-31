import { expect, test } from '@playwright/test';
import { login, loginAdmin, shot, totp } from './helpers';

test('super admin uses the admin console', async ({ page }) => {
    await loginAdmin(page);
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByText('Tổng tenant')).toBeVisible();
    await shot(page, '18-admin-dashboard');

    await page.getByRole('link', { name: 'Khách hàng', exact: true }).click();
    await expect(page.getByRole('cell', { name: 'Cửa hàng Mẫu' })).toBeVisible();
    await shot(page, '19-admin-tenants');
});

test('tenant users are sent away from the admin console', async ({ page }) => {
    await login(page, 'owner@demo.test');
    await expect(page.getByRole('heading', { name: /Xin chào/ })).toBeVisible();
    await page.goto('/admin');
    await expect(page).not.toHaveURL(/\/admin/);
});

test('a super admin must set up 2FA on first login, then sees the monitoring page', async ({ page }) => {
    await login(page, 'security@demo.test');
    await page.waitForURL(/\/admin\/2fa/);
    const secretEl = page.getByTestId('totp-secret');
    await expect(secretEl).toBeVisible();
    await expect(page.getByAltText('Mã QR cài đặt xác thực hai bước')).toBeVisible();
    await page.screenshot({ path: 'docs/screenshots/phase-8/01-admin-2fa-setup.png' });

    const secret = (await secretEl.getAttribute('data-secret')) ?? '';
    await page.getByTestId('totp-code').fill('000000');
    await page.getByTestId('submit-2fa').click();
    await expect(page.getByText('Mã xác thực không đúng hoặc đã dùng.')).toBeVisible();

    await page.getByTestId('totp-code').fill(totp(secret));
    await page.getByTestId('submit-2fa').click();
    await expect(page.getByTestId('recovery-codes').locator('li')).toHaveCount(8);
    await page.screenshot({ path: 'docs/screenshots/phase-8/02-admin-2fa-recovery-codes.png' });
    await page.getByTestId('finish-setup').click();
    await expect(page).toHaveURL(/\/admin$/);

    await page.getByRole('link', { name: 'Giám sát hệ thống' }).click();
    await expect(page.getByTestId('monitor-status')).toBeVisible();
    await expect(page.getByTestId('queues')).toContainText('webhooks');
    await shot(page, '03-admin-monitoring', 'docs/screenshots/phase-8');
});

test('the admin API refuses a session that has not passed 2FA', async ({ page }) => {
    await login(page, 'admin@demo.test');
    await page.waitForURL(/\/admin\/2fa/);
    await page.screenshot({ path: 'docs/screenshots/phase-8/04-admin-2fa-verify.png' });
    const res = await page.request.get('/api/v1/admin/tenants', { headers: { Accept: 'application/json', Referer: 'http://127.0.0.1:8000/admin' } });
    expect(res.status()).toBe(403);
    expect((await res.json()).code).toBe('TWO_FACTOR_REQUIRED');
});
