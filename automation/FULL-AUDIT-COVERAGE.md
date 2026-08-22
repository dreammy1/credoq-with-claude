# CredoQ Full Audit Coverage

## Purpose

The original Playwright track contained three event-widget scenarios. This document records the expanded deterministic browser matrix and separates fixture-level verification from authenticated staging verification. A green fixture test proves that the production widget bundle behaves correctly for the exercised configuration; it does not by itself prove that a live WordPress administrator screen, WooCommerce gateway, mailbox, or external MCP transport is healthy.

## Automated browser coverage

| Area | Coverage now present | Evidence requirement |
|---|---|---|
| Field rendering | Text, email, long text, number, date, dropdown, time, radio, checkboxes, file upload, signature, hidden, HTML block, quantity, formula, total price | Rendered control, no page errors, and appropriate hidden/visible state |
| Contract completeness | All 20 declared audit field variations are mapped to a runtime test or explicit special-flow test | Contract test fails if a declared type is missing from the mapping |
| Validation | Empty required values and malformed email are prevented from becoming a successful submission | Browser validity and zero mocked submissions |
| Data capture | Text, long text, select, and checkbox values reach the booking payload | Payload assertions on mocked AJAX request |
| Calculation | Formula field reacts to numeric input and total-price component renders | Calculated output and total component assertions |
| Upload/signature | File input exists, visible upload wrapper exists, signature canvas and clear action exist | DOM and accessibility-control assertions |
| Events | Free event selection, event with seat map, quantity lock, seat hold, real seat ID submission | Existing `events-widget.spec.js` scenarios |
| Failure handling | Backend rejection produces a visible error while the widget remains mounted; capacity rejection is covered | Existing and expanded negative-path tests |
| Diagnostics | Console/page errors are captured and attached to failures | `withDiagnostics`, screenshots, traces, HTML report |

## Required authenticated staging tracks still to implement

The live audit must add separate admin and authenticated frontend projects rather than pretending the local fixture is equivalent to production. These tracks must create prefixed fixtures and record WordPress IDs, WooCommerce order IDs, booking IDs, membership transaction IDs, timestamps, screenshots, console errors, HTTP responses, and cleanup results.

The admin track must walk every page of all five plugins and exercise settings persistence, membership-plan creation and assignment, service and provider creation, event creation, seat-plan configuration, form builder save/publish, plugin activation/deactivation checks, permission boundaries, nonce failures, and deletion/cleanup. The frontend track must publish pages for the 20 field variations and execute appointment, event, seat, availability, capacity, special-day, off-day, break-hour, pricing, membership-credit, and duplicate-submission scenarios.

The commerce track must use a non-capturing payment mode and correlate the WooCommerce order to the Credoq booking by explicit metadata or ID. It must verify cart total, checkout total, order status transitions, booking status synchronization, notification hook, approved mailbox delivery, credit deduction, cancellation, refund, and idempotency. Any unobservable payment, email, MCP transport, or background-job result must be reported as `Not verified`, never as `Pass`.

## CI behavior

The new specification is under `tests/track-b-e2e/tests/`, so the existing Playwright command automatically discovers it. The CI artifact must retain the HTML report, JSON result, screenshots, traces, and package checksums. Live audit remains a separate manual-opt-in workflow job and production deployment remains approval-gated and fail-closed.

## Current local result

The expanded matrix currently passes **22/22** tests. The original event/widget specification remains separate and should be run in the complete suite. This result covers the widget fixture and mocked AJAX boundary; it is not a claim that all authenticated WordPress, WooCommerce, email, credit, MCP, backup, rollback, or production deployment paths have passed.
