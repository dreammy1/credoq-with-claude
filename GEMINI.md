# Credoq Suite — Project Context & AI Guidelines

Credoq Suite is a modular platform of five interconnected WordPress plugins plus an AI-management MCP server. It provides booking, registration, membership, and seat-mapping capabilities, designed with an "AI-first" development and automation philosophy.

## Project Architecture

- **Core Engine (`credoq-engine-v3`)**: The foundational logic for forms, submissions, logging, and shared services.
- **Appointments (`credoq-appointments`)**: Catalog-based service booking and staff management.
- **Events (`credoq-events-v3`)**: Event registration and capacity management.
- **Seats (`credoq-seats`)**: Visual seat mapping and per-seat pricing overrides.
- **Membership (`credoq-membership-v3`)**: Plans, user assignments, and credit-based ledgers.
- **MCP Server (`credoq-mcp-server`)**: A secure JSON-RPC interface for AI agents to discover, audit, and manage the suite.

## Development Workflow

1.  **Research**: Inspect plugin boundaries in `plugins/`, read `docs/credoq-docs.html`, and check `AUDIT.md` for historical fixes.
2.  **Implementation**: Create a feature branch. Add behavior to the relevant plugin. **Every change requires a test.**
3.  **Verification**:
    -   **PHP Harness**: `./run-all.sh` (Fast, execution-based mocks).
    -   **Track A**: Admin settings tests (Real WP + SQLite).
    -   **Track B**: E2E Widget tests (Playwright + Mocked backend).
4.  **Audit**: For live-site validation, use the `credoq_dispatch_e2e_audit` tool via MCP.
5.  **Review**: Open a Pull Request with the full implementation summary and test evidence.

## AI Operating Rules (Mandatory)

Refer to `automation/AI-OPERATING-CONTRACT.md` for the full legal/safety boundary.

- **No Direct Production Writes**: Never mutate production settings or data without a gated proposal/confirm workflow.
- **Prefix Everything**: All test/audit data must carry the `AUDIT TEST` prefix.
- **Non-Capturing Payments**: Use only 'cod' or 'bacs' for test orders; never process real payments.
- **Idempotency**: Use the MCP `proposal_id` and `confirm_token` pattern for any state change.
- **Evidence First**: A task is not complete until the evidence report (test output or audit log) is provided.

## Key Commands

- **Run all mock tests**: `./run-all.sh`
- **Setup Track A (WP)**: `tests/track-a-admin-settings/setup-wordpress.sh /tmp/wpsite $(pwd)/plugins`
- **Run Track A**: `WPSITE=/tmp/wpsite tests/track-a-admin-settings/run-track-a.sh`
- **Run Track B (E2E)**: `cd tests/track-b-e2e && npx playwright test`
- **Package Plugins**: `automation/package-suite.sh`

## Directory Overview

- `plugins/`: The source code for each plugin.
- `tests/`: Multi-track test suites and bootstrap.
- `automation/`: AI orchestration contracts, MCP configuration, and deployment scripts.
- `docs/`: Technical architecture and API documentation.
- `AUDIT.md`: A permanent log of bugs and applied fixes.
