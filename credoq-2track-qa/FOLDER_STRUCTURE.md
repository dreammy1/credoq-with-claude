# Proposed Folder Structure

This document maps the real repository layout and shows where the proposed 2-track QA files should be added.

## Current Repository Layout

```text
/workspace
├── .github/
│   └── workflows/
│       └── tests.yml
├── docs/
│   └── credoq-docs.html
├── plugins/
│   ├── credoq-engine-v3/
│   │   └── react-widget/
│   ├── credoq-appointments/
│   ├── credoq-events-v3/
│   ├── credoq-membership-v3/
│   └── credoq-seats/
│       └── react-canvas/
├── tests/
│   ├── bootstrap.php
│   ├── wp_stubs.php
│   └── test*.php
├── AUDIT.md
├── README.md
├── TESTING.md
└── run-all.sh
```

## Recommended New Layout

Add the new QA package at the repository root:

```text
/workspace
├── credoq-2track-qa/
│   ├── README.md
│   ├── FOLDER_STRUCTURE.md
│   ├── SETUP_GUIDE.md
│   ├── track-a/
│   │   ├── track-a-harness.php
│   │   ├── auto-fix-engine.php
│   │   └── config/
│   │       └── settings-matrix.php
│   ├── track-b/
│   │   ├── playwright.config.js
│   │   ├── package.json
│   │   ├── tests/
│   │   │   └── booking-widget.spec.js
│   │   └── fixtures/
│   │       ├── api-responses.js
│   │       └── settings-state.js
│   └── shared/
│       ├── reports/
│       └── artifacts/
├── plugins/
├── tests/
├── README.md
├── TESTING.md
└── run-all.sh
```

## Purpose Of Each Proposed File

- `credoq-2track-qa/README.md`: top-level summary for the package
- `credoq-2track-qa/FOLDER_STRUCTURE.md`: package tree and ownership map
- `credoq-2track-qa/SETUP_GUIDE.md`: setup, execution, and CI steps
- `track-a/track-a-harness.php`: headless WordPress CLI bootstrap for admin-setting toggles
- `track-a/auto-fix-engine.php`: retry and repair logic for Track A failures
- `track-a/config/settings-matrix.php`: canonical list of plugin settings to iterate
- `track-b/playwright.config.js`: Playwright runtime configuration
- `track-b/package.json`: Node scripts and dependencies for frontend E2E
- `track-b/tests/booking-widget.spec.js`: widget tests with mocked backend routes
- `track-b/fixtures/api-responses.js`: deterministic route payloads
- `track-b/fixtures/settings-state.js`: translation layer from Track A results to Track B mocks
- `shared/reports/`: JSON and Markdown output written by runs
- `shared/artifacts/`: screenshots, traces, logs, and debug snapshots

## Why This Layout Fits This Repo

- It keeps automation outside `plugins/`, so plugin zips stay clean.
- It does not disturb the existing `tests/` PHP harness that already runs in CI.
- It gives Track A and Track B separate dependency roots, which helps avoid mixing PHP and Node concerns.
- It gives CI a single entry folder for future commands and artifacts.

## Recommended Ownership Boundaries

- `plugins/`: production code only
- `tests/`: current lightweight PHP execution tests
- `credoq-2track-qa/track-a/`: WordPress-aware backend integration automation
- `credoq-2track-qa/track-b/`: frontend React widget E2E automation
- `credoq-2track-qa/shared/`: generated outputs only

## Suggested Next Files To Add

If you want to implement the package next, create files in this order:

1. `track-a/config/settings-matrix.php`
2. `track-a/track-a-harness.php`
3. `track-b/package.json`
4. `track-b/playwright.config.js`
5. `track-b/fixtures/api-responses.js`
6. `track-b/fixtures/settings-state.js`
7. `track-b/tests/booking-widget.spec.js`
8. `shared/reports/.gitkeep`
9. `shared/artifacts/.gitkeep`
