#!/usr/bin/env bash
set -Eeuo pipefail

: "${CREDOQ_TEST_URL:?Set CREDOQ_TEST_URL to the staging/audit WordPress URL}"
: "${CREDOQ_MCP_URL:?Set CREDOQ_MCP_URL to the current Credoq MCP endpoint}"
: "${CREDOQ_MCP_BEARER_TOKEN:?Set CREDOQ_MCP_BEARER_TOKEN to a scoped, rotatable token}"

case "$CREDOQ_TEST_URL" in
  *localhost*|*127.0.0.1*|*staging*|*test*) ;;
  *) echo "Refusing non-test-looking URL: $CREDOQ_TEST_URL" >&2; exit 2 ;;
esac

site_code=$(curl --silent --show-error --location --output /dev/null --write-out '%{http_code}' "$CREDOQ_TEST_URL/wp-json/")
case "$site_code" in 2*) ;; *) echo "WordPress REST preflight failed with HTTP $site_code" >&2; exit 3 ;; esac

payload='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"credoq-ci-preflight","version":"1.0.0"}}}'
response=$(curl --silent --show-error --location \
  -H "Authorization: Bearer $CREDOQ_MCP_BEARER_TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  --data "$payload" "$CREDOQ_MCP_URL")

if grep -q 'credoq_mcp_unauthorized\|Invalid MCP key' <<<"$response"; then
  echo 'MCP authentication failed' >&2
  exit 4
fi
if grep -q 'legacy SSE\|Method Not Allowed\|404' <<<"$response"; then
  echo 'MCP endpoint did not accept current JSON-RPC initialization; transport compatibility must be fixed' >&2
  exit 5
fi
printf '%s\n' "$response" | grep -q 'jsonrpc' || {
  echo 'MCP preflight did not return a JSON-RPC response' >&2
  exit 6
}

echo 'Preflight passed: test-looking WordPress URL and current MCP JSON-RPC initialization responded.'
