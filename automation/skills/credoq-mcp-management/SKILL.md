# CredoQ MCP Management Skill

## Purpose

Use the CredoQ MCP server to inspect and manage the CredoQ WordPress plugin suite through authenticated, approval-gated tools. The suite currently includes the CredoQ Engine, Appointments, Membership, Events, and Seats plugins. Use read-only discovery first, then perform only the smallest requested mutation.

## Required operating rules

1. Identify the target site and environment before every mutation. Never create orders, change production data, or change plugin code unless the user explicitly authorizes that exact action.
2. Start with `initialize`, then `tools/list`, then read-only discovery. Never infer a table name, option name, field name, or status value when the server can enumerate it.
3. For settings, use the preview/proposal tool first. A write requires `confirm=true` plus the one-time `confirm_token`. Never reuse a token.
4. For bookings, services, and seat plans, use the typed proposal tools rather than generic option writes. Verify the record before and after the change.
5. For WooCommerce verification, use `credoq_list_payment_gateways` and `credoq_preview_staging_order` first. Order creation is permitted only on an explicitly configured staging site, with synthetic customer data, COD or BACS, and a second confirmation. Never capture a real payment.
6. Do not delete records, uninstall plugins, alter credentials, rotate keys, or modify production deployment settings without an explicit human approval step.
7. After each mutation, report the exact object, fields changed, before/after summary, confirmation identifier, and any follow-up verification result. Do not expose passwords, API keys, application passwords, or secret option values.

## Royal MCP WordPress option allowlist

Royal MCP's allowlisted plugin options control only generic `wp_update_option`-style writes. They do not automatically provide CRUD access to bookings, services, memberships, events, seats, forms, WooCommerce orders, plugin files, installation, or deployment. Those capabilities must be exposed by dedicated, validated MCP tools.

Recommended conservative entries for Royal MCP, one per line:

```text
credoq_engine_settings
credoq_booking_settings
credoq_debug_mode
credoq_e2e_runner
```

The following options should not be added to the Royal writable allowlist:

```text
credoq_smtp_settings
credoq_mcp_key_hash
credoq_mcp_key_meta
credoq_mcp_audit_log
credoq_mcp_enable_staging_orders
credoq_remove_data_on_uninstall
credoq_apt_delete_data
credoq_membership_delete_data
credoq_events_delete_data
credoq_seats_delete_data
credoq_engine_db_version
credoq_apt_db_version
credoq_membership_db_version
credoq_events_db_version
credoq_seats_db_version
```

The SMTP container can include reCAPTCHA or mail secrets and must remain read-only or hidden. Database-version options, delete-data flags, MCP key metadata, audit logs, and staging-order gates are operational safety controls rather than ordinary user settings.

## Five-plugin management map

| Plugin area | Preferred management path | Royal option write suitable? |
|---|---|---:|
| Engine general/security settings | `credoq_get_setting`, preview, then confirmed update | Limited; only the safe container and non-secret fields |
| Appointments/bookings | Typed booking list and booking proposal/update tools | No; use typed tools |
| Membership plans, user membership, credits | Dedicated membership data tools when exposed | No; use typed tools |
| Events/providers/staff | Dedicated event/provider tools when exposed | No; use typed tools |
| Seats/seat plans/capacity | Typed seat-plan tools | No; use typed tools |
| Forms and E2E | Form-builder tools and the local/staging Playwright runner | No; use dedicated tools |
| WooCommerce test order | Gateway list, staging preview, confirmed staging order | No; enforced staging-only path |

## Safe workflow for a user request

Translate the request into a plan containing target environment, object type, intended fields, validation steps, and rollback or cleanup. Run read-only discovery. Preview the exact mutation. Ask for or require the human confirmation token. Apply once. Re-read the affected object and run the relevant E2E or checkout verification. Produce a concise audit record.

## Example reasoning patterns

For “change the appointment status,” list or fetch the booking, validate the requested status against the server's allowed values, create a booking proposal, and apply it only after explicit confirmation.

For “test checkout,” list enabled gateways, confirm the site is staging, preview an `AUDIT TEST` order using COD/BACS and synthetic data, create it only after confirmation, and verify the order remains uncaptured.

For “add a feature,” do not use an option write as a substitute for code management. Inspect the repository, create a branch, implement and test the change, open a pull request, and require the protected release approval before deployment.

## Failure handling

If authentication fails, do not guess or regenerate credentials in an AI response. Ask the site administrator to rotate the MCP key through WordPress admin. If a tool is not exposed, state that it is unavailable rather than writing directly to an unknown database table or option. If a staging-order guard fails, stop and explain which explicit staging prerequisite is missing.
