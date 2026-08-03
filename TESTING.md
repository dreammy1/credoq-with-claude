# Credoq Suite — Cross-Plugin Test Harness

Execution-based tests for the interactions between `credoq-engine`,
`credoq-appointments`, `credoq-events`, `credoq-seats`, and
`credoq-membership` — the kind of bug (missing methods, wrong hook
arguments, averaged prices) that only surfaces by actually running the
code, not by reading it. Each `tests/testN_*.php` file is standalone and
prints PASS/FAIL per assertion.

## Setup

1. Drop all five plugin folders (using their real names — `credoq-engine-v3`,
   `credoq-appointments`, `credoq-events-v3`, `credoq-seats`,
   `credoq-membership-v3`) into a single directory.
2. Point the suite at it: `export CREDOQ_PLUGINS_DIR=/path/to/that/directory`
   (or place them in `./plugins/` next to this README).
3. `./run-all.sh`

Requires PHP 8.1+ CLI. No WordPress install, no database — `tests/wp_stubs.php`
provides a minimal in-memory `$wpdb` + WP function stand-in purpose-built
for these tests (not a general WP test framework).

## What's covered

| File | Covers |
|---|---|
| `test1_resolve_event_id.php` | Seat map → event resolution from a submission payload |
| `test2_event_scoping.php` | Seat holds/releases/bookings scoped correctly when one plan serves multiple events |
| `test3_full_integration.php` | Full price-replacement flow: seat plan total overrides flat event price in WC/credit/stored booking |
| `test4_capacity_and_ambiguity.php` | Seat-plan-aware capacity ceiling; ambiguous multi-event seat_map submissions rejected |
| `test5_wc_cancel_hook.php` | WooCommerce cancel/refund/fail correctly releases seats |
| `test6_membership_static_methods.php` | Membership_Service's credit ledger methods (balance, deduct, refund) |
| `test7_appointments_bridge_pricemap.php` | Per-seat pricing applied to Appointments bookings too |
| `test8_legacy_event_register.php` | Legacy standalone Events registration: capacity ceiling + credit form_id argument |

## Adding a new test

Copy the shape of any existing `testN_*.php`: require `bootstrap.php` first
(defines `PLUGINS_DIR`), then `wp_stubs.php`, then only the specific plugin
source files the scenario needs — these are intentionally narrow, fast unit
tests, not a full WordPress bootstrap.
