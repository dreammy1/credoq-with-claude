# Credoq 2-Track QA Docs

This folder contains planning and setup documentation for a proposed 2-track QA package for the Credoq suite.

The repository currently includes:

- `plugins/` with the five WordPress plugins
- `tests/` with the existing PHP CLI harness
- `.github/workflows/tests.yml` with CI that lints plugin PHP files and runs `./run-all.sh`

The repository does not currently include the previously described `credoq-2track-qa` automation files such as a Track A WordPress bootstrap harness or Track B Playwright suite. This folder documents where those files should live and how to add them safely.

## Files In This Folder

- `FOLDER_STRUCTURE.md` explains the proposed directory layout and what each file is for
- `SETUP_GUIDE.md` gives step-by-step instructions for adding the package and wiring it into local and CI workflows

## Current Repo Anchors

- Root overview: `README.md`
- Existing test harness: `TESTING.md`
- Existing CI workflow: `.github/workflows/tests.yml`
- Existing React widget source: `plugins/credoq-engine-v3/react-widget/`
- Existing seat canvas source: `plugins/credoq-seats/react-canvas/`

## Recommendation

Add any future 2-track QA package at the repository root as:

```text
credoq-2track-qa/
```

That keeps the new automation isolated from the production plugin code while still making it easy to run from CI and local shells.
