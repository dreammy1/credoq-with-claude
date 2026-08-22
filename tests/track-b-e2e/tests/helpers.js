const fs = require('fs');
const path = require('path');

const FIXTURES_DIR = path.join(__dirname, '..', 'fixtures');
const WIDGET_JS = fs.readFileSync(path.join(FIXTURES_DIR, 'booking-widget.min.js'), 'utf8');
const WIDGET_CSS = fs.readFileSync(path.join(FIXTURES_DIR, 'booking-widget.min.css'), 'utf8');
const SEAT_JS = fs.readFileSync(path.join(FIXTURES_DIR, 'frontend-seat-map.js'), 'utf8');

/**
 * Mounts the REAL production booking-widget.min.js bundle with the given
 * config, exactly the way Shortcodes.php does on a live WordPress page
 * (a #credoq-booking-root div with a JSON data-config attribute) — but
 * via page.setContent() + addStyleTag/addScriptTag rather than navigating
 * to any URL. This makes the test's own page load have ZERO external
 * network dependency (no file:// origin quirks, no local server to keep
 * alive, nothing that can itself fail/hang independently of the widget).
 * The only network activity on the page is the widget's own real AJAX
 * calls to config.ajax_url — already intercepted via mockAjax()/page.route,
 * which works at the network layer regardless of the destination's actual
 * reachability or the page's own origin.
 */
async function mountWidget(page, config) {
  page._browserErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') page._browserErrors.push('[console.error] ' + msg.text()); });
  page.on('pageerror', err => page._browserErrors.push('[pageerror] ' + err.message));

  const configJson = JSON.stringify(config).replace(/"/g, '&quot;');
  await page.setContent(
    `<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>` +
    `<div id="credoq-booking-root" data-config="${configJson}"></div>` +
    `</body></html>`,
    { waitUntil: 'domcontentloaded' }
  );
  await page.addStyleTag({ content: WIDGET_CSS });
  const seatAjaxUrl = JSON.stringify(config.ajax_url || 'http://127.0.0.1:4173/wp-admin/admin-ajax.php');
  const seatNonce = JSON.stringify(config.nonce || 'test-nonce-123');
  await page.addScriptTag({ content: `window.credoqSeatsCfg = { ajaxUrl: ${seatAjaxUrl}, nonce: ${seatNonce} };` });
  await page.addScriptTag({ content: SEAT_JS });
  await page.addScriptTag({ content: WIDGET_JS });

  await page.waitForTimeout(1500);

  const rootHTML = await page.locator('#credoq-booking-root').innerHTML().catch(e => '[innerHTML read failed: ' + e.message + ']');
  if (!rootHTML || rootHTML.trim().length < 20) {
    const errs = (page._browserErrors || []).join(' ;; ') || 'none captured';
    throw new Error(`WIDGET DID NOT RENDER. root innerHTML="${rootHTML}". browserErrors=${errs}`);
  }
}

/** Wraps an action; on failure, rethrows with any captured browser console/page errors appended so they appear in the CI annotation. */
async function withDiagnostics(page, label, fn) {
  try {
    await fn();
  } catch (err) {
    const extra = (page._browserErrors || []).slice(0, 5).join(' ;; ');
    err.message = `[${label}] ${err.message}${extra ? ' ---BROWSER ERRORS: ' + extra : ' ---no browser console/page errors captured'}`;
    throw err;
  }
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

module.exports = { mountWidget, mockAjax, withDiagnostics };
