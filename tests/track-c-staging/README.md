# Authenticated staging audit

This track runs only when the manual `run_live_audit` workflow input is enabled and the protected `staging-audit` environment supplies the required credentials. It is deliberately separate from the local widget fixture tests.

The required secrets are `CREDOQ_TEST_URL`, `CREDOQ_TEST_USER`, `CREDOQ_TEST_APP_PASSWORD`, and `CREDOQ_TEST_LOGIN_PASSWORD`. The application password is reserved for authenticated REST calls; the normal WordPress login password is required for browser-based admin pages. The workflow fails closed when the browser password is missing.

Optional environment-scoped secrets are `CREDOQ_FRONTEND_AUDIT_PATH`, `CREDOQ_CHECKOUT_PATH`, `CREDOQ_BOOKING_API_PATH`, `CREDOQ_MEMBERSHIP_API_PATH`, `CREDOQ_NOTIFICATION_STATUS_PATH`, and `CREDOQ_TEST_MAILBOX`. Missing optional paths are recorded as skipped or `Not verified`; they are not silently reported as passing. The checkout track checks only a non-capturing gateway and never submits payment. `CREDOQ_ENABLE_CLEANUP` remains false in CI until a reviewed cleanup command is added.

Every fixture recorded by the track must begin with `AUDIT TEST`. The manifest is written before any cleanup. No delete or destructive WordPress operation is performed by these tests. Screenshots, traces, HTML report, JSON results, browser diagnostics, HTTP statuses, fixture manifest, and observations are uploaded as workflow artifacts.

## Coverage

The track inventories the CredoQ admin and REST surface, loads configured admin pages, checks nonce-protected settings forms, renders the configured published frontend audit page, validates required fields, checks the non-capturing WooCommerce gateway, records order/booking correlation observability, classifies membership balance and notification visibility, and verifies cleanup guards.

A green run proves only the observations listed in its evidence artifacts. Payment capture, actual email delivery, background jobs, MCP deployment, backup, rollback, and any path not configured in the staging environment remain `Not verified`.
