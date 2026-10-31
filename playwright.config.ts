import { defineConfig, devices } from '@playwright/test';

/**
 * E2E runs against a local server seeded with DemoSeeder (no marketplace calls):
 *   php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder
 *   php artisan serve --port=8000
 *   npx playwright test
 */
export default defineConfig({
    testDir: './e2e',
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
        viewport: { width: 1440, height: 900 },
        locale: 'vi-VN',
        trace: 'retain-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } }],
});
