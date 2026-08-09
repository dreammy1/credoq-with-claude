const fs = require('fs');
const path = require('path');

const HARNESS_TEMPLATE = fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'widget-harness.html'), 'utf8');
const FIXTURES_DIR = path.join(__dirname, '..', 'fixtures');

/**
 * Mounts the REAL production booking-widget.min.js bundle with the given
 * config, exactly the way Shortcodes.php does on a live WordPress page.
 * Nothing about the widget itself is mocked — only the backend it talks
 * to (via mockAjax below), which must be set up BEFORE calling this.
 */
async function mountWidget(page, config) {
  const html = HARNESS_TEMPLATE.replace(
    'CONFIG_JSON_PLACEHOLDER',
    JSON.stringify(config).replace(/'/g, '&#39;')
  );
  const tmpFile = path.join(FIXTURES_DIR, `_tmp-${Date.now()}-${Math.random().toString(36).slice(2)}.html`);
  fs.writeFileSync(tmpFile, html);
  await page.goto('file://' + tmpFile);
  await page.waitForSelector('#credoq-booking-root', { state: 'attached' });
  page.once('close', () => { try { fs.unlinkSync(tmpFile); } catch (e) {} });
  return tmpFile;
}

/**
 * Intercepts every admin-ajax.php POST from the widget (its real fetch()
 * calls, unmodified) and routes by the multipart FormData 'action' field.
 * `handlers` is { actionName: (fields) => jsonResponseObject }.
 * `fields` is a best-effort extraction of simple (non-file) multipart
 * field values — sufficient for asserting what the widget actually sent
 * without needing a full multipart parser.
 */
async function mockAjax(page, handlers) {
  await page.route('**/admin-ajax.php*', async (route) => {
    const postData = route.request().postData() || '';
    const actionMatch = postData.match(/name="action"\r?\n\r?\n([^\r\n-]+)/);
    const action = actionMatch ? actionMatch[1].trim() : '';

    const fields = {};
    const fieldRe = /name="([^"]+)"\r?\n\r?\n([\s\S]*?)\r?\n--/g;
    let m;
    while ((m = fieldRe.exec(postData)) !== null) {
      fields[m[1]] = m[2];
    }

    const handler = handlers[action];
    if (!handler) {
      await route.fulfill({ json: { success: false, data: { message: `[mock] unhandled action: ${action}` } } });
      return;
    }
    const result = await handler(fields, route.request());
    await route.fulfill({ json: result });
  });
}

module.exports = { mountWidget, mockAjax };
