const { chromium } = require('@playwright/test');
const fs = require('fs');

module.exports = async config => {
  const statePath = process.env.CREDOQ_STORAGE_STATE || '/tmp/credoq-staging-auth.json';
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(`${process.env.CREDOQ_TEST_URL.replace(/\/$/, '')}/wp-login.php`, { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.locator('#user_login').fill(process.env.CREDOQ_TEST_USER);
  await page.locator('#user_pass').fill(process.env.CREDOQ_TEST_LOGIN_PASSWORD);
  await page.locator('#wp-submit').click();
  if (/wp-login\.php/.test(page.url())) throw new Error('Authenticated staging browser login failed; check CREDOQ_TEST_USER and CREDOQ_TEST_LOGIN_PASSWORD.');
  await context.storageState({ path: statePath });
  await browser.close();
  if (!fs.existsSync(statePath)) throw new Error('Authenticated staging storage state was not created.');
};
