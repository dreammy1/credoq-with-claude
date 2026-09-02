# Credoq Suite

Five interconnected WordPress plugins — one core engine plus four addons —
forming a booking, registration, and membership platform.

## Layout

- `plugins/` — the five plugin source trees, ready to zip and install individually:
  `credoq-engine-v3`, `credoq-appointments`, `credoq-events-v3`, `credoq-seats`, `credoq-membership-v3`
- `tests/` + `run-all.sh` — the cross-plugin execution-based test harness (PHP CLI, no WordPress/DB required)
- `docs/credoq-docs.html` — full interactive technical documentation (architecture, lifecycles, settings matrix, business use cases, test suite reference)
- `AUDIT.md` — complete bug/fix log with file and function references
- `.github/workflows/tests.yml` — CI: lints every plugin file and runs the full harness suite on PHP 8.1/8.2/8.3 on every push and pull request

## Running tests locally

**Cross-plugin mock harness** (fast, no WordPress needed):
```
./run-all.sh
```

**Track A — Backend Admin Settings** (real headless WordPress + SQLite, every plugin setting toggled On/Off against real code):
```
tests/track-a-admin-settings/setup-wordpress.sh /tmp/wpsite $(pwd)/plugins
WPSITE=/tmp/wpsite tests/track-a-admin-settings/run-track-a.sh
```

**Track B — React Widget E2E** (Playwright driving the real production widget bundle, backend mocked via route interception):
```
cd tests/track-b-e2e
npm ci && npx playwright install --with-deps chromium
npx playwright test
```

No setup needed for the mock harness — `tests/bootstrap.php` defaults to `plugins/` next to this
README. See `TESTING.md` for details and how to point it at a different
plugin checkout location.

## CI status

Check the **Actions** tab for the latest run — every push to `main` and every
pull request triggers the full suite across three PHP versions.
