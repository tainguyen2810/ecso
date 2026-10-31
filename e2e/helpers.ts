import { createHmac } from 'node:crypto';
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

/** Base32 TOTP secret of admin@demo.test (DemoSeeder::DEMO_ADMIN_TOTP_SECRET, demo data only). */
export const DEMO_ADMIN_TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/** RFC 6238 code (SHA-1, 6 digits, 30 s) for the given time step offset. */
export function totp(secret: string, stepOffset = 0, now = Date.now()): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const ch of secret.replace(/\s+/g, '').toUpperCase()) bits += alphabet.indexOf(ch).toString(2).padStart(5, '0');
    const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((b) => parseInt(b, 2)));
    const counter = Math.floor(now / 1000 / 30) + stepOffset;
    const msg = Buffer.alloc(8);
    msg.writeUInt32BE(Math.floor(counter / 2 ** 32), 0);
    msg.writeUInt32BE(counter >>> 0, 4);
    const hash = createHmac('sha1', key).update(msg).digest();
    const offset = hash[19] & 0x0f;
    const value = ((hash[offset] & 0x7f) << 24) | (hash[offset + 1] << 16) | (hash[offset + 2] << 8) | hash[offset + 3];
    return String(value % 1_000_000).padStart(6, '0');
}

/**
 * Logs in as a super admin and passes the 2FA screen. A code can be used once per time step, so when the current
 * step was already used by an earlier test, the next step's code (accepted within the ±1 window) is tried.
 */
export async function loginAdmin(page: Page, email = 'admin@demo.test', secret = DEMO_ADMIN_TOTP_SECRET) {
    await login(page, email);
    await page.waitForURL(/\/admin\/2fa/);
    for (const offset of [0, 1, -1]) {
        await page.getByTestId('totp-code').fill(totp(secret, offset));
        await page.getByTestId('submit-2fa').click();
        try {
            await page.waitForURL((url) => !url.pathname.startsWith('/admin/2fa'), { timeout: 3000 });
            return;
        } catch {
            // Code already used in this step: try the next one.
        }
    }
    throw new Error('Could not pass 2FA');
}
