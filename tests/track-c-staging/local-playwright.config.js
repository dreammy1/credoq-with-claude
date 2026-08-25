const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: 'local-flow.spec.js',
  fullyParallel: false,
  workers: 1,
  timeout: 30000,
  reporter: [['list'], ['json', { outputFile: 'local-flow-results.json' }]],
  use: { browserName: 'chromium', headless: true },
});
