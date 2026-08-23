const fs = require('fs');
const path = require('path');
const { expect } = require('@playwright/test');

const PREFIX = process.env.CREDOQ_AUDIT_PREFIX || 'AUDIT TEST';
const evidenceDir = path.resolve(__dirname, '..', 'audit-evidence');
fs.mkdirSync(evidenceDir, { recursive: true });
const manifestPath = path.join(evidenceDir, 'fixture-manifest.json');
const manifest = fs.existsSync(manifestPath) ? JSON.parse(fs.readFileSync(manifestPath, 'utf8')) : { prefix: PREFIX, fixtures: [], observations: [] };

function recordObservation(observation) {
  manifest.observations.push({ timestamp: new Date().toISOString(), ...observation });
  fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
}

function recordFixture(kind, name, id = null, cleanup = null) {
  if (!name.startsWith(PREFIX)) throw new Error(`Fixture name must start with audit prefix: ${name}`);
  manifest.fixtures.push({ kind, name, id, cleanup });
  fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
  return name;
}

async function login(page) {
  await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.locator('#user_login').fill(process.env.CREDOQ_TEST_USER);
  const loginPassword = process.env.CREDOQ_TEST_LOGIN_PASSWORD || process.env.CREDOQ_TEST_APP_PASSWORD;
  await page.locator('#user_pass').fill(loginPassword);
  await page.locator('#wp-submit').click();
  await expect(page).not.toHaveURL(/wp-login\.php/, { timeout: 20000 });
  recordObservation({ track: 'auth', status: 'authenticated', url: page.url() });
}

async function assertAdminPage(page, pageUrl, expectedText) {
  const response = await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
  expect(response && response.ok()).toBeTruthy();
  if (expectedText) await expect(page.getByText(expectedText, { exact: false }).first()).toBeVisible({ timeout: 15000 });
  recordObservation({ track: 'admin', page: pageUrl, status: 'loaded', httpStatus: response.status() });
}

async function assertNoUnexpectedBrowserErrors(page, label) {
  const errors = page.__credoqErrors || [];
  recordObservation({ track: label, browserErrors: errors });
  expect(errors, `${label} browser errors`).toEqual([]);
}

function installDiagnostics(page) {
  page.__credoqErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') page.__credoqErrors.push(`console: ${msg.text()}`); });
  page.on('pageerror', error => page.__credoqErrors.push(`pageerror: ${error.message}`));
  page.on('requestfailed', request => page.__credoqErrors.push(`requestfailed: ${request.url()} ${request.failure()?.errorText || ''}`));
}

async function assertSafeAuditPrefix(name) {
  expect(name).toMatch(new RegExp(`^${PREFIX.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
}

module.exports = { PREFIX, evidenceDir, manifestPath, login, assertAdminPage, assertNoUnexpectedBrowserErrors, installDiagnostics, recordFixture, recordObservation, assertSafeAuditPrefix };
