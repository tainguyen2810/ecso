import { expect, type Page } from '@playwright/test';

export const SHOTS = 'docs/screenshots/phase-1';

export async function login(page: Page, email: string, password = 'password1') {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Mật khẩu').fill(password);
    await page.getByRole('button', { name: 'Đăng nhập' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

export async function shot(page: Page, name: string, dir: string = SHOTS) {
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.animate-pulse')).toHaveCount(0);
    await page.screenshot({ path: `${dir}/${name}.png`, fullPage: true });
}
