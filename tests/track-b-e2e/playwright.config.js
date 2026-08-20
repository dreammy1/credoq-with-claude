// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  timeout: 45000,
  reporter: [['list'], ['html', { open: 'never' }], ['json', { outputFile: 'test-results/results.json' }]],
  use: {
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    baseURL: 'http://127.0.0.1:4173',
  },
  webServer: {
    command: 'node fixture-server.js',
    url: 'http://127.0.0.1:4173/widget-harness.html',
    reuseExistingServer: !process.env.CI,
    timeout: 15000,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
