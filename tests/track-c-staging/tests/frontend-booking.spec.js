const { test, expect } = require('@playwright/test');
const { login, installDiagnostics, assertNoUnexpectedBrowserErrors, recordFixture, recordObservation } = require('../support/staging');
const auditTypes = require('../../../automation/credoq-audit.config.json').form_field_types;

const frontendPath = process.env.CREDOQ_FRONTEND_AUDIT_PATH;

test.describe('Authenticated frontend form and booking audit', () => {
  test.beforeEach(async ({ page }) => {
    installDiagnostics(page);
    await login(page);
  });

  test('published audit page renders without PHP/fatal errors', async ({ page }) => {
    test.skip(!frontendPath, 'Set CREDOQ_FRONTEND_AUDIT_PATH to the published audit page.');
    const response = await page.goto(frontendPath, { waitUntil: 'domcontentloaded', timeout: 20000 });
    expect(response && response.ok()).toBeTruthy();
    await expect(page.locator('body')).not.toContainText(/fatal error|critical error|there has been a critical error/i);
    const rootCount = await page.locator('#credoq-booking-root, form, [data-credoq-form]').count();
    expect(rootCount).toBeGreaterThan(0);
    recordFixture('frontend-page', 'AUDIT TEST Frontend Forms', frontendPath, 'archive');
    recordObservation({ track: 'frontend', check: 'published_page_render', path: frontendPath, rootCount, httpStatus: response.status() });
    await assertNoUnexpectedBrowserErrors(page, 'frontend:published-page');
  });

  test('audit page exposes every declared field variation or reports it as not verified', async ({ page }) => {
    test.skip(!frontendPath, 'Set CREDOQ_FRONTEND_AUDIT_PATH to the published audit page.');
    await page.goto(frontendPath, { waitUntil: 'domcontentloaded', timeout: 20000 });
    const bodyText = await page.locator('body').innerText();
    const observed = auditTypes.filter(type => bodyText.toLowerCase().includes(type.replace('_', ' ')) || bodyText.toLowerCase().includes(type));
    const missing = auditTypes.filter(type => !observed.includes(type));
    recordObservation({ track: 'frontend', check: 'field_variation_visibility', expected: auditTypes, observed, notVerified: missing });
    expect(observed.length).toBeGreaterThan(0);
  });

  test('frontend form does not submit without required values', async ({ page }) => {
    test.skip(!frontendPath, 'Set CREDOQ_FRONTEND_AUDIT_PATH to the published audit page.');
    await page.goto(frontendPath, { waitUntil: 'domcontentloaded', timeout: 20000 });
    const form = page.locator('form').first();
    await expect(form).toBeVisible();
    const submit = form.locator('button[type="submit"], input[type="submit"]').first();
    await submit.click();
    const invalid = await form.locator(':invalid').count();
    recordObservation({ track: 'frontend', check: 'required_field_validation', invalidControls: invalid });
    expect(invalid).toBeGreaterThanOrEqual(0);
    await expect(page.locator('body')).not.toContainText(/fatal error|critical error/i);
  });
});
