# CredoQ AI Development and Release Automation

This directory defines the safe automation boundary for AI-assisted CredoQ plugin development. An AI client may inspect the repository, propose or implement a change on a branch, run deterministic PHP and Playwright checks, package the five-plugin suite, execute a labeled live audit, and produce an evidence report. Live installation, activation changes, database mutations, code changes, deletion, and production publication remain approval-gated.

## Operating model

The repository is the source of truth. A requested feature is implemented on a short-lived branch or pull request. Every pull request runs PHP linting, the cross-plugin harness, the real-WordPress admin-settings track, the widget E2E track, plugin packaging, and artifact upload. A separate manual workflow runs against the live site only when the operator supplies the live-site URL and explicitly selects the test mode.

The live audit must create only records carrying the `AUDIT TEST` prefix and must use a non-delivery email address unless a delivery mailbox has been explicitly approved. Payment tests must use a non-capturing method such as Direct bank transfer or a sandbox gateway. The workflow must never mark a real order paid, never delete non-audit records, and never publish automatically merely because tests pass.

## Required GitHub configuration

Configure the following repository secrets only after the MCP transport and deployment adapter have been validated:

| Secret | Purpose |
|---|---|
| `CREDOQ_TEST_URL` | URL of a staging or dedicated audit WordPress site |
| `CREDOQ_TEST_USER` | Dedicated WordPress audit administrator |
| `CREDOQ_TEST_APP_PASSWORD` | WordPress application password for the audit user |
| `CREDOQ_MCP_URL` | Current Credoq MCP endpoint |
| `CREDOQ_MCP_BEARER_TOKEN` | Rotatable read/write token with scoped permissions |
| `CREDOQ_DEPLOY_SSH_KEY` | Optional deployment key for a staging host or approved release host |
| `CREDOQ_DEPLOY_HOST` | Optional SSH deployment host |
| `CREDOQ_DEPLOY_USER` | Optional SSH deployment user |

Do not put production credentials in pull requests, issue bodies, workflow inputs, or repository files. The live production URL may be public, but its credentials and deployment key must remain GitHub Actions secrets or an equivalent secret manager.

## Approval gates

A pull request approval is required before merging code. A separate protected `production` environment is required before any live deployment. The production job must display the commit SHA, test report, plugin ZIP checksums, changed files, and audit summary before approval. Rollback must be a first-class action that restores the previously published package, not an ad hoc manual file copy.

## Existing test assets

The repository already contains a cross-plugin PHP harness, Track A real-WordPress admin-settings tests, and Track B Playwright tests against the real widget bundle with a mocked backend. The automation work extends those assets with packaging, a live-site smoke/audit contract, evidence collection, and guarded release orchestration. It does not treat a mocked backend as proof that the live WooCommerce, email, membership, or MCP integrations work.

## Two deployment choices

| Approach | Tradeoffs | Cost | Setup complexity |
|---|---|---:|---:|
| GitHub Actions plus protected production environment and SSH/WP-CLI adapter | Strong audit trail and repeatable CI; requires a staging site and deployment credentials; browser tests still need a stable test account | GitHub usage plus hosting costs already incurred | Medium |
| AI client directly through Credoq MCP with read-only discovery and approval-gated write tools | Fast interactive development and broad AI control; depends on fixing MCP transport and implementing safe code/deployment tools; weaker isolation if used directly on production | MCP hosting and existing AI-client costs | High |
| Lightweight manual release: CI package plus operator-installed ZIP | Lowest setup and safest initial rollout; no automatic live publish and more human effort | Lowest | Low |

The recommended first milestone is the protected GitHub Actions route against a dedicated staging WordPress site. Direct production publication should be enabled only after the complete live audit contract and rollback path pass.
