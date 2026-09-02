// @ts-check
const { defineConfig, devices } = require('@playwright/test');

const required = ['CREDOQ_TEST_URL', 'CREDOQ_TEST_USER', 'CREDOQ_TEST_APP_PASSWORD'];
if (process.env.CREDOQ_REQUIRE_ADMIN_BROWSER === 'true' && !process.env.CREDOQ_TEST_LOGIN_PASSWORD) {
  throw new Error('CREDOQ_TEST_LOGIN_PASSWORD is required when browser admin coverage is enabled.');
}
for (const key of required) {
  if (!process.env[key]) throw new Error(`Missing required authenticated staging secret: ${key}`);
}
if (/localhost|127\.0\.0\.1/i.test(process.env.CREDOQ_TEST_URL) && process.env.ALLOW_LOCAL_STAGING !== 'true') {
  throw new Error('Refusing local staging URL unless ALLOW_LOCAL_STAGING=true is explicitly set.');
}

module.exports = defineConfig({
  globalSetup: require.resolve('./global-setup.js'),
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  forbidOnly: true,
  retries: process.env.CI ? 1 : 0,
  timeout: 60000,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }], ['json', { outputFile: 'audit-evidence/results.json' }]],
  use: {
    baseURL: process.env.CREDOQ_TEST_URL.replace(/\/$/, ''),
    browserName: 'chromium',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: false,
    storageState: process.env.CREDOQ_STORAGE_STATE || '/tmp/credoq-staging-auth.json',
  },
  projects: [{ name: 'staging-chromium', use: { ...devices['Desktop Chrome'] } }],
  outputDir: 'audit-evidence/test-results',
});
