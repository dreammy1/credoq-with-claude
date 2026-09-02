#!/usr/bin/env bash
set -Eeuo pipefail

: "${DEPLOY_APPROVED:?Set DEPLOY_APPROVED=true only after protected-environment approval}"
: "${DEPLOY_ENVIRONMENT:?Set DEPLOY_ENVIRONMENT=staging or production}"
: "${ARTIFACT:?Set ARTIFACT to the exact CI-generated suite ZIP}"
: "${EXPECTED_SHA256:?Set EXPECTED_SHA256 to the recorded CI checksum}"
: "${WP_CLI_TARGET:?Set WP_CLI_TARGET to an explicit SSH target or local wp-cli path}"

if [[ "$DEPLOY_APPROVED" != "true" ]]; then
  echo 'Deployment blocked: explicit approval is required.' >&2
  exit 20
fi
if [[ "$DEPLOY_ENVIRONMENT" == 'production' && "${PRODUCTION_APPROVED:-false}" != 'true' ]]; then
  echo 'Production deployment blocked: protected production approval is required.' >&2
  exit 21
fi
if [[ ! -f "$ARTIFACT" ]]; then
  echo "Artifact not found: $ARTIFACT" >&2
  exit 22
fi
actual_sha256=$(sha256sum "$ARTIFACT" | awk '{print $1}')
if [[ "$actual_sha256" != "$EXPECTED_SHA256" ]]; then
  echo "Artifact checksum mismatch: expected $EXPECTED_SHA256, got $actual_sha256" >&2
  exit 23
fi

# This adapter intentionally stops until the operator supplies a site-specific,
# reviewed command. Different hosts expose wp-cli through SSH, containers, or a
# managed deployment API; guessing would risk deploying to the wrong site.
if [[ -z "${WP_CLI_DEPLOY_COMMAND:-}" ]]; then
  echo 'Deployment blocked: WP_CLI_DEPLOY_COMMAND is not configured.' >&2
  echo 'Configure a reviewed command that backs up the site, installs the exact ZIP, verifies activation, and records rollback data.' >&2
  exit 24
fi

export ARTIFACT actual_sha256 DEPLOY_ENVIRONMENT
bash -c "$WP_CLI_DEPLOY_COMMAND"
echo "Deployment command completed for $DEPLOY_ENVIRONMENT with SHA-256 $actual_sha256"
