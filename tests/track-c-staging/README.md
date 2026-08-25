# Authenticated staging audit

## Local runner

For real staging tests from a computer or WordPress Studio, copy `.env.staging.example` to `.env.staging.local`, fill the values locally, and run `npm run audit:local`. The local env file is intentionally not committed. The runner installs Chromium, uses the local browser environment, writes HTML/JSON/trace evidence under `audit-evidence/`, and keeps cleanup disabled by default.

The local runner exercises the same authenticated staging specs as CI. It does not bypass WAF security; it only avoids GitHub-hosted runner IP challenges when the user’s local browser can access the site. If the site presents a bot challenge even locally, use an approved staging hostname or a hosting allowlist.


This track runs only when the manual `run_live_audit` workflow input is enabled and the protected `staging-audit` environment supplies the required credentials. It is deliberately separate from the local widget fixture tests.

The required secrets are `CREDOQ_TEST_URL`, `CREDOQ_TEST_USER`, `CREDOQ_TEST_APP_PASSWORD`, and `CREDOQ_TEST_LOGIN_PASSWORD`. The application password is reserved for authenticated REST calls; the normal WordPress login password is required for browser-based admin pages. The workflow fails closed when the browser password is missing.

Optional environment-scoped secrets are `CREDOQ_BOOKING_FORM_PATH`, `CREDOQ_APPOINTMENT_DATE`, `CREDOQ_APPOINTMENT_SLOT_TEXT`, `CREDOQ_FRONTEND_AUDIT_PATH`, `CREDOQ_CHECKOUT_PATH`, `CREDOQ_BOOKING_API_PATH`, `CREDOQ_MEMBERSHIP_API_PATH`, `CREDOQ_NOTIFICATION_STATUS_PATH`, and `CREDOQ_TEST_MAILBOX`. `CREDOQ_BOOKING_FORM_PATH` is the published appointment form and falls back to `CREDOQ_FRONTEND_AUDIT_PATH`; the date and slot variables identify an available schedule. Missing optional paths are recorded as skipped or `Not verified`; they are not silently reported as passing. The appointment-flow track submits the CredoQ booking form to verify the redirect into WooCommerce checkout, but it never clicks Place Order. The checkout track checks only a non-capturing gateway and never submits payment. `CREDOQ_ENABLE_CLEANUP` remains false in CI until a reviewed cleanup command is added.

Every fixture recorded by the track must begin with `AUDIT TEST`. The manifest is written before any cleanup. No delete or destructive WordPress operation is performed by these tests. Screenshots, traces, HTML report, JSON results, browser diagnostics, HTTP statuses, fixture manifest, and observations are uploaded as workflow artifacts.

## Coverage

The track inventories the CredoQ admin and REST surface, loads configured admin pages, checks nonce-protected settings forms, renders the configured published frontend audit page, validates required fields, checks the non-capturing WooCommerce gateway, records order/booking correlation observability, classifies membership balance and notification visibility, and verifies cleanup guards.

A green run proves only the observations listed in its evidence artifacts. Payment capture, actual email delivery, background jobs, MCP deployment, backup, rollback, and any path not configured in the staging environment remain `Not verified`.
