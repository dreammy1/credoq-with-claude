# Credoq AI Automation — Implementation Status

## Current state

The automation foundation is implemented on branch `automation/ai-audit-release` and is under pull request [#5](https://github.com/dreammy1/credoq-with-claude/pull/5).

The branch includes a versioned five-plugin audit contract, deterministic ZIP packaging with SHA-256 checksums, a safe MCP/live-site preflight, a protected GitHub Actions release workflow, an AI operating contract, and a fail-closed WordPress deployment adapter. The event seat-map regression found during CI was fixed in the widget and Seats runtime, and the local Playwright suite now passes **3/3 scenarios**. Pull request CodeQL checks are green.

## Target operating model

An AI receives a feature request, creates a branch, changes the repository, runs PHP and browser tests, packages the five plugins, runs the labeled live audit against `https://credoq.freedev.app`, publishes evidence, and opens a pull request. The pull request should be reviewed before a release environment is approved. The live deployment job must deploy only the checksummed artifact created by CI, take a backup, run a post-deployment smoke test, and retain a rollback artifact.

The requested dashboard experience should be implemented as a thin WordPress control panel over that remote runner. The button or voice command should dispatch a named GitHub workflow or MCP job; it should not execute Playwright inside a normal PHP request. The panel should display run ID, stage, evidence links, and approval state. Voice must be treated as an alternative way to press “Start audit,” not as an authority to publish code.

## Required one-time secrets and configuration

The final connection needs a scoped GitHub token or GitHub App installation with permission to dispatch workflows and read workflow artifacts, plus a Credoq MCP deployment tool that accepts a checksummed release artifact, creates a backup, and returns a deployment ID and rollback ID. These values must be stored as server-side WordPress secrets or connector secrets, never in JavaScript, committed files, or an AI prompt.

The current adapter intentionally refuses to publish until those exact MCP deployment operations are available. This is deliberate: the live target is approved for testing, but an unknown deployment endpoint cannot safely be guessed or used for irreversible code changes.

## Recommended setup order

First, merge pull request #5 after reviewing the workflow and the seat-map fix. Next, add the scoped GitHub/MCP secrets and configure the protected release environment with a human reviewer. Then install the dashboard runner module, run a dry-run audit, review the evidence bundle, and only afterward enable the production deployment action. The first live deployment should be a known-good package with a tested rollback.

## Known limitations

The complete 20-field public form submission, email delivery verification, and WooCommerce order-status verification still require a stable authenticated live browser session and a completed unpaid checkout. The current audit evidence records the successful booking-to-checkout transition and the pending-payment booking, but it does not claim that payment, email, or credit deduction passed.

The current repository branch does not yet contain the dashboard runner UI or a voice command implementation. Those should be added only after the MCP deployment contract is finalized, because the UI needs stable job and artifact endpoints. The safest long-term design remains: WordPress dashboard -> authenticated job dispatcher -> GitHub Actions runner -> evidence artifact -> explicit release approval -> MCP deployment endpoint -> backup/rollback verification.
