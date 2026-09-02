const { test, expect } = require('@playwright/test');
const { login, assertAdminPage, assertNoUnexpectedBrowserErrors, installDiagnostics, recordObservation, recordFixture } = require('../support/staging');

const adminPaths = (process.env.CREDOQ_ADMIN_PATHS || [
  '/wp-admin/admin.php?page=credoq-engine-settings',
  '/wp-admin/admin.php?page=credoq-forms-builder',
  '/wp-admin/admin.php?page=credoq-membership-plans',
  '/wp-admin/admin.php?page=credoq-appointments',
  '/wp-admin/admin.php?page=credoq-events',
  '/wp-admin/admin.php?page=credoq-seats',
  '/wp-admin/admin.php?page=credoq-e2e-runner',
].join(',')).split(',').map(x => x.trim()).filter(Boolean);

test.describe('Authenticated WordPress admin and plugin inventory', () => {
  test.beforeEach(async ({ page }) => {
    installDiagnostics(page);
  });

  test('WordPress REST index exposes CredoQ namespaces and WooCommerce', async ({ page }) => {
    const response = await page.request.get('/wp-json/');
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.namespaces).toContain('credoq/v1');
    expect(body.namespaces).toContain('credoq-mcp/v1');
    expect(body.namespaces.some(namespace => namespace.startsWith('wc/'))).toBeTruthy();
    recordObservation({ track: 'admin', check: 'rest_namespace_inventory', namespaces: body.namespaces });
  });

  for (const adminPath of adminPaths) {
    test(`admin page loads: ${adminPath}`, async ({ page }) => {
      await assertAdminPage(page, adminPath, null);
      await expect(page.locator('body')).not.toContainText(/fatal error|critical error/i);
      recordFixture('admin-page-observation', `AUDIT TEST Admin ${adminPath}`, adminPath, 'archive');
      await assertNoUnexpectedBrowserErrors(page, `admin:${adminPath}`);
    });
  }

  test('settings screen contains a nonce-protected form when configured', async ({ page }) => {
    const settingsPath = process.env.CREDOQ_SETTINGS_PATH;
    test.skip(!settingsPath, 'Set CREDOQ_SETTINGS_PATH for the plugin settings write-read track.');
    await page.goto(settingsPath, { waitUntil: 'networkidle' });
    const forms = await page.locator('form').count();
    expect(forms).toBeGreaterThan(0);
    const nonceCount = await page.locator('input[name="_wpnonce"]').count();
    expect(nonceCount).toBeGreaterThan(0);
    recordObservation({ track: 'admin', check: 'nonce_protected_settings_form', path: settingsPath, formCount: forms, nonceCount });
    await assertNoUnexpectedBrowserErrors(page, 'admin:settings');
  });
});
