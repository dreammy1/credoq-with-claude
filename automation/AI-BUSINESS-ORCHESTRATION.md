# CredoQ AI Business Orchestration

## Purpose

The CredoQ MCP server now exposes a stable, typed orchestration layer for synthetic staging administration and end-to-end verification. An AI client can plan and verify a business journey without scraping WordPress admin screens. Mutations remain proposal/confirmation gated, staging-only, and audit-logged.

## Protocol contract

The endpoint remains `POST /wp-json/credoq-mcp/v1/mcp` using authenticated Streamable HTTP JSON-RPC. The client sequence is:

1. Call `initialize`.
2. Call `tools/list` and select the required typed operation.
3. For a mutation, call `tools/call` with a preview tool.
4. Inspect the returned `proposal_id`, one-time `confirm_token`, correlation ID, exact requested data, and environment guard.
5. Call the corresponding apply/run tool with `confirm=true` and the token.
6. Call the verification tool with the correlation ID and include the structured report in the final response.

No key, password, or payment credential is returned in tool results.

## New tools

| Tool | Purpose | Mutation | Guard |
|---|---|---:|---|
| `credoq_preview_provision` | Preview a synthetic service, staff member, WordPress user, membership plan, event, seat plan, form, page, or WooCommerce product link | No | N/A |
| `credoq_apply_provision` | Apply one approved provisioning proposal | Yes | `CREDOQ_MCP_STAGING_MODE=true`, non-production, explicit confirmation |
| `credoq_preview_business_e2e` | Create a bounded plan for an appointment, event, membership, seat, form, or full journey | No | N/A |
| `credoq_run_business_e2e` | Run the approved server-side readback and correlation report | Reads and audits | Staging guard, explicit confirmation |
| `credoq_verify_business_journey` | Correlate submissions, appointment/event bookings, memberships, credit ledger, WooCommerce orders, and MCP audit records | No | Read-only |

Provisioning supports the existing table contracts. Membership provisioning stores price and credit metadata in the membership plan `rules` JSON, can assign the plan to a synthetic user, and can grant an initial credit ledger entry. Seat-plan provisioning creates a published plan, a floor, and synthetic available seats when seat definitions are supplied.

## One-command business journeys

The `business_type` values are:

- `appointment`: service/staff setup, product link, slot and capacity checks, form publication, submission, checkout/status correlation, notifications, and audit readback.
- `event`: event and seat-plan setup, event registration, seat allocation, paid checkout and credit-paid branches, and audit readback.
- `membership`: plan and synthetic-user setup, membership assignment, credit grant, deduction/refund checks, and ledger readback.
- `seat`: seat-plan setup, per-seat pricing, hold/confirmation/release, and capacity checks.
- `form`: contact, estimate, appointment, event, and signature field combinations with validation and totals.
- `full`: all supported server-side checks in one correlated run.

The MCP verification result intentionally distinguishes observable records from browser/mailbox-only checks. Authenticated Playwright staging tests remain the execution boundary for filling a published page, redirecting through WooCommerce checkout, inspecting rendered frontend widgets, and verifying an external mailbox.

## Defect fixes included

`Field_Event` now computes a server-side price contribution from the event record and the Events↔Seats override instead of inheriting the zero-valued abstract default. `Seat_Map_Field` now recomputes the selected-seat total from the seat plan and connected event and never trusts the browser-provided total. The appointment seat notification bridge now safely handles repository objects that do not expose a `title` property.

## Operational safety

The implementation does not enable real payment capture, production deployment, plugin installation, plugin deletion, or arbitrary SQL. WooCommerce staging order creation remains separately guarded and limited to COD/BACS. Every mutating operation is scoped to a typed resource and stored as a one-time transient proposal. Use a dedicated MCP key; never reuse a WordPress login or GitHub Actions token.

## Validation performed

The local MCP protocol harness passes, including schema shape, authentication, tool discoverability, setting preview/apply, and one-time token replay rejection. The cross-plugin regression harness passes all 11 test files. PHP lint passes for all modified files.

## Remaining work before production-grade full automation

The current repository still needs authenticated browser/mailbox adapters for fully proving frontend widget rendering, SMTP delivery, IP/country blocking, and WooCommerce status callbacks. Appointment and event provisioning can be performed server-side, but the exact browser checkout and external-notification assertions should remain in the protected staging Playwright track. A future release should add explicit typed tools for those adapters rather than exposing arbitrary HTTP or filesystem access.
