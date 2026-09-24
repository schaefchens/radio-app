import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests against the e2e stack (scripts/e2e-stack.sh): the
 * production build served by Apache with the real .htaccess headers (CSP
 * included), PHP with stub AI, the realtime server and a fake YouTube (the
 * Data API in Docker, the IFrame API shimmed in the page). `npm run e2e` at
 * the repo root starts the stack first.
 *
 * One worker: the tests share one station, and some of them change it.
 */
export default defineConfig({
  testDir: 'tests/e2e',
  timeout: 120_000,
  expect: { timeout: 20_000 },
  workers: 1,
  reporter: [['list']],
  globalSetup: './tests/e2e/global-setup.ts',
  outputDir: 'test-results',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8090',
    // The app registers its worker itself (update banner); the tests want
    // every request to reach the stack.
    serviceWorkers: 'block',
    locale: 'en-US',
    timezoneId: 'Europe/Berlin',
    trace: 'retain-on-failure',
    // A disabled button fails the step, not the whole test's time budget.
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },
  projects: [
    {
      name: 'desktop',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 900 },
        // A fake microphone (a beep) for the recording test.
        launchOptions: { args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] },
      },
    },
  ],
});
