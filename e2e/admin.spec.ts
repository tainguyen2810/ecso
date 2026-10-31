import { expect, test } from '@playwright/test';
import { login, shot } from './helpers';

test('super admin uses the admin console', async ({ page }) => {
    await login(page, 'admin@demo.test');
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
