import { defineConfig } from '@playwright/test';

const php = process.env.TEAMI_PHP_BINARY || 'php';
if (!/^[a-zA-Z0-9/_.-]+$/.test(php)) throw new Error('Invalid PHP executable path.');

export default defineConfig({
    webServer: process.env.TEAMI_UI_START_SERVER ? { command: `${php} artisan serve --host=127.0.0.1 --port=8000`, url: 'http://127.0.0.1:8000', reuseExistingServer: false } : undefined,
    testDir: './tests/browser',
    timeout: 30000,
    fullyParallel: false,
    use: {
        baseURL: process.env.TEAMI_UI_URL || 'http://127.0.0.1:8000',
        headless: true,
        viewport: { width: 1440, height: 1000 },
        launchOptions: process.env.TEAMI_CHROMIUM_PATH ? { executablePath: process.env.TEAMI_CHROMIUM_PATH } : {},
    },
    reporter: 'list',
});
