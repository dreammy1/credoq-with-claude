# CredoQ Live Audit Contract

The live audit is valid only when it runs against a dedicated staging or test WordPress site, or when the operator explicitly approves a production run. Every created object must begin with the configured audit prefix. The runner must record WordPress IDs, WooCommerce order IDs, booking IDs, membership transaction IDs, timestamps, screenshots, browser console errors, HTTP responses, and cleanup results.

## Admin configuration matrix

| Domain | Required setup and verification |
|---|---|
| Engine | Read every visible settings page; toggle safe settings in an isolated run; save; reload; verify persisted values; restore the original configuration |
| Membership | Create an audit plan; grant it to the audit user; verify expiry and starting credits; test allowed form IDs and credit rules |
| Appointments | Create an audit service; configure duration, interval, capacity, staff, price, membership deduction, seat plan, weekly availability, off-day, break hour, and special-day price; reload and verify persistence |
| Staff | Create an audit provider; verify multiplier, working hours, breaks, holidays, and assignment |
| Events | Create a published audit event; verify date, capacity, price, WooCommerce product, seat plan, credit deduction, and ticket quantity rules |
| Seats | Create, connect, price, publish, and reload an audit plan; verify layout capacity, seat types, overrides, holds, confirmation, and release |
| Forms | Create Form ID 4-equivalent coverage with all 20 field variants; publish it; create a page containing its shortcode; verify frontend rendering and submission storage |

## Frontend matrix

The browser runner must create a fresh context for each scenario and capture evidence on failure. It must test basic fields, required-field validation, email/phone validation, long text, number, date, time, dropdown, radio, checkboxes, file upload, signature, hidden fields, HTML blocks, quantity, formula, total price, appointment booking, event calendar, seat map, and submit behavior.

The appointment scenarios must verify provider discovery, availability, date selection, time slot selection, capacity, off-day rejection, break-hour rejection, special-day price, staff multiplier, seat price overrides, and final total. The event scenarios must verify event price, ticket quantity, capacity exhaustion, event seats, event credits, and duplicate submission handling.

## Commerce and membership matrix

For every paid scenario, the runner must verify the displayed total, the WooCommerce cart total, the checkout total, the payment method, the order status, and the Credoq booking status. The test must use a non-capturing payment mode. It must correlate the order and booking by an explicit ID or metadata field. It must verify the expected notification hook and, when a test mailbox is approved, actual delivery. Membership credit tests must compare the balance before and after pending, paid, cancelled, and refunded states and verify that deduction and refund are idempotent.

## Pass criteria

A scenario passes only when configuration persistence, frontend behavior, stored data, and integration side effects agree. A browser success message alone is insufficient. If payment, email, MCP transport, or background jobs cannot be observed, the result is `Not verified`, not `Pass`.

## Cleanup criteria

The runner must delete or archive only objects whose names begin with the audit prefix and must leave non-test records untouched. Before cleanup it must write a manifest of every fixture ID. If cleanup fails, the workflow must fail and list the remaining IDs. Production deployment is prohibited when cleanup is incomplete or when any destructive action lacks an approval record.
