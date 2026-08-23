const { chromium } = require('@playwright/test');
const fs = require('fs');

module.exports = async config => {
  const statePath = process.env.CREDOQ_STORAGE_STATE || '/tmp/credoq-staging-auth.json';
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();
  const loginUrl = `${process.env.CREDOQ_TEST_URL.replace(/\/$/, '')}/wp-login.php`;
  await page.goto(loginUrl, { waitUntil: 'commit', timeout: 20000 });
  const username = page.locator('#user_login, input[name="log"]').first();
  const password = page.locator('#user_pass, input[name="pwd"]').first();
  await username.waitFor({ state: 'visible', timeout: 20000 });
  await password.waitFor({ state: 'visible', timeout: 20000 });
  await username.fill(process.env.CREDOQ_TEST_USER);
  await password.fill(process.env.CREDOQ_TEST_LOGIN_PASSWORD);
  await page.locator('#wp-submit, input[name="wp-submit"]').first().click();
  if (/wp-login\.php/.test(page.url())) throw new Error(`Authenticated staging browser login failed at ${loginUrl}; check CREDOQ_TEST_USER and CREDOQ_TEST_LOGIN_PASSWORD.`);
  await context.storageState({ path: statePath });
  await browser.close();
  if (!fs.existsSync(statePath)) throw new Error('Authenticated staging storage state was not created.');
};
