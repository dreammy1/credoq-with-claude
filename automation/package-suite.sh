#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-${ROOT}/artifacts}"
mkdir -p "$OUT"
rm -f "$OUT"/credoq-*.zip "$OUT"/SHA256SUMS

plugins=(
  credoq-engine-v3
  credoq-appointments
  credoq-events-v3
  credoq-seats
  credoq-membership-v3
)

for plugin in "${plugins[@]}"; do
  src="$ROOT/plugins/$plugin"
  php_count=$(find "$src" -maxdepth 1 -type f -name '*.php' | wc -l)
  test "$php_count" -gt 0
  archive="$OUT/${plugin}.zip"
  (cd "$ROOT/plugins" && zip -qr "$archive" "$plugin" -x "$plugin/node_modules/*" "$plugin/.git/*")
  unzip -tq "$archive"
  sha256sum "$archive" >> "$OUT/SHA256SUMS"
done

# A combined package is convenient for an operator while preserving per-plugin ZIPs.
(cd "$ROOT/plugins" && zip -qr "$OUT/credoq-suite.zip" "${plugins[@]}" -x '*/node_modules/*' '*/.git/*')
unzip -tq "$OUT/credoq-suite.zip"
sha256sum "$OUT/credoq-suite.zip" >> "$OUT/SHA256SUMS"

echo "Created packages in $OUT"
cat "$OUT/SHA256SUMS"
