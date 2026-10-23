import { defineConfig, devices } from '@playwright/test';
import { BASE_URL, PORT, REMOTE, ROOT, appEnv, authFile } from './env';

const CI = !!process.env.CI;

/** Brainitive e2e suite (BRAI-96) — see e2e/README.md. */
export default defineConfig({
    testDir: './tests',
    // per port: parallel local runs must not wipe each other's artifacts
    outputDir: `./test-results/${PORT}`,
    globalSetup: './global-setup.ts',
    fullyParallel: true,
    forbidOnly: CI,
    retries: CI ? 1 : 0,
    workers: CI ? 4 : undefined,
    timeout: 45_000,
    expect: { timeout: 10_000 },
    reporter: CI ? [['github'], ['html', { open: 'never', outputFolder: `./playwright-report/${PORT}` }]] : [['list'], ['html', { open: 'never', outputFolder: `./playwright-report/${PORT}` }]],
    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        httpCredentials: process.env.E2E_HTTP_USER
            ? { username: process.env.E2E_HTTP_USER, password: process.env.E2E_HTTP_PASSWORD ?? '' }
            : undefined,
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'chromium',
            testIgnore: /crawl\.spec\.ts/,
            use: { ...devices['Desktop Chrome'], storageState: authFile('owner') },
            dependencies: ['setup'],
        },
        // every button / command (e2e/crawler.ts) — after the journeys, on the data they created
        {
            name: 'crawl',
            testMatch: /crawl\.spec\.ts/,
            use: { ...devices['Desktop Chrome'], storageState: authFile('owner'), permissions: ['clipboard-read', 'clipboard-write'] },
            dependencies: process.env.E2E_CRAWL_ONLY ? ['setup'] : ['chromium'],
        },
        // nightly: --project=firefox / --project=webkit
        { name: 'firefox', testIgnore: /crawl\.spec\.ts/, use: { ...devices['Desktop Firefox'], storageState: authFile('owner') }, dependencies: ['setup'] },
        { name: 'webkit', testIgnore: /crawl\.spec\.ts/, use: { ...devices['Desktop Safari'], storageState: authFile('owner') }, dependencies: ['setup'] },
    ],
    webServer: REMOTE
        ? undefined
        : {
            // --no-reload: pass the e2e environment through instead of re-reading .env
            command: `php artisan serve --no-reload --host=127.0.0.1 --port=${PORT}`,
            cwd: ROOT,
            env: appEnv(),
            url: `${BASE_URL}/up`,
            reuseExistingServer: !CI,
            timeout: 60_000,
        },
});
