# CredoQ Custom MCP Architecture

## Objective

Provide an authenticated MCP endpoint inside WordPress so an authorized AI client can discover and manage CredoQ plugins through structured tools instead of screen scraping. The endpoint must be audit-logged, capability-scoped, and fail closed for destructive or production-sensitive actions.

## Current repository finding

The current repository contains five CredoQ plugin packages and an E2E Runner plugin, but it does not contain a complete MCP server implementation. The E2E Runner stores an MCP endpoint setting and dispatches GitHub Actions; it does not expose the plugin admin APIs as MCP tools. A new dedicated `credoq-mcp-server` plugin is therefore required.

## Tool layers

| Layer | Examples | Default policy |
|---|---|---|
| Discovery | `credoq_system_status`, `credoq_plugin_inventory`, `credoq_admin_routes`, `credoq_rest_routes` | Read-only; callable immediately |
| Read | `credoq_get_setting`, `credoq_list_forms`, `credoq_get_form`, `credoq_list_services`, `credoq_list_staff`, `credoq_list_events`, `credoq_list_seat_plans`, `credoq_list_memberships`, `credoq_list_bookings`, `credoq_get_order_correlation` | Read-only; capability-scoped |
| Proposal | `credoq_propose_change`, `credoq_preview_form`, `credoq_plan_e2e_audit` | Creates an audit record and diff; does not mutate |
| Write | `credoq_update_setting`, `credoq_create_form`, `credoq_create_service`, `credoq_create_staff`, `credoq_create_event`, `credoq_create_seat_plan`, `credoq_create_membership`, `credoq_update_booking`, `credoq_update_order_status` | Requires explicit confirmation token and allowed scope |
| High risk | `credoq_install_plugin`, `credoq_uninstall_plugin`, `credoq_activate_plugin`, `credoq_deactivate_plugin`, `credoq_delete_fixture`, `credoq_run_real_order_test`, `credoq_deploy_production`, `credoq_rollback` | Disabled by default; separate approval and environment guard |

## Authentication

Use a dedicated MCP key stored as a WordPress hash, never as plaintext in an option or URL. Accept `Authorization: Bearer <key>` and optionally `X-CredoQ-MCP-Key`. Use constant-time hash verification, rate limiting, request IDs, and an audit record for every request. Do not reuse the GitHub Actions token or the WordPress browser password.

## MCP transport

Implement Streamable HTTP JSON-RPC at `POST /wp-json/credoq-mcp/v1/mcp` with `initialize`, `notifications/initialized`, `tools/list`, and `tools/call`. Return JSON-RPC errors for malformed requests, unauthorized requests, unknown tools, invalid arguments, missing capabilities, and unconfirmed mutations. Add a health/discovery GET response that does not expose secrets.

## Approval contract

Every mutating tool returns a preview containing the exact operation, target IDs, before/after diff, environment, request ID, expiry, and a one-time confirmation token. The operation executes only when the AI sends that token in a second call and the current WordPress user or MCP principal has the required capability. Deletes, plugin lifecycle changes, real order creation, deployment, and rollback require a stronger approval scope and are never enabled by the general write scope.

## Admin integration

The plugin should add a CredoQ MCP settings page where an administrator can generate/revoke a key, choose read/write scopes, enable staging-only test operations, view audit logs, and configure the endpoint URL. Key rotation must revoke the old key immediately. The page must never display the key after creation.

## Testing sequence

Start with protocol tests for unauthenticated rejection, initialize, tools/list, unknown-tool rejection, malformed arguments, and read-only inventory. Then test preview/confirm behavior with synthetic fixture IDs. Finally run authenticated staging discovery and form-driven E2E tests. Do not enable real order creation or production deployment until backup, rollback, and human approval contracts are verified.
