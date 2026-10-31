import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';

/**
 * Runs only against a fresh, uninstalled copy of the app (no .env), see scripts/e2e-install.sh:
 *   INSTALL_BASE_URL  where the fresh copy is served
 *   INSTALL_TOKEN_FILE  storage/app/install-token.txt of that copy
 *   INSTALL_DB_*  an empty MySQL database for it
 */
const base = process.env.INSTALL_BASE_URL;
test.skip(!base, 'needs a fresh uninstalled copy (scripts/e2e-install.sh)');

const shot = (name: string) => `docs/screenshots/install/${name}.png`;

test('installs a fresh upload through the web wizard', async ({ page }) => {
    test.setTimeout(120_000);

    await page.goto(`${base}/`);
    await expect(page).toHaveURL(/\/install$/);
    await expect(page.getByTestId('token-file')).toContainText('install-token.txt');
    await page.screenshot({ path: shot('01-token'), fullPage: true });

    // A wrong token is refused.
    await page.getByTestId('install-token').fill('0'.repeat(32));
    await page.getByTestId('submit-token').click();
    await expect(page.getByTestId('install-error')).toContainText('Mã cài đặt không đúng');

    const token = readFileSync(process.env.INSTALL_TOKEN_FILE!, 'utf8').trim();
    await page.getByTestId('install-token').fill(token);
    await page.getByTestId('submit-token').click();
    await expect(page.getByTestId('requirements')).toBeVisible();
    await page.screenshot({ path: shot('02-requirements'), fullPage: true });
    await page.getByTestId('to-database').click();

    await page.getByTestId('db-host').fill(process.env.INSTALL_DB_HOST ?? '127.0.0.1');
    await page.getByTestId('db-port').fill(process.env.INSTALL_DB_PORT ?? '3306');
    await page.getByTestId('db-name').fill(process.env.INSTALL_DB_DATABASE!);
    await page.getByTestId('db-user').fill(process.env.INSTALL_DB_USERNAME!);
    await page.getByTestId('db-password').fill('wrong-password');
    await page.getByTestId('test-db').click();
    await expect(page.getByTestId('db-check')).toContainText('Không kết nối được');
    await expect(page.getByTestId('to-redis')).toBeDisabled();

    await page.getByTestId('db-password').fill(process.env.INSTALL_DB_PASSWORD ?? '');
    await page.getByTestId('test-db').click();
    await expect(page.getByTestId('db-check')).toContainText('Kết nối được MySQL');
    await page.screenshot({ path: shot('03-database'), fullPage: true });
    await page.getByTestId('to-redis').click();

    await page.getByTestId('test-redis').click();
    await expect(page.getByTestId('redis-check')).toContainText('Kết nối được Redis');
    await page.screenshot({ path: shot('04-redis'), fullPage: true });
    await page.getByTestId('to-site').click();

    await page.getByTestId('site-name').fill('Shop Demo');
    await page.screenshot({ path: shot('05-site'), fullPage: true });
    await page.getByTestId('to-admin').click();

    await page.getByTestId('admin-name').fill('Quản trị');
    await page.getByTestId('admin-email').fill('root@wizard.test');
    await page.getByTestId('admin-password').fill('short');
    await expect(page.getByTestId('run-install')).toBeDisabled();
    await page.getByTestId('admin-password').fill('Wizard-pass-2026');
    await page.getByTestId('admin-password2').fill('Wizard-pass-2026');
    await page.screenshot({ path: shot('06-admin'), fullPage: true });
    await page.getByTestId('run-install').click();

    await expect(page.getByTestId('install-done')).toBeVisible({ timeout: 90_000 });
    await expect(page.getByTestId('worker-command').first()).toContainText('artisan horizon');
    await page.screenshot({ path: shot('07-done'), fullPage: true });

    // The wizard has locked itself and the app now serves the real login page.
    await page.goto(`${base}/install`);
    await expect(page.getByTestId('already-installed')).toBeVisible();
    await page.goto(`${base}/login`);
    await page.getByLabel('Email').fill('root@wizard.test');
    await page.getByLabel('Mật khẩu').fill('Wizard-pass-2026');
    await page.getByRole('button', { name: 'Đăng nhập' }).click();
    await expect(page).toHaveURL(/\/admin\/2fa/, { timeout: 15_000 });
    await page.screenshot({ path: shot('08-first-login-2fa'), fullPage: true });
});
