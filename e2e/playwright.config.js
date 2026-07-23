// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Local-only e2e config. This directory is deliberately outside the PHP `tests/` suite and is
 * never wired into CI (the package CI runs `composer test`, which does not touch `e2e/`). Run it
 * on demand — see e2e/README.md.
 */
module.exports = defineConfig({
  testDir: './specs',
  timeout: 120_000,
  expect: { timeout: 30_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
