# Step-By-Step Setup Guide

This guide explains how to add a future 2-track QA package to the current Credoq repository without breaking the existing PHP harness.

## Goal

- Track A: run a headless WordPress-backed backend settings integration harness
- Track B: run Playwright E2E against the React booking widget with mocked backend APIs

## Before You Start

Confirm the current repository already contains:

- `plugins/` with all five plugin folders
- `tests/` with the existing standalone PHP harness
- `run-all.sh` for the current test suite

The current repo does **not** yet contain the full 2-track QA implementation, so the steps below are for adding it cleanly.

## Step 1: Create The Package Folder

From the repository root:

```bash
mkdir -p credoq-2track-qa/track-a/config
mkdir -p credoq-2track-qa/track-b/tests
mkdir -p credoq-2track-qa/track-b/fixtures
mkdir -p credoq-2track-qa/shared/reports
mkdir -p credoq-2track-qa/shared/artifacts
```

Result:

```text
credoq-2track-qa/
├── track-a/
├── track-b/
└── shared/
```

## Step 2: Add Track A Files

Create these files under `credoq-2track-qa/track-a/`:

- `track-a-harness.php`
- `auto-fix-engine.php`
- `config/settings-matrix.php`

Recommended responsibility split:

- `settings-matrix.php`: returns the list of plugin options and expected effects
- `track-a-harness.php`: boots WordPress from CLI, toggles settings, records results
- `auto-fix-engine.php`: retries cache/schema/hook/bootstrap issues after failures

Implementation note:

- Keep WordPress-specific bootstrapping here, not in the existing root `tests/` folder, because the current harness is intentionally WordPress-free and fast.

## Step 3: Add Track B Files

Create these files under `credoq-2track-qa/track-b/`:

- `package.json`
- `playwright.config.js`
- `tests/booking-widget.spec.js`
- `fixtures/api-responses.js`
- `fixtures/settings-state.js`

Recommended responsibility split:

- `package.json`: Playwright dependency and scripts
- `playwright.config.js`: reporters, timeouts, artifacts, browser settings
- `tests/booking-widget.spec.js`: booking widget flows
- `fixtures/api-responses.js`: success and failure responses
- `fixtures/settings-state.js`: reads Track A output and converts it into mock behavior

## Step 4: Keep Existing Test Layers Separate

Do not replace the current root-level harness:

- Keep using `tests/` + `run-all.sh` for lightweight cross-plugin regression coverage
- Use `credoq-2track-qa/track-a/` for WordPress-aware integration coverage
- Use `credoq-2track-qa/track-b/` for browser E2E coverage

This keeps fast tests fast and heavier tests optional.

## Step 5: Add Local Run Scripts

Recommended scripts:

- Root script for current harness: keep `./run-all.sh`
- New Track A script: `php credoq-2track-qa/track-a/track-a-harness.php`
- New Track B script: `npm --prefix credoq-2track-qa/track-b test`
- Optional combined script: `credoq-2track-qa/run-all-2track.sh`

Example combined shell script:

```bash
#!/usr/bin/env bash
set -euo pipefail

php credoq-2track-qa/track-a/track-a-harness.php
npm --prefix credoq-2track-qa/track-b install
npx --prefix credoq-2track-qa/track-b playwright test
```

## Step 6: Decide Where Reports Go

Write outputs to `credoq-2track-qa/shared/`:

- JSON summaries: `shared/reports/`
- Screenshots and traces: `shared/artifacts/`

Recommended files:

- `shared/reports/track-a-report.json`
- `shared/reports/track-b-report.json`
- `shared/reports/final-summary.json`
- `shared/artifacts/*.png`
- `shared/artifacts/*.zip`

## Step 7: Wire CI Carefully

The current CI file is `.github/workflows/tests.yml` and already does two things:

- PHP syntax lint over `plugins/`
- run `./run-all.sh`

When adding the 2-track package, extend CI incrementally:

1. Keep the existing job unchanged at first.
2. Add a second job for Track A after the new PHP files exist.
3. Add a third job for Track B after the Playwright files exist.
4. Upload Track B artifacts on failure.

Recommended future job split:

- `php-harness`: current `run-all.sh`
- `track-a-settings`: WordPress-backed backend integration
- `track-b-widget`: Playwright mocked E2E

## Step 8: Use Real Widget Sources

For frontend testing, use the existing React code already present in the repo:

- `plugins/credoq-engine-v3/react-widget/`
- `plugins/credoq-seats/react-canvas/`

Track B should validate the compiled or mounted widget behavior, not reimplement widget logic in the test package.

## Step 9: Define The Settings Matrix First

Before writing automation, build a single source of truth for plugin options:

- engine settings in `credoq-engine-v3`
- appointment settings in `credoq-appointments`
- event settings in `credoq-events-v3`
- membership settings in `credoq-membership-v3`
- seat settings in `credoq-seats`

Each row in the matrix should include:

- plugin slug
- option name
- allowed values
- expected side effect
- verification method

Example shape:

```php
return [
    [
        'plugin' => 'credoq-appointments',
        'option' => 'show_staff_selector',
        'values' => [0, 1],
        'verify' => 'rest_response_shape',
    ],
];
```

## Step 10: Add A Final Summary Layer

At the end of a full 2-track run, generate one final report in:

```text
credoq-2track-qa/shared/reports/final-summary.json
```

It should include:

- Track A totals
- Track B totals
- failures before fixes
- fixes applied
- final pass/fail outcome

## Minimal Rollout Plan

If you want the safest implementation order, use this sequence:

1. Create the folder tree from `FOLDER_STRUCTURE.md`
2. Implement `settings-matrix.php`
3. Build the Track A harness and verify local PHP execution
4. Add Track B fixtures and Playwright config
5. Add one happy-path widget spec
6. Add error-path widget specs
7. Add final summary generation
8. Extend GitHub Actions

## Quick Reference

Useful current files:

- Root overview: `README.md`
- Existing harness docs: `TESTING.md`
- Existing CI workflow: `.github/workflows/tests.yml`
- Widget package: `plugins/credoq-engine-v3/react-widget/package.json`

Useful future entry points:

- Backend: `credoq-2track-qa/track-a/track-a-harness.php`
- Frontend: `credoq-2track-qa/track-b/tests/booking-widget.spec.js`
- Reports: `credoq-2track-qa/shared/reports/`
