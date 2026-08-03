=== Credoq Engine ===
Contributors: credoq
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.9
License: GPLv2 or later

Core engine for the Credoq modular booking ecosystem with form builder, React booking widget, dashboard shell, reports, addon registry, AJAX contracts, and migration tools.

== Description ==
Credoq Engine provides the shared form builder, frontend widget shell, submission storage, dashboard SPA, reports page, addon menu shell, field-type registry, and migration tooling used by the Credoq commercial addon suite.

== Installation ==
1. Upload and activate Credoq Engine.
2. Activate the needed Credoq addons.
3. Build forms and configure workflows from the Credoq admin menu.

== Changelog ==
= 1.1.9 =
Cross-plugin correctness pass covering Events + Seats seat-map integration
(auto event/plan resolution, per-seat pricing replacing flat pricing across
WooCommerce/credit/stored totals, cross-event booking isolation, capacity
ceilings, WooCommerce cancellation handling), a Forms Builder settings panel
for the seat_map field type, and supporting Engine-side fixes to the React
booking widget (event resolution, qty-stepper UX). See AUDIT.md in the
Credoq documentation package for the full list with file/function references.

= 1.0.0 =
Initial commercial modular release.
