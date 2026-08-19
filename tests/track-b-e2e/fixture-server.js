// Minimal static file server (no extra deps) for serving the widget
// harness fixtures over http:// instead of file://.
//
// AUDIT-FIX (Track B — root cause of the CI hang): the widget's real
// fetch(config.ajax_url, ...) calls were being made from a file:// origin
// page. Browsers apply much stricter (often fully blocking) CORS/
// mixed-content handling to fetches originating from file://, which
// silently stalls the widget's AJAX calls before Playwright's route
// interception even gets a chance to fulfill them — explaining why every
// test hung at its first interaction regardless of which one it was.
// Serving the harness over a real http:// origin (matching how it's
// actually used on a live WordPress site) removes that class of problem
// entirely, and is the standard/recommended Playwright pattern besides.
const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = process.env.PORT || 4173;
const ROOT = path.join(__dirname, 'fixtures');

const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css' };

http.createServer((req, res) => {
  const urlPath = decodeURIComponent(req.url.split('?')[0]);
  const filePath = path.join(ROOT, urlPath === '/' ? 'widget-harness.html' : urlPath);
  if (!filePath.startsWith(ROOT)) { res.writeHead(403); res.end(); return; }
  fs.readFile(filePath, (err, data) => {
    if (err) { res.writeHead(404); res.end('Not found: ' + urlPath); return; }
    const ext = path.extname(filePath);
    res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
    res.end(data);
  });
}).listen(PORT, () => console.log(`Fixture server listening on http://127.0.0.1:${PORT}`));
