#!/usr/bin/env bash
# Runs every cross-plugin harness test. Exits non-zero if any test fails,
# so this can be wired directly into a CI pipeline (GitHub Actions, etc).
#
# Usage:
#   CREDOQ_PLUGINS_DIR=/path/to/plugins ./run-all.sh
# or drop plugin folders into ./plugins/ next to this script and just run:
#   ./run-all.sh
set -uo pipefail
cd "$(dirname "$0")/tests"

total=0
failed=0
for f in test*.php; do
    total=$((total+1))
    echo "=== $f ==="
    if ! php "$f"; then
        failed=$((failed+1))
        echo ">>> FAILED: $f"
    fi
    echo
done

echo "============================================"
echo "$((total-failed))/$total test files passed"
[ "$failed" -eq 0 ] && exit 0 || exit 1
