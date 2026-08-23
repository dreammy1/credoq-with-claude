#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${CREDOQ_ENV_FILE:-$ROOT_DIR/.env.staging.local}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE. Copy .env.staging.example to .env.staging.local and fill it locally." >&2
  exit 2
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

: "${CREDOQ_TEST_URL:?Set CREDOQ_TEST_URL in the local env file}"
: "${CREDOQ_TEST_USER:?Set CREDOQ_TEST_USER in the local env file}"
: "${CREDOQ_TEST_APP_PASSWORD:?Set CREDOQ_TEST_APP_PASSWORD in the local env file}"
: "${CREDOQ_TEST_LOGIN_PASSWORD:?Set CREDOQ_TEST_LOGIN_PASSWORD in the local env file}"

export CI="${CI:-false}"
export CREDOQ_REQUIRE_ADMIN_BROWSER="true"
export CREDOQ_AUDIT_PREFIX="${CREDOQ_AUDIT_PREFIX:-AUDIT TEST LOCAL}"
export CREDOQ_ENABLE_CLEANUP="false"

cd "$ROOT_DIR"
npm install --no-audit --no-fund
npx playwright install chromium
npx playwright test --config=playwright.config.js "$@"
