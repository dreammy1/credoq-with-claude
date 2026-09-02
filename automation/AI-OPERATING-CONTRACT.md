# AI Operating Contract for CredoQ

## Request intake

When an AI receives a request such as “add a new feature,” it must first inspect the repository, identify the affected plugin boundaries, read the plugin documentation and existing tests, and produce a change plan. It must not edit `main` directly. The change must be made on a branch named with a request identifier, and the AI must explain which files and database/API contracts will change.

## Implementation loop

The AI may edit plugin code, tests, documentation, and build configuration within the repository. It must add or update deterministic tests for every new behavior, preserve backward compatibility across Engine, Appointments, Events, Seats, and Membership, and avoid destructive migrations without a versioned upgrade and rollback path. New add-on plugins must declare the Credoq Engine version range, dependencies, activation checks, hooks, database schema, uninstall behavior, and compatibility tests.

## Validation loop

Before opening a pull request, the AI must run PHP linting, the cross-plugin PHP harness, the real-WordPress admin settings track, the Playwright widget track, and the packaging script. If a live audit is requested, it must use the audit contract, create only prefixed fixtures, use non-capturing payment, capture evidence, and write a pass/fail/not-verified report. A failure may be fixed by returning to the branch and repeating the validation loop; it may not be hidden by weakening or skipping a test.

## Review and merge

The AI opens a pull request containing the user request, implementation summary, changed files, test commands, test output, package checksums, audit evidence, known limitations, and rollback instructions. Merging requires human review. Automated tests passing is not equivalent to production approval.

## Deployment

Production deployment requires a separate protected environment approval and a deployment adapter that supports backup, install/update, activation checks, health verification, and rollback. The adapter must deploy the exact artifact produced by CI, verify its SHA-256 checksum, and record the Git commit SHA and package version. It must never silently deactivate or uninstall unrelated plugins, overwrite non-Credoq files, or run a database mutation without an explicit migration step.

## Allowed and forbidden actions

| Action | Default AI permission | Approval required |
|---|---|---:|
| Read repository and tests | Allowed | No |
| Create branch and modify branch files | Allowed | No |
| Run local/CI tests | Allowed | No |
| Create pull request | Allowed | No, if repository policy permits |
| Read staging audit data | Allowed with scoped credentials | No |
| Create prefixed staging fixtures | Allowed | No, if staging is isolated |
| Modify live production settings/data | Denied by default | Yes |
| Install, activate, deactivate, or uninstall production plugins | Denied by default | Yes |
| Push to `main` | Denied by default | Yes/repository policy |
| Publish to production | Denied by default | Yes/protected environment |
| Delete data | Denied by default | Yes, and audit-prefix check required |

## MCP interface expectations

The Credoq MCP server should expose read-only discovery separately from write tools. Write tools must include a dry-run mode, a human-readable change summary, an idempotency key, and an approval token or explicit approval step. Codebase changes should be performed through a GitHub pull request rather than arbitrary direct file writes from the live WordPress server. Deployment should accept only a reviewed artifact or reviewed commit SHA. The MCP transport must be validated against the current client protocol before this contract is considered operational.
